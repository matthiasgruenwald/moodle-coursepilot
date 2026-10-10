'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const { parseClover, buildReport, formatSummary, mapChangedToTests } = require('../scripts/gate/gate');

const FIX = path.join(__dirname, 'fixtures', 'gate');
const GATE = path.join(__dirname, '..', 'scripts', 'gate', 'gate.js');
const fixture = name => path.join(FIX, name);

function cli(...args) {
  const r = spawnSync('node', [GATE, ...args], { encoding: 'utf8' });
  return { status: r.status, out: r.stdout };
}

test('report: totals, per-file coverage and CRAP per method from a clover fixture', () => {
  const { lines, summary } = buildReport(parseClover(fs.readFileSync(fixture('clover-ok.xml'), 'utf8')));
  assert.equal(summary.coverage, 65);
  assert.equal(summary.methods, 3);
  assert.equal(summary.methodsCrapOver8, 2);
  assert.equal(summary.methodsCrapOver30, 1);
  assert.equal(summary.filesBelow50, 1);
  assert.deepEqual(lines, [
    'Plugin/src/local_coursepilot/classes/b.php:0: coverage-file: 40.0% (4/10) unter 90%',
    'Plugin/src/local_coursepilot/classes/a.php:30: crap-method: hard crap=462 complexity=21',
    'Plugin/src/local_coursepilot/classes/b.php:8: crap-method: mid crap=12 complexity=3',
  ]);
  assert.match(formatSummary(summary), /^summary: coverage=65% lines=13\/20 .* crap_over_8=2 crap_over_30=1$/);
});

test('report command: valid fixture prints findings and exits 0 (nothing blocks yet)', () => {
  const r = cli('report', fixture('clover-ok.xml'));
  assert.equal(r.status, 0);
  assert.match(r.out, /a\.php:30: crap-method: hard crap=462/);
});

for (const name of ['clover-empty.xml', 'clover-invalid.xml', 'clover-zero.xml', 'clover-no-crap.xml', 'clover-no-methods.xml', 'does-not-exist.xml']) {
  test(`report command: ${name} is red, never green`, () => {
    const r = cli('report', fixture(name));
    assert.equal(r.status, 1);
    assert.match(r.out, /gate-error/);
    assert.doesNotMatch(r.out, /^summary:/m);
  });
}

test('report command: missing path argument is red', () => {
  assert.equal(cli('report').status, 1);
});

test('unknown mode is red', () => {
  assert.equal(cli('bogus').status, 1);
});

test('changed class maps to tests that cover it, changed test maps to itself', () => {
  const tests = [
    { file: 'x/tests/foo_test.php', content: '#[CoversClass(foo::class)]' },
    { file: 'x/tests/other_test.php', content: '#[CoversClass(\\local_coursepilot\\other::class)]' },
    { file: 'x/tests/foobar_test.php', content: '#[CoversClass(foobar::class)]' },
  ];
  const r = mapChangedToTests([
    'Plugin/src/local_coursepilot/classes/foo.php',
    'Plugin/src/local_coursepilot/tests/bar_test.php',
    'Plugin/src/local_coursepilot/classes/lonely.php',
    'README.md',
  ], tests);
  assert.deepEqual(r.tests, ['bar_test', 'foo_test']);
  assert.deepEqual(r.unmapped, ['Plugin/src/local_coursepilot/classes/lonely.php']);
});
