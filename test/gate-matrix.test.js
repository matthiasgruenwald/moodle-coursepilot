'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { matrixProfiles } = require('../scripts/gate/gate');

const ROOT = path.resolve(__dirname, '..');
const PLUGIN = 'Plugin/src/local_coursepilot';

test('matrix profiles: the default is Moodle 5.3 on PostgreSQL, the list is selectable and strict', () => {
  assert.deepEqual(matrixProfiles({}).map(p => [p.name, p.db, p.moodleRef]), [['53-pgsql', 'pgsql', 'origin/MOODLE_503_STABLE']]);
  assert.deepEqual(matrixProfiles({ GATE_MATRIX_PROFILES: '52-mariadb, 53-pgsql' }).map(p => p.name), ['52-mariadb', '53-pgsql']);
  assert.throws(() => matrixProfiles({ GATE_MATRIX_PROFILES: 'nope' }), /Unknown or empty matrix profiles: nope/);
});

function fixture(dockerExit) {
  const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-matrix-'));
  const write = (name, data, mode) => {
    fs.mkdirSync(path.dirname(path.join(repo, name)), { recursive: true });
    fs.writeFileSync(path.join(repo, name), data, { mode });
  };
  const git = (...args) => assert.equal(spawnSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', ...args], { cwd: repo }).status, 0);
  write('scripts/gate/baseline.json', JSON.stringify({ coverage: { covered: 1, statements: 1 }, excluded: [] }));
  write(`${PLUGIN}/tests/a_test.php`, '<?php\n');
  write('meminfo', 'MemAvailable: 16000000 kB\n');
  const calls = path.join(repo, 'calls.log');
  // Fake bash (matrix-instance.sh) and docker (phpunit exec; `ps` lists nothing).
  write('bin/bash', `#!/bin/sh\nprintf "%s\\n" "bash $*" >> ${calls}\n`, 0o755);
  write('bin/docker', `#!/bin/sh\nprintf "%s\\n" "docker $*" >> ${calls}\n[ "$1" = exec ] && exit ${dockerExit}\nexit 0\n`, 0o755);
  git('init', '-q');
  git('add', '.');
  git('commit', '-qm', 'base');
  const run = () => {
    const r = spawnSync('node', [path.join(ROOT, 'scripts/gate/gate.js'), 'matrix'], {
      cwd: repo, encoding: 'utf8',
      env: { ...process.env, PATH: `${path.join(repo, 'bin')}:${process.env.PATH}`, GATE_REPO: repo, GATE_DIR: path.join(repo, 'gd'), GATE_MATRIX_BASE: 'HEAD', GATE_MEMINFO: path.join(repo, 'meminfo'), GATE_FAILURE_LOG: path.join(repo, 'f.log') },
    });
    return { status: r.status, out: `${r.stdout}${r.stderr}`, calls: fs.existsSync(calls) ? fs.readFileSync(calls, 'utf8') : '' };
  };
  return { repo, write, run, done: () => fs.rmSync(repo, { recursive: true, force: true }) };
}

test('gate matrix: no changed tests means no instance is started', () => {
  const f = fixture(0);
  try {
    const r = f.run();
    assert.equal(r.status, 0, r.out);
    assert.match(r.out, /mode=matrix no changed tests/);
    assert.doesNotMatch(r.calls, /matrix-instance/);
  } finally { f.done(); }
});

test('gate matrix: a changed test runs on the profile instance, the instance is stopped afterwards, PHPUnit decides the result', () => {
  for (const [exit, status] of [[0, 0], [1, 1]]) {
    const f = fixture(exit);
    try {
      f.write(`${PLUGIN}/tests/a_test.php`, '<?php\n// changed\n');
      const r = f.run();
      assert.equal(r.status, status, r.out);
      assert.match(r.out, new RegExp(`summary: mode=matrix profile=53-pgsql phpunit=${exit === 0 ? 'ok' : 'fail'} tests=1`));
      const lines = r.calls.split('\n');
      const up = lines.findIndex(l => /matrix-instance\.sh up 53-pgsql origin\/MOODLE_503_STABLE pgsql 8\.4/.test(l));
      const exec = lines.findIndex(l => /^docker exec .*kurspilot-gate-m-53-pgsql-webserver-1 .*--filter \/\\b\(a_test\)\\b\//.test(l));
      const stop = lines.findIndex(l => /matrix-instance\.sh stop 53-pgsql/.test(l));
      assert.ok(up >= 0 && exec > up && stop > exec, r.calls);
    } finally { f.done(); }
  }
});
