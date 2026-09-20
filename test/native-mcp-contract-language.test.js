'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.join(__dirname, '..', 'Plugin', 'src', 'local_coursepilot');
const KEYS = fs.readFileSync(path.join(ROOT, 'classes', 'contract_keys.php'), 'utf8');
const DISPATCHER = fs.readFileSync(path.join(ROOT, 'classes', 'dispatcher.php'), 'utf8');
const CORPUS = fs.readFileSync(path.join(ROOT, 'skills', 'referenz', 'mcp-tools.md'), 'utf8');

test('native MCP boundary publishes English field names and translates both directions', () => {
  for (const [german, english] of [
    ['felder_json', 'fields_json'], ['bestaetigt', 'confirmed'], ['vollstaendig', 'full'],
    ['vorheriger_ort', 'previous_location'], ['ausstand', 'pending_entry'],
    ['nur_anlegen', 'create_only'], ['ort', 'location'], ['zielversion', 'target_version'],
  ]) {
    assert.match(KEYS, new RegExp(`'${german}'\\s*=>\\s*'${english}'`));
  }
  assert.match(DISPATCHER, /contract_keys::internalize\(\$params\['arguments'\]/);
  assert.match(DISPATCHER, /contract_keys::externalize\(\$data\)/);
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
