'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const PLUGIN_ROOT = path.join(__dirname, '..', 'legacy', 'local_coursepilot');
const CREATE_QUIZ_PATH = path.join(PLUGIN_ROOT, 'classes', 'external', 'create_quiz.php');
const UPDATE_QUIZ_PATH = path.join(PLUGIN_ROOT, 'classes', 'external', 'update_quiz_settings.php');
const QUIZ_SETTINGS_PATH = path.join(PLUGIN_ROOT, 'classes', 'quiz_settings.php');
const SERVICES_PATH = path.join(PLUGIN_ROOT, 'db', 'services.php');

test('create_quiz treats new Kurspilot quiz modes as native and old names as deprecated aliases', () => {
  const source = fs.readFileSync(CREATE_QUIZ_PATH, 'utf8');

  assert.match(source, /NATIVE_MODES\s*=\s*\['mini-check', 'lernstandscheck', 'abschlusstest'\]/);
  assert.match(source, /'intensiv'\s*=>\s*'mini-check'/);
  assert.match(source, /'lerncheck'\s*=>\s*'lernstandscheck'/);
  assert.match(source, /'bewertung'\s*=>\s*'abschlusstest'/);
  assert.match(source, /PARAM_ALPHANUMEXT/);
  assert.match(source, /'mode'\s*=>\s*new external_value\(PARAM_TEXT/);
});

test('update_quiz_settings is registered and checks activity-management capability', () => {
  const source = fs.readFileSync(UPDATE_QUIZ_PATH, 'utf8');
  const services = fs.readFileSync(SERVICES_PATH, 'utf8');

  assert.match(services, /local_coursepilot_update_quiz_settings/);
  assert.match(services, /'classname'\s*=>\s*'local_coursepilot\\external\\update_quiz_settings'/);
  assert.match(services, /'capabilities'\s*=>\s*'moodle\/course:manageactivities'/);
  assert.match(source, /get_coursemodule_from_id\('quiz'/);
  assert.match(source, /context_module::instance/);
  assert.match(source, /require_capability\('moodle\/course:manageactivities'/);
  assert.match(source, /completionpassgrade/);
  assert.match(source, /gradepass/);
  assert.match(source, /reviewrightanswer/);
  assert.match(source, /reviewmaxmarks/);
});

test('lernstandscheck defaults include pass-grade completion, review flags and three feedback bands', () => {
  const source = fs.readFileSync(CREATE_QUIZ_PATH, 'utf8');

  assert.match(source, /'preferredbehaviour'\s*=>\s*'deferredcbm'/);
  assert.match(source, /'questionsperpage'\s*=>\s*0/);
  assert.match(source, /'attempts'\s*=>\s*0/);
  assert.match(source, /'grademethod'\s*=>\s*QUIZ_GRADEHIGHEST/);
  assert.match(source, /'gradepass'\s*=>\s*80\.0/);
  assert.match(source, /'decimalpoints'\s*=>\s*2/);
  assert.match(source, /'completion'\s*=>\s*2/);
  assert.match(source, /'completionusegrade'\s*=>\s*1/);
  assert.match(source, /'completionpassgrade'\s*=>\s*1/);
  assert.match(source, /review_form_flags/);
  assert.match(source, /'rightanswerduring'/);
  assert.match(source, /'rightanswerimmediately'/);
  assert.match(source, /'rightansweropen'/);
  assert.match(source, /'rightanswerclosed'/);
  assert.match(source, /'maxmarksimmediately'/);
  assert.match(source, /'maxmarksopen'/);
  assert.match(source, /'maxmarksclosed'/);
  assert.match(source, /'overallfeedbackimmediately'/);
  assert.match(source, /'overallfeedbackopen'/);
  assert.match(source, /'overallfeedbackclosed'/);
  assert.match(source, /'high'\s*=>/);
  assert.match(source, /'middle'\s*=>/);
  assert.match(source, /'low'\s*=>/);
  assert.match(source, /feedback_boundaries/);
});

test('mini-check uses immediate feedback without confidence-based marking', () => {
  const source = fs.readFileSync(CREATE_QUIZ_PATH, 'utf8');

  assert.match(source, /case 'mini-check':[\s\S]*?'preferredbehaviour'\s*=>\s*'immediatefeedback'/);
});

test('update_quiz_settings returns saved quiz, completion, review and feedback values', () => {
  const source = fs.readFileSync(UPDATE_QUIZ_PATH, 'utf8');
  const settingsSource = fs.readFileSync(QUIZ_SETTINGS_PATH, 'utf8');
  const createSource = fs.readFileSync(CREATE_QUIZ_PATH, 'utf8');

  // Snapshot+Patch-Logik (#322) lebt im quiz_settings-Modul, update_quiz_settings
  // ruft es nur noch duenn auf.
  assert.match(source, /quiz_settings::snapshot/);
  assert.match(source, /quiz_settings::patch/);
  assert.match(source, /quiz_settings::persist/);
  assert.match(source, /quiz_settings::result/);
  assert.match(settingsSource, /completionusegrade/);
  assert.match(settingsSource, /completionpassgrade/);
  assert.match(settingsSource, /reviewrightanswer/);
  assert.match(settingsSource, /reviewmaxmarks/);
  assert.match(settingsSource, /reviewmarks/);
  assert.match(settingsSource, /reviewoverallfeedback/);
  assert.match(settingsSource, /feedbackboundaries/);
  assert.match(createSource, /mingrade/);
  assert.match(settingsSource, /feedbackrecords/);
  assert.match(source, /create_quiz::saved_settings_return_structure/);
  assert.match(createSource, /new external_multiple_structure/);
});

// #86: Volle Quiz-Formularfelder statt nur mode+gradepass+timelimit.
// Modus-Presets bleiben Default; einzelne Felder ueberschreiben gezielt
// (Layered Defaults via Sentinel-Werte: '' bzw. -1 = "nicht gesetzt").
const OVERRIDABLE_STRING_FIELDS = ['preferredbehaviour', 'navmethod'];
const OVERRIDABLE_INT_FIELDS = [
  'questionsperpage',
  'attempts',
  'attemptonlast',
  'grademethod',
  'delay1',
  'delay2',
  'shuffleanswers',
  'decimalpoints',
  'completion',
  'completionusegrade',
  'completionpassgrade',
  'reviewattempt',
  'reviewcorrectness',
  'reviewmaxmarks',
  'reviewmarks',
  'reviewspecificfeedback',
  'reviewgeneralfeedback',
  'reviewrightanswer',
  'reviewoverallfeedback',
];

test('create_quiz and update_quiz_settings expose full quiz form fields as overridable parameters', () => {
  const createSource = fs.readFileSync(CREATE_QUIZ_PATH, 'utf8');
  const updateSource = fs.readFileSync(UPDATE_QUIZ_PATH, 'utf8');

  // create_quiz definiert die Override-Parameter zentral in
  // overridable_field_params() (geteilt mit update_quiz_settings statt
  // dupliziert); pruefe die Feldlisten und Sentinel-Defaults dort.
  assert.match(createSource, /function overridable_field_params/);
  assert.match(createSource, /OVERRIDABLE_STRING_FIELDS\s*=\s*\['preferredbehaviour', 'navmethod'\]/);
  assert.match(createSource, /new external_value\(PARAM_ALPHANUMEXT,[^;]*VALUE_DEFAULT,\s*''\)/);
  assert.match(createSource, /new external_value\(PARAM_INT,[^;]*VALUE_DEFAULT,\s*-1\)/);

  for (const field of OVERRIDABLE_STRING_FIELDS) {
    assert.match(createSource, new RegExp(`'${field}'`), `create_quiz: '${field}' muss referenziert sein`);
  }
  for (const field of OVERRIDABLE_INT_FIELDS) {
    assert.match(createSource, new RegExp(`'${field}'`), `create_quiz: '${field}' muss referenziert sein`);
  }

  // update_quiz_settings nutzt dieselbe Parameterdefinition; die Merge-Logik
  // (apply_field_overrides) lebt im quiz_settings-Modul (#322, Snapshot+Patch).
  assert.match(updateSource, /create_quiz::overridable_field_params\(\)/);
  const settingsSource = fs.readFileSync(QUIZ_SETTINGS_PATH, 'utf8');
  assert.match(settingsSource, /create_quiz::apply_field_overrides\(/);
  for (const field of [...OVERRIDABLE_STRING_FIELDS, ...OVERRIDABLE_INT_FIELDS]) {
    assert.match(updateSource, new RegExp(`\\$${field}\\b`), `update_quiz_settings: '${field}' muss als execute()-Parameter durchgereicht werden`);
  }

  // overallfeedback bleibt modusabhaengig strukturiert (high/middle/low vs.
  // pass/fail) - Override ueber explizite pass/fail Texte.
  assert.match(createSource, /'overallfeedbacktextpass'/);
  assert.match(createSource, /'overallfeedbacktextfail'/);
  assert.match(updateSource, /overallfeedbacktextpass/);
  assert.match(updateSource, /overallfeedbacktextfail/);
});

// #322 (KP-009/KP-012): quiz_settings kapselt Snapshot+Patch, update_quiz_settings
// ruft nur noch duenn auf. mode wird nur angewendet, wenn explizit uebergeben.
test('quiz_settings module encapsulates snapshot+patch; update_quiz_settings only wires it', () => {
  const settingsSource = fs.readFileSync(QUIZ_SETTINGS_PATH, 'utf8');
  const updateSource = fs.readFileSync(UPDATE_QUIZ_PATH, 'utf8');

  assert.match(settingsSource, /namespace local_coursepilot;/);
  assert.match(settingsSource, /class quiz_settings/);
  assert.match(settingsSource, /public static function snapshot\(/);
  assert.match(settingsSource, /public static function patch\(/);
  assert.match(settingsSource, /public static function persist\(/);
  assert.match(settingsSource, /public static function result\(/);

  // mode hat keinen automatischen Sentinel-Default mehr: nur angewendet,
  // wenn params['mode'] explizit != '' ist.
  assert.match(settingsSource, /\$modegiven\s*=\s*\(\$params\['mode'\] \?\? ''\) !== ''/);
  assert.match(settingsSource, /if \(\$modegiven\)/);

  // update_quiz_settings ist duenn: keine eigene Patch-/Persist-Logik mehr.
  assert.match(updateSource, /quiz_settings::snapshot\(\$cm\)/);
  assert.match(updateSource, /quiz_settings::patch\(\$snapshot, \$params\)/);
  assert.match(updateSource, /quiz_settings::persist\(\$cm, \$patched\)/);
  assert.match(updateSource, /quiz_settings::result\(\$cm\)/);
  assert.doesNotMatch(updateSource, /\$DB->update_record\(\s*'quiz'/);
  assert.doesNotMatch(updateSource, /\$DB->set_field\(\s*'course_modules'/);

  // 'mode' hat keinen automatischen Default mehr (leer = kein Moduswechsel).
  assert.match(updateSource, /'mode'\s*=>\s*new external_value\(PARAM_ALPHANUMEXT,[^;]*VALUE_DEFAULT,\s*''\)/);
});

// #322: optionales Feld 'name' aendert nur den sichtbaren Titel.
test('update_quiz_settings exposes an optional name field that only patches the title', () => {
  const updateSource = fs.readFileSync(UPDATE_QUIZ_PATH, 'utf8');
  const settingsSource = fs.readFileSync(QUIZ_SETTINGS_PATH, 'utf8');

  assert.match(updateSource, /'name'\s*=>\s*new external_value\(PARAM_TEXT,[^;]*VALUE_DEFAULT,\s*''\)/);
  assert.match(settingsSource, /\(\$params\['name'\] \?\? ''\) !== ''/);
  assert.match(settingsSource, /\$result\['name'\] = \$params\['name'\]/);
});

// #322: Rueckgabewert enthaelt die effektiven Settings nach dem Update
// (konsistent mit update_assign) - inklusive name/visible.
test('update_quiz_settings return payload includes effective name and visibility', () => {
  const createSource = fs.readFileSync(CREATE_QUIZ_PATH, 'utf8');
  const settingsSource = fs.readFileSync(QUIZ_SETTINGS_PATH, 'utf8');

  assert.match(createSource, /'name'\s*=>\s*new external_value\(PARAM_TEXT, 'Saved quiz title'\)/);
  assert.match(createSource, /'visible'\s*=>\s*new external_value\(PARAM_INT, 'Saved visibility'\)/);
  assert.match(settingsSource, /'name'\s*=>\s*\(string\) \$quiz->name/);
  assert.match(settingsSource, /'visible'\s*=>\s*\(int\) \$cmrecord->visible/);
});

test('create_quiz merges explicit field overrides on top of mode defaults (layered defaults helper)', () => {
  const createSource = fs.readFileSync(CREATE_QUIZ_PATH, 'utf8');

  // Zentrale Merge-Funktion statt Wiederholung der Override-Logik pro Feld.
  assert.match(createSource, /function apply_field_overrides/);
});

// #325: timeopen/timeclose waren bei create_quiz fest auf 0 gesetzt und bisher
// ueber update_quiz_settings nicht aenderbar (KP-010-Folgeticket "Klonen"
// braucht eine Korrektur des Zeitfensters ohne Moodle-UI-Wechsel).
test('update_quiz_settings patches timeopen/timeclose with -1 sentinel (0 = no limit)', () => {
  const updateSource = fs.readFileSync(UPDATE_QUIZ_PATH, 'utf8');
  const settingsSource = fs.readFileSync(QUIZ_SETTINGS_PATH, 'utf8');

  assert.match(updateSource, /'timeopen'\s*=>\s*new external_value\(PARAM_INT,[^;]*VALUE_DEFAULT,\s*-1\)/);
  assert.match(updateSource, /'timeclose'\s*=>\s*new external_value\(PARAM_INT,[^;]*VALUE_DEFAULT,\s*-1\)/);
  assert.match(updateSource, /\$timeopen\b/);
  assert.match(updateSource, /\$timeclose\b/);

  assert.match(settingsSource, /\(\$params\['timeopen'\] \?\? -1\) >= 0/);
  assert.match(settingsSource, /\(\$params\['timeclose'\] \?\? -1\) >= 0/);
  assert.match(settingsSource, /\$quiz->timeopen = \$patched\['timeopen'\]/);
  assert.match(settingsSource, /\$quiz->timeclose = \$patched\['timeclose'\]/);
  assert.match(settingsSource, /'timeopen'\s*=>\s*\(int\) \$quiz->timeopen/);
  assert.match(settingsSource, /'timeclose'\s*=>\s*\(int\) \$quiz->timeclose/);
});
