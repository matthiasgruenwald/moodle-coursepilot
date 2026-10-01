const { test } = require('node:test');
const assert = require('node:assert/strict');

const { ASSIGN_TOOLS } = require('../lib/assign-tools');
const fs = require('node:fs');
const path = require('node:path');

function tool(name) {
  return ASSIGN_TOOLS.find(candidate => candidate.name === name);
}

test('moodle_create_assign exposes and forwards the exercise preset with explicit attempt overrides', async () => {
  const createAssign = tool('moodle_create_assign');
  assert.ok(createAssign);

  const properties = createAssign.inputSchema.properties;
  assert.deepEqual(properties.mode.enum, ['standard', 'übung']);
  assert.ok(properties.grade);
  assert.ok(properties.gradepass);
  assert.ok(properties.submissiondrafts);
  assert.ok(properties.maxattempts);
  assert.deepEqual(properties.attemptreopenmethod.enum, ['manual', 'automatic', 'untilpass']);

  const calls = [];
  await createAssign.handler({
    courseid: 20,
    sectionnum: 1,
    name: 'Übungsraum',
    mode: 'übung',
    grade: 0,
    gradepass: 0,
    submissiondrafts: 0,
    maxattempts: -1,
    attemptreopenmethod: 'manual',
  }, async (functionName, args) => {
    calls.push([functionName, args]);
    return { cmid: 1024 };
  });

  assert.deepEqual(calls, [[
    'local_coursepilot_create_assign',
    {
      courseid: 20,
      sectionnum: 1,
      name: 'Übungsraum',
      description: '',
      duedate: 0,
      maxfiles: 1,
      visible: 1,
      mode: 'übung',
      grade: 0,
      gradepass: 0,
      submissiondrafts: 0,
      maxattempts: -1,
      attemptreopenmethod: 'manual',
      allowsubmissionsfromdate: 0,
      cutoffdate: 0,
      gradingduedate: 0,
      requiresubmissionstatement: 0,
      teamsubmission: 0,
      requireallteammemberssubmit: 0,
      teamsubmissiongroupingid: 0,
      sendnotifications: 0,
      sendlatenotifications: 0,
      sendstudentnotifications: 1,
      blindmarking: 0,
      markingworkflow: 0,
      markingallocation: 0,
      gradecat: 0,
      gradingmethod: 'none',
      onlinetext_enabled: 1, onlinetext_wordlimit_enabled: 0, onlinetext_wordlimit: 0,
      submission_file_enabled: -1, submission_file_maxfiles: 1, submission_file_maxsizebytes: 0, submission_file_filetypes: '',
      feedback_comments_enabled: 1, feedback_editpdf_enabled: 0, feedback_file_enabled: 0,
      feedback_file_maxfiles: 1, feedback_file_maxsizebytes: 0, feedback_file_filetypes: '', feedback_offline_enabled: 0,
    },
  ]]);
});

test('moodle_update_assign forwards every explicit submission-flow setting', async () => {
  const updateAssign = tool('moodle_update_assign');
  const calls = [];

  await updateAssign.handler({
    cmid: 1024,
    submissiondrafts: 1,
    grade: 100,
    gradepass: 50,
    maxattempts: 2,
    attemptreopenmethod: 'automatic',
  }, async (functionName, args) => {
    calls.push([functionName, args]);
    return { cmid: 1024 };
  });

  assert.deepEqual(calls, [[
    'local_coursepilot_update_assign',
    {
      cmid: 1024,
      name: '',
      description: '',
      duedate: -1,
      visible: -1,
      grade: 100,
      gradepass: 50,
      submissiondrafts: 1,
      maxattempts: 2,
      attemptreopenmethod: 'automatic',
      allowsubmissionsfromdate: -1,
      cutoffdate: -1,
      gradingduedate: -1,
      requiresubmissionstatement: -1,
      teamsubmission: -1,
      requireallteammemberssubmit: -1,
      teamsubmissiongroupingid: -1,
      sendnotifications: -1,
      sendlatenotifications: -1,
      sendstudentnotifications: -1,
      blindmarking: -1,
      markingworkflow: -1,
      markingallocation: -1,
      gradecat: -1,
      gradingmethod: '',
      onlinetext_enabled: -1, onlinetext_wordlimit_enabled: -1, onlinetext_wordlimit: -1,
      submission_file_enabled: -1, submission_file_maxfiles: -1, submission_file_maxsizebytes: -1, submission_file_filetypes: '',
      feedback_comments_enabled: -1, feedback_editpdf_enabled: -1, feedback_file_enabled: -1,
      feedback_file_maxfiles: -1, feedback_file_maxsizebytes: -1, feedback_file_filetypes: '', feedback_offline_enabled: -1,
    },
  ]]);
});

