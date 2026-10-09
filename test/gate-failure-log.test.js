'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const failureLog = require('../scripts/gate/failure-log');
const s = require('../scripts/gate/static');

const FIXTURE = path.join(__dirname, 'fixtures', 'gate', 'failures.log');
const GATE = path.join(__dirname, '..', 'scripts', 'gate', 'gate.js');
const tmpLog = () => path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'gate-log-')), 'f.log');

test('ranking: files and checks ordered by failure count, ok rows ignored, abort counts', () => {
  const out = failureLog.report(undefined, FIXTURE);
  const [files, checks] = out.split('\n\n');
  assert.match(files, /1\. 3x .*a\.php\n2\. 2x .*b\.php/);
  assert.match(checks, /1\. 3x phpstan\n2\. 2x moodle-cs-error\n3\. 1x mustache/);
  assert.doesNotMatch(out, /covers/);
  assert.match(out, /failures=6/);
});

test('ranking command: prints ranking from GATE_FAILURE_LOG, fails when log is missing', () => {
  const ok = spawnSync('node', [GATE, 'ranking'], { encoding: 'utf8', env: { ...process.env, GATE_FAILURE_LOG: FIXTURE } });
  assert.equal(ok.status, 0);
  assert.match(ok.stdout, /Dateien:/);
  assert.match(ok.stdout, /Pruefungen:/);
  const missing = spawnSync('node', [GATE, 'ranking'], { encoding: 'utf8', env: { ...process.env, GATE_FAILURE_LOG: '/nonexistent/x.log' } });
  assert.equal(missing.status, 1);
});

test('runStatic: appends one row per finding or ok, and an abort row when a check throws', () => {
  const file = tmpLog();
  process.env.GATE_FAILURE_LOG = file;
  try {
    const exec = () => ({ code: 0, out: 'ok', stdout: '[]' });
    s.runStatic(['savepoints'], { container: 'x', repo: '/nonexistent', exec });
    const throwing = () => { throw new Error('boom'); };
    assert.throws(() => s.runStatic(['savepoints', 'moodle-cs'], { container: 'x', repo: '/nonexistent', exec: throwing }), /boom/);
  } finally {
    delete process.env.GATE_FAILURE_LOG;
  }
  const rows = failureLog.parse(fs.readFileSync(file, 'utf8'));
  assert.deepEqual(rows.map(r => `${r.check}:${r.result}`), ['savepoints:ok', 'savepoints:abort']);
});
