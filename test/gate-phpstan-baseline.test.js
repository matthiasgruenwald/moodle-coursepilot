'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const DIR = path.join(__dirname, '..', 'scripts', 'gate', 'phpstan');

test('phpstan baseline: every identifier kept in the baseline has a reason, and no reason is stale', () => {
  const baseline = fs.readFileSync(path.join(DIR, 'phpstan-baseline.neon'), 'utf8');
  const reasons = JSON.parse(fs.readFileSync(path.join(DIR, 'baseline-reasons.json'), 'utf8'));
  const identifiers = [...new Set([...baseline.matchAll(/^\s+identifier: (\S+)$/gm)].map(m => m[1]))].sort();
  assert.deepEqual(Object.keys(reasons).sort(), identifiers);
  for (const [id, reason] of Object.entries(reasons)) {
    assert.ok(typeof reason === 'string' && reason.trim().length > 20, `reason for ${id}`);
  }
});
