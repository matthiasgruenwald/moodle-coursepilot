'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { CORE_TOOLS } = require('../lib/core-tools');
const { ALLOWED_WEBSERVICE_FUNCTIONS } = require('../lib/data-protection-allowlist');

const ROOT = path.join(__dirname, '..');
const SERVICES_PATH = path.join(ROOT, 'legacy', 'local_coursepilot', 'db', 'services.php');
const CATALOG_PATH = path.join(ROOT, 'legacy', 'local_coursepilot', 'classes', 'external', 'get_course_catalog.php');

function tool(name) {
  const match = CORE_TOOLS.find(candidate => candidate.name === name);
  assert.ok(match, `${name} is exposed by the Core MCP`);
  return match;
}

test('activity visibility and group mode use Moodle Core course-format updates', async () => {
  const calls = [];

  await tool('moodle_update_activity_settings').handler({
    courseid: 42,
    cmid: 99,
    visible: 0,
    groupmode: 2,
  }, async (functionName, params) => calls.push([functionName, params]));

  assert.deepEqual(calls, [
    ['core_courseformat_update_course', { action: 'cm_hide', courseid: 42, 'ids[0]': 99 }],
    ['core_courseformat_update_course', { action: 'cm_visiblegroups', courseid: 42, 'ids[0]': 99 }],
  ]);
});

test('Coursepilot automatically registers the Core API without a duplicate local adapter or deprecated endpoint', () => {
  const services = fs.readFileSync(SERVICES_PATH, 'utf8');

  assert.ok(ALLOWED_WEBSERVICE_FUNCTIONS.includes('core_courseformat_update_course'));
  assert.match(services, /'core_courseformat_update_course'/);
  assert.doesNotMatch(services, /local_coursepilot_update_activity_settings/);
  assert.doesNotMatch(services, /core_course_edit_module/);
});

test('a fresh course catalog reports saved visibility and group mode', () => {
  const catalog = fs.readFileSync(CATALOG_PATH, 'utf8');

  assert.match(catalog, /cm\.visible, cm\.visibleoncoursepage, cm\.groupmode/);
  assert.match(catalog, /'groupmode'\s*=>\s*\(int\) \$row->groupmode/);
  assert.match(catalog, /'groupmode'\s*=>\s*new external_value\(PARAM_INT/);
});
