'use strict';

const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', 'Plugin', 'src', 'local_coursepilot');

// ADR 0026 allows both selected system cohorts and existing system roles.
// Bind both public location routes to that grant policy, as OAuth already is.
for (const route of ['location_selection.php', 'location_selection_browse.php']) {
  test(`${route} uses the common role-or-cohort remote access policy`, () => {
    const source = fs.readFileSync(path.join(ROOT, route), 'utf8');
    assert.match(source, /\\local_coursepilot\\remote_access::require_granted\(\);/);
    assert.doesNotMatch(source, /require_capability\('local\/coursepilot:useremote'/);
    assert.match(source, /require_login\(null, false\);/);
    if (route.endsWith('_browse.php')) {
      assert.match(source, /require_sesskey\(\);/);
    }
  });
}
