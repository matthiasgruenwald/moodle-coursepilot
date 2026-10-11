'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

test('CI wiring: full on the minimum version is mandatory with target history and diagnostics', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/native-ci.yml'), 'utf8');
  const quality = workflow.split('  quality:\n')[1].split('\n  phpunit:')[0];
  assert.match(quality, /GATE_MOODLE_REF: origin\/MOODLE_501_STABLE/);
  assert.match(quality, /fetch-depth: 0/);
  assert.match(quality, /format\('origin\/\{0\}', github\.base_ref\)/);
  assert.match(quality, /github\.event\.before/);
  assert.match(quality, /npm run gate -- full/);
  assert.match(quality, /if: always\(\)[\s\S]*actions\/upload-artifact@v4/);
  assert.match(quality, /name: gate-trend-MOODLE_501_STABLE-/);
  assert.match(quality, /if-no-files-found: error/);
  assert.match(workflow, /needs: \[quality, phpunit, js-native, artifact\]/);
  assert.match(workflow, /NEEDS_JSON: \$\{\{ toJSON\(needs\) \}\}/);
  assert.match(workflow, /run: node scripts\/ci\/required-check\.js/);
  assert.doesNotMatch(workflow, /check-coverage|threshold=80|continue-on-error/);
  assert.equal(fs.existsSync(path.join(__dirname, '../scripts/ci/check-coverage.js')), false);
});
