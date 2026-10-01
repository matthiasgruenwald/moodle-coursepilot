#!/usr/bin/env node
/**
 * Node-Testlauf für CI (Issue #268, Akzeptanzkriterium 3/12).
 *
 * Führt dieselbe Suite wie `npm test` aus, schließt aber Testdateien aus, die
 * strukturell nicht auf einem Linux-CI-Runner laufen können - unabhängig vom
 * Zustand des nativen Server-MCP (Plugin/src/local_coursepilot). Kein
 * natives Testversagen wird hier verdeckt, jede Zeile ist einzeln begründet:
 *
 * - moodle-credentials.test.js, moodle-test-client-credentials.test.js,
 *   start-mcp.test.js, uninstall-kurspilot.test.js: pruefen den
 *   plattformgebundenen OS-Credential-Store (macOS Keychain / Windows
 *   Credential Manager) des eingefrorenen Altstands - siehe
 *   scripts/moodle-credentials.js:assertSupportedPlatform.
 * - assign-settings-freeze.test.js: ruft legacy/local_coursepilot/classes/
 *   assign_settings.php ueber die lokale `php`-CLI auf, die in diesem
 *   Job nicht bereitsteht (nativer PHPUnit-Job bringt PHP separat mit) -
 *   testet ausserdem den Altstand, nicht Plugin/src/local_coursepilot.
 * - assign-tools-crop-warning.test.js: die macOS-Faelle (#139) mocken
 *   os.platform(), waehrend lib/kurspilot-workspace-config.js den echten
 *   process.platform liest - auf einem Linux-Runner damit strukturell nicht
 *   simulierbar. Testet zudem lib/ (lokaler stdio-Altstand), nicht den
 *   nativen Server-MCP.
 *
 * Jede neue Datei mit vergleichbarer Einschraenkung muss hier ausdruecklich
 * eingetragen werden, damit sie nicht stillschweigend aus dem Pflichtlauf
 * verschwindet.
 */

'use strict';

const path = require('node:path');
const fs = require('node:fs');
const { spawnSync } = require('node:child_process');

const REPO_ROOT = path.join(__dirname, '..', '..');
const TEST_DIR = path.join(REPO_ROOT, 'test');

// Begruendung je Datei: siehe Dateikopf-Kommentar oben.
const EXCLUDED_FROM_NATIVE_GATE = new Set([
  'moodle-credentials.test.js',
  'moodle-test-client-credentials.test.js',
  'start-mcp.test.js',
  'uninstall-kurspilot.test.js',
  'assign-settings-freeze.test.js',
  'assign-tools-crop-warning.test.js',
]);

function listTestFiles(dir) {
  const out = [];
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      out.push(...listTestFiles(full));
    } else if (entry.isFile() && entry.name.endsWith('.test.js')) {
      if (EXCLUDED_FROM_NATIVE_GATE.has(entry.name)) {
        continue;
      }
      out.push(full);
    }
  }
  return out;
}

const files = listTestFiles(TEST_DIR).sort();
if (files.length === 0) {
  process.stderr.write('run-js-tests.js: keine Testdateien gefunden.\n');
  process.exit(1);
}

process.stderr.write(
  `run-js-tests.js: ${files.length} Testdateien, ${EXCLUDED_FROM_NATIVE_GATE.size} plattformgebunden ausgeschlossen.\n`
);

const result = spawnSync(
  process.execPath,
  ['--require', path.join(TEST_DIR, 'helpers', 'no-browser.js'), '--test', ...files],
  { stdio: 'inherit', cwd: REPO_ROOT }
);

process.exit(result.status === null ? 1 : result.status);
