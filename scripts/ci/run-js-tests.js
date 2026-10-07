#!/usr/bin/env node
/**
 * Node-Testlauf für CI (Issue #268, Akzeptanzkriterium 3/12).
 *
 * Führt dieselbe Suite wie `npm test` aus. Seit dem Entfernen des Altstands
 * 1.x (#587) gibt es keine plattformgebundenen Ausnahmen mehr. Kommt eine
 * Testdatei hinzu, die strukturell nicht auf einem Linux-CI-Runner laufen
 * kann, wird sie hier ausdrücklich und begründet ausgeschlossen, damit sie
 * nicht stillschweigend aus dem Pflichtlauf verschwindet.
 */

'use strict';

const path = require('node:path');
const fs = require('node:fs');
const { spawnSync } = require('node:child_process');

const REPO_ROOT = path.join(__dirname, '..', '..');
const TEST_DIR = path.join(REPO_ROOT, 'test');

// Begruendung je Datei: siehe Dateikopf-Kommentar oben.
const EXCLUDED_FROM_NATIVE_GATE = new Set([]);

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
  ['--test', ...files],
  { stdio: 'inherit', cwd: REPO_ROOT }
);

process.exit(result.status === null ? 1 : result.status);
