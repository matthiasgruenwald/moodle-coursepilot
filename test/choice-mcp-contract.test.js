'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { CHOICE_TOOLS } = require('../lib/choice-tools');

const PLUGIN_ROOT = path.join(__dirname, '..', 'legacy', 'local_coursepilot');
const UPDATE_CHOICE_PATH = path.join(PLUGIN_ROOT, 'classes', 'external', 'update_choice.php');

// #325: timeopen/timeclose fehlten in update_choice, nach dem Klonen einer
// Abstimmung war eine Korrektur nur ueber die Moodle-UI moeglich.
test('moodle_update_choice exposes timeopen/timeclose with -1 sentinel and forwards them', async () => {
  const updateChoice = CHOICE_TOOLS.find(tool => tool.name === 'moodle_update_choice');
  assert.ok(updateChoice, 'moodle_update_choice should be exposed');

  assert.equal(updateChoice.inputSchema.properties.timeopen.default, -1);
  assert.equal(updateChoice.inputSchema.properties.timeclose.default, -1);

  const calls = [];
  const callMoodle = async (name, args) => {
    calls.push({ name, args });
    return {};
  };

  await updateChoice.handler({ cmid: 5 }, callMoodle);
  assert.equal(calls[0].args.timeopen, -1, 'timeopen-Sentinel ist -1, wenn nicht angegeben');
  assert.equal(calls[0].args.timeclose, -1, 'timeclose-Sentinel ist -1, wenn nicht angegeben');

  await updateChoice.handler({ cmid: 5, timeopen: 1700000000, timeclose: 1700100000 }, callMoodle);
  assert.equal(calls[1].args.timeopen, 1700000000);
  assert.equal(calls[1].args.timeclose, 1700100000);

  await updateChoice.handler({ cmid: 5, timeopen: 0 }, callMoodle);
  assert.equal(calls[2].args.timeopen, 0, '0 ist ein gueltiger expliziter Wert (kein Limit), kein Sentinel');
});

test('update_choice.php patches timeopen/timeclose with -1 sentinel (0 = no limit)', () => {
  const source = fs.readFileSync(UPDATE_CHOICE_PATH, 'utf8');

  assert.match(source, /'timeopen'\s*=>\s*new external_value\(PARAM_INT,[^;]*VALUE_DEFAULT,\s*-1\)/);
  assert.match(source, /'timeclose'\s*=>\s*new external_value\(PARAM_INT,[^;]*VALUE_DEFAULT,\s*-1\)/);
  assert.match(source, /\$params\['timeopen'\]\s*>=\s*0/);
  assert.match(source, /\$params\['timeclose'\]\s*>=\s*0/);
  assert.match(source, /'timeopen'\s*=>\s*\(int\)\s*\$stored->timeopen/);
  assert.match(source, /'timeclose'\s*=>\s*\(int\)\s*\$stored->timeclose/);
});
