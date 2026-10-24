'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { mutationBase, recordGreen } = require('../scripts/gate/mutation-base');

function fixture() {
  const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-mutation-base-'));
  const git = (...args) => {
    const r = spawnSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@example.org', ...args], { cwd: repo, encoding: 'utf8' });
    assert.equal(r.status, 0, r.stderr);
    return r.stdout.trim();
  };
  const write = (name, data) => {
    fs.mkdirSync(path.dirname(path.join(repo, name)), { recursive: true });
    fs.writeFileSync(path.join(repo, name), data);
  };
  const commit = (name, data) => { write(name, data); git('add', '.'); git('commit', '-qm', name); return git('rev-parse', 'HEAD'); };
  write('scripts/gate/mutation-ignore.json', '[]');
  write('scripts/gate/mutation.js', '// rules');
  git('init', '-q');
  const full = commit('a.php', '1');
  const green = commit('a.php', '2');
  commit('a.php', '3');
  const stateFile = path.join(repo, 'state', 'mutation-green.json');
  const args = (env = {}) => ({ repo, stateFile, fullBase: full, env });
  const state = (sha, extra = {}) => {
    fs.mkdirSync(path.dirname(stateFile), { recursive: true });
    fs.writeFileSync(stateFile, JSON.stringify({ sha, base: full, digest: require('node:crypto').createHash('sha256').update('scripts/gate/mutation-ignore.json\0[]scripts/gate/mutation.js\0// rules').digest('hex'), ...extra }));
  };
  return { repo, git, write, commit, full, green, stateFile, args, state, done: () => fs.rmSync(repo, { recursive: true, force: true }) };
}

test('mutation base: without state the full base applies', () => {
  const f = fixture();
  try {
    assert.deepEqual(mutationBase(f.args()), { base: f.full, incremental: false });
  } finally { f.done(); }
});

test('mutation base: a valid green ancestor becomes the base', () => {
  const f = fixture();
  try {
    f.state(f.green);
    assert.deepEqual(mutationBase(f.args()), { base: f.green, incremental: true });
  } finally { f.done(); }
});

test('mutation base: changed evaluation rules, other full base, foreign or corrupt state fall back to the full base', () => {
  const f = fixture();
  try {
    f.state(f.green);
    f.write('scripts/gate/mutation-ignore.json', '[ ]');
    assert.equal(mutationBase(f.args()).incremental, false, 'mutation-ignore.json changed');
    f.write('scripts/gate/mutation-ignore.json', '[]');
    assert.equal(mutationBase(f.args()).incremental, true);
    f.write('scripts/gate/mutation.js', '// other');
    assert.equal(mutationBase(f.args()).incremental, false, 'mutation.js changed');
    f.write('scripts/gate/mutation.js', '// rules');
    f.state(f.green, { base: f.green });
    assert.equal(mutationBase(f.args()).incremental, false, 'verified for another full base');
    f.git('checkout', '-q', '-b', 'other', f.full);
    f.commit('b.php', 'x');
    f.state(f.green);
    assert.equal(mutationBase(f.args()).incremental, false, 'green commit is not an ancestor of HEAD');
    f.state('not-a-commit');
    assert.equal(mutationBase(f.args()).incremental, false, 'unknown commit');
    fs.writeFileSync(f.stateFile, '{');
    assert.equal(mutationBase(f.args()).incremental, false, 'corrupt state');
  } finally { f.done(); }
});

test('mutation base: CI and GATE_MUTATION_FULL always use the full base', () => {
  const f = fixture();
  try {
    f.state(f.green);
    for (const env of [{ CI: 'true' }, { GITHUB_ACTIONS: 'true' }, { GATE_MUTATION_FULL: '1' }]) {
      assert.equal(mutationBase(f.args(env)).incremental, false, JSON.stringify(env));
    }
  } finally { f.done(); }
});

test('mutation base: green is recorded atomically for a clean local tree only', () => {
  const f = fixture();
  try {
    f.write('dirty.php', 'x');
    assert.equal(recordGreen(f.args()), false, 'untracked file');
    assert.equal(fs.existsSync(f.stateFile), false);
    fs.rmSync(path.join(f.repo, 'dirty.php'));
    assert.equal(recordGreen(f.args({ CI: 'true' })), false, 'CI never records');
    assert.equal(fs.existsSync(f.stateFile), false);
    assert.equal(recordGreen(f.args()), true);
    assert.deepEqual(fs.readdirSync(path.dirname(f.stateFile)), ['mutation-green.json'], 'no temp file left');
    const head = f.git('rev-parse', 'HEAD');
    assert.equal(JSON.parse(fs.readFileSync(f.stateFile, 'utf8')).sha, head);
    f.commit('a.php', '4');
    assert.deepEqual(mutationBase(f.args()), { base: head, incremental: true });
  } finally { f.done(); }
});
