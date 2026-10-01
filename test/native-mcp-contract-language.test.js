'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', 'Plugin', 'src', 'local_coursepilot');
const CONTRACT_KEYS_PATH = path.join(ROOT, 'classes', 'contract_keys.php');
const DISPATCHER = fs.readFileSync(path.join(ROOT, 'classes', 'dispatcher.php'), 'utf8');
const SCHEMA_CONVERTER = fs.readFileSync(path.join(ROOT, 'classes', 'external_schema_converter.php'), 'utf8');
const EXTERNAL_DIR = path.join(ROOT, 'classes', 'external');
const EXTERNAL_SOURCE = fs.readdirSync(EXTERNAL_DIR)
  .filter((name) => name.endsWith('.php'))
  .map((name) => fs.readFileSync(path.join(EXTERNAL_DIR, name), 'utf8'))
  .join('\n');
const CORPUS = fs.readFileSync(path.join(ROOT, 'skills', 'referenz', 'mcp-tools.md'), 'utf8');

// #573 (Contract-Schritt): die Uebersetzungsschicht aus #568 ist entfernt,
// nicht nur deaktiviert - kein Aufrufer darf sie noch voraussetzen.
test('the translation layer from #568 is gone, not just unused', () => {
  assert.equal(fs.existsSync(CONTRACT_KEYS_PATH), false, 'contract_keys.php muss entfernt sein');
  assert.doesNotMatch(DISPATCHER, /contract_keys::/);
  assert.doesNotMatch(SCHEMA_CONVERTER, /contract_keys::/);
});

test('every tool declares its English field names directly, not via a converter', () => {
  for (const field of [
    'fields_json', 'confirmed', 'full', 'previous_location', 'pending_entry',
    'create_only', 'location', 'from_version', 'to_version', 'target_version',
    'date', 'message',
  ]) {
    assert.match(EXTERNAL_SOURCE, new RegExp(`'${field}'`), `${field} sollte unmittelbar deklariert sein`);
  }
});

test('native MCP skill corpus uses the published English field names', () => {
  for (const field of [
    'fields_json', 'confirmed', 'full', 'previous_location', 'pending_entry',
    'create_only', 'location', 'from_version', 'to_version', 'target_version',
  ]) {
    assert.match(CORPUS, new RegExp(`\\b${field}\\b`));
  }
  assert.doesNotMatch(CORPUS, /`(?:felder_json|bestaetigt|vollständig|vorheriger_ort|ausstand|nur_anlegen|ort|von_version|nach_version|zielversion)\b/);
});
