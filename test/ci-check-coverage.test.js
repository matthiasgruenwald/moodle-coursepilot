'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const { checkCoverage } = require('../scripts/ci/check-coverage');

function cloverWith(statements, covered) {
  return `<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1">
  <project timestamp="1">
    <metrics files="1" loc="10" ncloc="10" classes="1" methods="1" coveredmethods="1"
      statements="${statements}" coveredstatements="${covered}" elements="${statements}" coveredelements="${covered}"/>
    <file name="x.php">
      <metrics statements="${statements}" coveredstatements="${covered}"/>
    </file>
  </project>
</coverage>`;
}

function writeTmp(content) {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'coverage-gate-'));
  const file = path.join(dir, 'clover.xml');
  fs.writeFileSync(file, content);
  return file;
}

test('gate case: below threshold is red', () => {
  const file = writeTmp(cloverWith(100, 79));
  const result = checkCoverage(file, 80);
  assert.equal(result.ok, false);
});

test('gate case: exactly the threshold is green', () => {
  const file = writeTmp(cloverWith(100, 80));
  const result = checkCoverage(file, 80);
  assert.equal(result.ok, true);
});

test('gate case: above the threshold is green', () => {
  const file = writeTmp(cloverWith(100, 95));
  const result = checkCoverage(file, 80);
  assert.equal(result.ok, true);
});

test('gate case: missing report is red', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'coverage-gate-missing-'));
  const result = checkCoverage(path.join(dir, 'does-not-exist.xml'), 80);
  assert.equal(result.ok, false);
  assert.match(result.message, /fehlt/);
});

test('gate case: empty report is red', () => {
  const file = writeTmp('');
  const result = checkCoverage(file, 80);
  assert.equal(result.ok, false);
  assert.match(result.message, /leer/);
});

test('gate case: invalid/unparseable report is red', () => {
  const file = writeTmp('not xml at all');
  const result = checkCoverage(file, 80);
  assert.equal(result.ok, false);
  assert.match(result.message, /ungueltig/);
});

test('gate case: zero-statement denominator is red, not a vacuous pass', () => {
  const file = writeTmp(cloverWith(0, 0));
  const result = checkCoverage(file, 80);
  assert.equal(result.ok, false);
  assert.match(result.message, /Nenner/);
});

test('gate case: no path argument is red', () => {
  const result = checkCoverage(undefined, 80);
  assert.equal(result.ok, false);
});
