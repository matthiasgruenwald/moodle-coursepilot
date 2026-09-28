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
    <file name="x.php">
      <metrics statements="${statements}" coveredstatements="${covered}"/>
    </file>
    <metrics files="1" loc="10" ncloc="10" classes="1" methods="1" coveredmethods="1"
      statements="${statements}" coveredstatements="${covered}" elements="${statements}" coveredelements="${covered}"/>
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

// Echte phpunit-Clover-Struktur (CI-Lauf 36475282645): Datei- und
// Paketmetriken zuerst, die Projektsumme als letztes Kind von <project>.
test('gate case: project totals are read from the end of <project>, not the first file', () => {
  const file = writeTmp(`<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1">
  <project timestamp="1" name="Clover Coverage">
    <file name="lang.php">
      <metrics loc="68" ncloc="39" classes="0" methods="0" coveredmethods="0" conditionals="0" coveredconditionals="0" statements="23" coveredstatements="0" elements="23" coveredelements="0"/>
    </file>
    <package name="local_coursepilot">
      <file name="x.php">
        <metrics loc="10" ncloc="10" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="77" coveredstatements="77" elements="78" coveredelements="78"/>
      </file>
    </package>
    <metrics files="2" loc="78" ncloc="49" classes="1" methods="1" coveredmethods="1" conditionals="0" coveredconditionals="0" statements="100" coveredstatements="77" elements="101" coveredelements="77"/>
  </project>
</coverage>`);
  const result = checkCoverage(file, 80);
  assert.equal(result.ok, false);
  assert.match(result.message, /77\/100/);
});
