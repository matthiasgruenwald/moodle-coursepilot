'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { isExcluded } = require('../scripts/gate/ratchet');

const plugin = path.join(__dirname, '..', 'Plugin', 'src', 'local_coursepilot');
const entries = [
  "admin/connections.php",
  "connections.php",
  "history.php",
  "location_selection.php",
  "location_selection_browse.php",
  "mcp.php",
  "oauth.php",
  "oauth/authorize.php",
  "oauth/jwks.php",
  "oauth/protected-resource.php",
  "oauth/register.php",
  "oauth/token.php",
  "ortswahl.php",
  "ortswahl_browse.php",
  "settings.php",
  "surface.php",
  "werkbank/download.php",
  "workbench/download.php"
];
const exclusions = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'scripts', 'gate', 'baseline.json'), 'utf8')).excluded;

test('entry scripts only delegate: no domain branches, functions or direct database access', () => {
  for (const file of entries) {
    const source = fs.readFileSync(path.join(plugin, file), 'utf8');
    // Mask comments and literals together so URLs and quoted keywords stay inert.
    const code = source.replace(/\/\*[\s\S]*?\*\/|\/\/[^\n]*|'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/g, '');
    assert.doesNotMatch(code, /\b(?:if|else|foreach|for|while|do|switch|match|try|catch|function)\b/, file);
    assert.doesNotMatch(code, /\$DB\b|(?<![?<])\?(?![?>?])/, file);
  }
});

test('coverage excludes exactly the proven delegating entries and retains upgrade logic', () => {
  for (const file of entries) {
    assert.ok(isExcluded(file, exclusions), `${file} remains in the denominator`);
    assert.ok(exclusions.find(entry => entry.path === file)?.reason, `${file} needs its own reason`);
  }
  assert.equal(isExcluded('db/upgrade.php', exclusions), false);
  assert.equal(isExcluded('db/upgradelib.php', exclusions), false);
  assert.equal(isExcluded('lib.php', exclusions), false);
  assert.equal(isExcluded('classes/output/history_page.php', exclusions), false);
  const declarations = ['lang/', 'db/access.php', 'db/events.php', 'db/services.php', 'db/tasks.php', 'version.php'];
  assert.deepEqual(exclusions.map(entry => entry.path).sort(), [...declarations, ...entries].sort());
});