test('assignment create and update share snapshot patch settings and Moodle module lifecycle', () => {
  const pluginRoot = path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes');
  const createSource = fs.readFileSync(path.join(pluginRoot, 'external', 'create_assign.php'), 'utf8');
  const updateSource = fs.readFileSync(path.join(pluginRoot, 'external', 'update_assign.php'), 'utf8');

  assert.match(createSource, /assign_settings::create_moduleinfo/);
  assert.match(updateSource, /assign_settings::snapshot\(\$cm, \$course\)/);
  assert.match(updateSource, /assign_settings::patch/);
  assert.match(updateSource, /update_moduleinfo\(\$cm, \$moduleinfo, \$course\)/);
  assert.doesNotMatch(updateSource, /\$DB->update_record\(\s*'assign'/);
  assert.doesNotMatch(updateSource, /\$DB->set_field\(\s*'course_modules'/);
});

test('exercise preset is ungraded, editable and accepts explicit overrides', () => {
  const source = fs.readFileSync(path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'assign_settings.php'), 'utf8');

  assert.match(source, /\$moduleinfo->grade = \$params\['mode'\] === 'übung' \? 0 : 100/);
  assert.match(source, /\$moduleinfo->submissiondrafts = \$params\['mode'\] === 'übung' \? 0 : 1/);
  assert.match(source, /\$moduleinfo->assignfeedback_comments_enabled = \$params\['feedback_comments_enabled'\]/);
  assert.match(source, /if \(\(\$params\['grade'\] \?\? -1\) !== -1\)/);
  assert.match(source, /if \(\(\$params\['submissiondrafts'\] \?\? -1\) >= 0\)/);
});

test('partial assignment updates retain the complete assignment and subplugin snapshot', () => {
  const source = fs.readFileSync(path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'assign_settings.php'), 'utf8');

  assert.match(source, /get_moduleinfo_data\(\$cm, \$course\)/);
  assert.match(source, /get_records\('assign_plugin_config', \['assignment' => \$moduleinfo->id\]\)/);
  assert.match(source, /\$config->subtype . '_' . \$config->plugin . '_' . \$config->name/);
});

test('assignment responses and fresh catalog reads expose stored base settings', () => {
  const pluginRoot = path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes');
  const helper = fs.readFileSync(path.join(pluginRoot, 'assign_settings.php'), 'utf8');
  const catalog = fs.readFileSync(path.join(pluginRoot, 'external', 'get_course_catalog.php'), 'utf8');

  for (const field of ['grade', 'gradepass', 'submissiondrafts', 'maxattempts', 'attemptreopenmethod', 'visible']) {
    assert.match(helper, new RegExp(`'${field}'`));
    assert.match(catalog, new RegExp(`'${field}'`));
  }
});

test('assignment attempt settings validate Moodle combinations before saving', () => {
  const source = fs.readFileSync(path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'assign_settings.php'), 'utf8');

  assert.match(source, /validate_attempt_settings/);
  assert.match(source, /manual', 'automatic', 'untilpass/);
  assert.match(source, /mindestens zwei Versuche oder unbegrenzte Versuche/);
  assert.match(source, /endgültige Abgabe/);
  assert.match(source, /Bewertung mit Bestehensgrenze/);
  assert.match(source, /Bestehensgrenze braucht eine Bewertung/);
  assert.doesNotMatch(source, /\(\$params\['submissiondrafts'\] \?\? -1\) >= 0\s*\|\|\s*\(\$params\['maxattempts'\]/);
});

test('assignment attempt updates refuse frozen settings when submissions or grades exist', () => {
  const source = fs.readFileSync(path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'assign_settings.php'), 'utf8');
  const updateSource = fs.readFileSync(path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'external', 'update_assign.php'), 'utf8');

  assert.match(source, /assign_submission/);
  assert.match(source, /assign_grades/);
  assert.match(source, /bereits Abgaben oder Bewertungen/);
  assert.match(updateSource, /assign_settings::validate_attempt_settings\(\$moduleinfo, \$params\)/);
});

