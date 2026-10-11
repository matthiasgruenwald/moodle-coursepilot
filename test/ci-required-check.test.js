'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const required = ['quality', 'phpunit', 'js-native', 'artifact'];
const green = Object.fromEntries(required.map(job => [job, { result: 'success' }]));
const check = needs => spawnSync(process.execPath, [path.join(__dirname, '../scripts/ci/required-check.js')], {
  encoding: 'utf8', env: { ...process.env, NEEDS_JSON: JSON.stringify(needs) },
});

test('required check: only success for every mandatory job is green', () => {
  assert.equal(check(green).status, 0);
  for (const job of required) {
    for (const result of ['failure', 'skipped', 'cancelled', undefined]) {
      const response = check({ ...green, [job]: { result } });
      assert.equal(response.status, 1);
      assert.match(response.stderr, new RegExp(`Pflichtjobs nicht erfolgreich: ${job}`));
    }
    const missing = { ...green };
    delete missing[job];
    assert.equal(check(missing).status, 1);
  }
  assert.equal(check(null).status, 1);
});
