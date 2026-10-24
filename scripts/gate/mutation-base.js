/**
 * Diff base of the local mutation run: the last locally green commit instead of the target branch,
 * so a push re-checks only what changed since. CI (and GATE_MUTATION_FULL=1) always uses the full base.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');

// Evaluation rules: when one of these changes, earlier green results prove nothing.
const BOUND_FILES = ['scripts/gate/mutation-ignore.json', 'scripts/gate/mutation.js'];

const forcedFull = env => Boolean(env.CI || env.GITHUB_ACTIONS || env.GATE_MUTATION_FULL === '1');

const git = (repo, args) => spawnSync('git', args, { cwd: repo, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
const isAncestor = (repo, a, b) => git(repo, ['merge-base', '--is-ancestor', a, b]).status === 0;

function rulesDigest(repo) {
  const hash = crypto.createHash('sha256');
  for (const rel of BOUND_FILES) {
    hash.update(`${rel}\0`);
    hash.update(fs.readFileSync(path.join(repo, rel)));
  }
  return hash.digest('hex');
}

function readState(file) {
  try {
    const state = JSON.parse(fs.readFileSync(file, 'utf8'));
    return [state.sha, state.base, state.digest].every(v => typeof v === 'string') ? state : null;
  } catch (e) {
    return null;
  }
}

/** The commit to diff against: the last green one when it provably continues the full base, else the full base. */
function mutationBase({ repo, stateFile, fullBase, env = process.env }) {
  if (forcedFull(env)) return { base: fullBase, incremental: false };
  const state = readState(stateFile);
  let valid = false;
  try {
    valid = state !== null && state.base === fullBase && state.digest === rulesDigest(repo)
      && isAncestor(repo, state.sha, 'HEAD') && isAncestor(repo, fullBase, state.sha);
  } catch (e) {
    valid = false;
  }
  return valid ? { base: state.sha, incremental: true } : { base: fullBase, incremental: false };
}

/** Remember HEAD as green; only for a clean tree outside CI, written atomically after the green result. */
function recordGreen({ repo, stateFile, fullBase, env = process.env }) {
  if (env.CI || env.GITHUB_ACTIONS) return false;
  const dirty = git(repo, ['status', '--porcelain']);
  const head = git(repo, ['rev-parse', 'HEAD']);
  if (dirty.status !== 0 || dirty.stdout.trim() !== '' || head.status !== 0) return false;
  fs.mkdirSync(path.dirname(stateFile), { recursive: true });
  const tmp = `${stateFile}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify({ sha: head.stdout.trim(), base: fullBase, digest: rulesDigest(repo) }));
  fs.renameSync(tmp, stateFile);
  return true;
}

module.exports = { mutationBase, recordGreen, forcedFull };
