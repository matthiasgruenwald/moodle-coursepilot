const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

const CREATE_ASSIGN_PATH = path.join(
  __dirname,
  '..',
  'legacy',
  'local_coursepilot',
  'classes',
  'external',
  'create_assign.php'
);
const ASSIGN_SETTINGS_PATH = path.join(
  __dirname,
  '..',
  'legacy',
  'local_coursepilot',
  'classes',
  'assign_settings.php'
);

test('create_assign provides cmidnumber through its shared settings helper before add_moduleinfo for Moodle 5.0', () => {
  const source = fs.readFileSync(CREATE_ASSIGN_PATH, 'utf8');
  const settings = fs.readFileSync(ASSIGN_SETTINGS_PATH, 'utf8');
  const cmidnumberIndex = settings.indexOf('$moduleinfo->cmidnumber');
  const createModuleInfoIndex = source.indexOf('assign_settings::create_moduleinfo($params)');
  const addModuleInfoIndex = source.indexOf('add_moduleinfo($moduleinfo, $course)');

  assert.notStrictEqual(cmidnumberIndex, -1);
  assert.notStrictEqual(createModuleInfoIndex, -1);
  assert.notStrictEqual(addModuleInfoIndex, -1);
  assert.ok(createModuleInfoIndex < addModuleInfoIndex);
});