test('assignment core form groups are public, forwarded and read back without generic overrides', async () => {
  const createAssign = tool('moodle_create_assign');
  const updateAssign = tool('moodle_update_assign');
  const properties = createAssign.inputSchema.properties;

  for (const field of [
    'allowsubmissionsfromdate', 'cutoffdate', 'gradingduedate',
    'requiresubmissionstatement', 'teamsubmission', 'requireallteammemberssubmit',
    'teamsubmissiongroupingid', 'sendnotifications', 'sendlatenotifications',
    'sendstudentnotifications', 'blindmarking', 'markingworkflow', 'markingallocation',
    'gradecat', 'gradingmethod',
  ]) {
    assert.ok(properties[field], `Create schema exposes ${field}`);
    assert.ok(updateAssign.inputSchema.properties[field], `Update schema exposes ${field}`);
  }
  assert.deepEqual(properties.gradingmethod.enum, ['none', 'rubric', 'guide']);

  const calls = [];
  await updateAssign.handler({
    cmid: 44,
    allowsubmissionsfromdate: 100,
    cutoffdate: 200,
    teamsubmission: 1,
    teamsubmissiongroupingid: 7,
    markingworkflow: 1,
  }, async (functionName, args) => { calls.push([functionName, args]); return {}; });
  assert.deepEqual(calls[0][1], {
    cmid: 44, name: '', description: '', duedate: -1, visible: -1, grade: -1, gradepass: -1,
    submissiondrafts: -1, maxattempts: -2, attemptreopenmethod: '',
    allowsubmissionsfromdate: 100, cutoffdate: 200, gradingduedate: -1,
    requiresubmissionstatement: -1, teamsubmission: 1, requireallteammemberssubmit: -1,
    teamsubmissiongroupingid: 7, sendnotifications: -1, sendlatenotifications: -1,
    sendstudentnotifications: -1, blindmarking: -1, markingworkflow: 1,
    markingallocation: -1, gradecat: -1, gradingmethod: '',
    onlinetext_enabled: -1, onlinetext_wordlimit_enabled: -1, onlinetext_wordlimit: -1,
    submission_file_enabled: -1, submission_file_maxfiles: -1, submission_file_maxsizebytes: -1, submission_file_filetypes: '',
    feedback_comments_enabled: -1, feedback_editpdf_enabled: -1, feedback_file_enabled: -1,
    feedback_file_maxfiles: -1, feedback_file_maxsizebytes: -1, feedback_file_filetypes: '', feedback_offline_enabled: -1,
  });

  const root = path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes');
  const helper = fs.readFileSync(path.join(root, 'assign_settings.php'), 'utf8');
  const catalog = fs.readFileSync(path.join(root, 'external', 'get_course_catalog.php'), 'utf8');
  for (const field of [
    'allowsubmissionsfromdate', 'cutoffdate', 'gradingduedate', 'requiresubmissionstatement',
    'teamsubmission', 'requireallteammemberssubmit', 'teamsubmissiongroupingid',
    'sendnotifications', 'sendlatenotifications', 'sendstudentnotifications', 'blindmarking',
    'markingworkflow', 'markingallocation', 'gradecat', 'gradingmethod',
  ]) {
    assert.match(helper, new RegExp(`'${field}'`), `Result includes ${field}`);
    assert.match(catalog, new RegExp(`'${field}'`), `Catalog includes ${field}`);
  }
  assert.match(helper, /Ungültige Terminfolge/);
  assert.match(helper, /Gruppenabgabe braucht eine vorhandene Kurs-Gruppierung/);
  assert.match(helper, /Bewertungskategorie gehört nicht zu diesem Kurs/);
  assert.match(helper, /grading_manager::available_methods/);
  assert.match(helper, /eingefroren/);
  assert.match(helper, /\(int\) \$params\[\$field\] !== \(int\) \$moduleinfo->\{\$field\}/);
});

test('assignment submission and feedback plugins are independently forwarded, snapshotted and catalogued', async () => {
  const createAssign = tool('moodle_create_assign');
  const updateAssign = tool('moodle_update_assign');
  const pluginRoot = path.join(__dirname, '..', 'legacy', 'local_coursepilot', 'classes');
  const helper = fs.readFileSync(path.join(pluginRoot, 'assign_settings.php'), 'utf8');
  const catalog = fs.readFileSync(path.join(pluginRoot, 'external', 'get_course_catalog.php'), 'utf8');

  for (const field of [
    'onlinetext_enabled', 'onlinetext_wordlimit_enabled', 'onlinetext_wordlimit',
    'submission_file_enabled', 'submission_file_maxfiles', 'submission_file_maxsizebytes', 'submission_file_filetypes',
    'feedback_comments_enabled', 'feedback_editpdf_enabled',
    'feedback_file_enabled', 'feedback_file_maxfiles', 'feedback_file_maxsizebytes', 'feedback_file_filetypes',
    'feedback_offline_enabled',
  ]) {
    assert.ok(createAssign.inputSchema.properties[field], `Create schema exposes ${field}`);
    assert.ok(updateAssign.inputSchema.properties[field], `Update schema exposes ${field}`);
    assert.match(helper, new RegExp(`'${field}'`), `Settings helper handles ${field}`);
    assert.match(catalog, new RegExp(`'${field}'`), `Catalog reads ${field}`);
  }

  const calls = [];
  await updateAssign.handler({
    cmid: 44,
    onlinetext_wordlimit: 250,
    feedback_file_enabled: 1,
  }, async (functionName, args) => { calls.push([functionName, args]); return {}; });
  assert.equal(calls[0][1].onlinetext_wordlimit, 250);
  assert.equal(calls[0][1].feedback_file_enabled, 1);
  assert.equal(calls[0][1].feedback_comments_enabled, -1, 'unspecified plugins use the no-change sentinel');

  assert.match(helper, /assign_plugin_config/);
  assert.match(helper, /assignsubmission_.*onlinetext/);
  assert.match(helper, /moduleinfo_field/);
  assert.match(catalog, /get_area_files\(\$filecontext->id, 'mod_assign', 'introattachment', 0/);
  assert.match(catalog, /filesize/);
  assert.match(catalog, /mimetype/);
});
