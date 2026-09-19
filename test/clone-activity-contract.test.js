'use strict';

/**
 * Vertragstest: moodle_clone_activity, Intra-Kurs- und kursuebergreifender
 * Pfad (Issue #328/#329, KP-010, docs/specs/0013-aktivitaeten-klonen.md).
 *
 * Testet die JS-Adapter-Logik in lib/core-tools.js gegen eine gemockte
 * callMoodle-Funktion (Vorbild: test/activity-settings-contract.test.js) -
 * keine echte Moodle-Instanz noetig. Der kursuebergreifende Pfad ruft intern
 * local_coursepilot_clone_activity_to_course auf (PHP-Backup/Restore-
 * Adapter, nicht live testbar in dieser Sandbox - siehe Plugin-Klasse
 * clone_activity_to_course.php fuer die Server-seitige Logik).
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { CORE_TOOLS } = require('../lib/core-tools');
const { ALLOWED_MCP_TOOLS, ALLOWED_WEBSERVICE_FUNCTIONS } = require('../lib/data-protection-allowlist');

const ROOT = path.join(__dirname, '..');
const SERVICES_PATH = path.join(ROOT, 'legacy', 'local_coursepilot', 'db', 'services.php');

function tool(name) {
  const match = CORE_TOOLS.find(candidate => candidate.name === name);
  assert.ok(match, `${name} is exposed by the Core MCP`);
  return match;
}

/**
 * Gemockte callMoodle-Implementierung: liefert je Funktionsname die naechste
 * Antwort aus einer vorbereiteten Warteschlange und protokolliert jeden
 * Aufruf (Funktionsname + Parameter) in `calls`.
 */
function makeCallMoodle(queues) {
  const calls = [];
  const cursors = {};
  return {
    calls,
    async callMoodle(functionName, params) {
      calls.push([functionName, params]);
      const queue = queues[functionName];
      if (!queue) {
        throw new Error(`makeCallMoodle: keine Mock-Antwort fuer ${functionName} konfiguriert.`);
      }
      const index = cursors[functionName] ?? 0;
      cursors[functionName] = index + 1;
      const entry = queue[index] ?? queue[queue.length - 1];
      if (entry instanceof Error) throw entry;
      return entry;
    },
  };
}

function sourceCm(overrides = {}) {
  return {
    id: 501,
    course: 7,
    modname: 'assign',
    name: 'Werkstattauftrag',
    sectionnum: 2,
    section: 900,
    visible: 1,
    completion: 0,
    completionpassgrade: 0,
    completionview: 0,
    completionexpected: 0,
    availability: null,
    ...overrides,
  };
}

test('AC: intra-course clone of an assign duplicates via cm_duplicate, resolves the new cmid via a module-list diff, strips the "(Kopie)" default title, and forces visible=1', async () => {
  const { callMoodle, calls } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm(), warnings: [] }],
    local_coursepilot_get_modules: [
      [{ cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 1 }],
      [
        { cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 1 },
        { cmid: 777, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag (Kopie)', visible: 1 },
      ],
    ],
    core_courseformat_update_course: [{}, {}],
    local_coursepilot_update_assign: [{}],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501 }, callMoodle);

  assert.deepEqual(result, {
    cmid: 777,
    modname: 'assign',
    title: 'Werkstattauftrag',
    courseid: 7,
    sectionnum: 2,
    visible: 1,
    notes: [],
  });

  assert.deepEqual(calls[0], ['core_course_get_course_module', { cmid: 501 }]);
  assert.deepEqual(calls[1], ['local_coursepilot_get_modules', { courseid: 7, sectionnum: 2 }]);
  assert.deepEqual(calls[2], ['core_courseformat_update_course', { action: 'cm_duplicate', courseid: 7, 'ids[0]': 501 }]);
  assert.deepEqual(calls[3], ['local_coursepilot_get_modules', { courseid: 7, sectionnum: 2 }]);
  assert.deepEqual(calls[4], ['local_coursepilot_update_assign', { cmid: 777, name: 'Werkstattauftrag' }]);
  assert.deepEqual(calls[5], ['core_courseformat_update_course', { action: 'cm_show', courseid: 7, 'ids[0]': 777 }]);
});

test('AC: hidden source activity still produces a visible clone by default', async () => {
  const { callMoodle, calls } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm({ visible: 0 }), warnings: [] }],
    local_coursepilot_get_modules: [
      [{ cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 0 }],
      [
        { cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 0 },
        { cmid: 778, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag (Kopie)', visible: 0 },
      ],
    ],
    core_courseformat_update_course: [{}, {}],
    local_coursepilot_update_assign: [{}],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501 }, callMoodle);

  assert.equal(result.visible, 1);
  assert.deepEqual(calls.at(-1), ['core_courseformat_update_course', { action: 'cm_show', courseid: 7, 'ids[0]': 778 }]);
});

test('AC: explicit title overrides the source title, quiz uses moodle_update_quiz_settings for the rename', async () => {
  const { callMoodle, calls } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm({ modname: 'quiz', name: 'Lernstandscheck LS 4' }), warnings: [] }],
    local_coursepilot_get_modules: [
      [{ cmid: 501, sectionnum: 2, modname: 'quiz', name: 'Lernstandscheck LS 4', visible: 1 }],
      [
        { cmid: 501, sectionnum: 2, modname: 'quiz', name: 'Lernstandscheck LS 4', visible: 1 },
        { cmid: 900, sectionnum: 2, modname: 'quiz', name: 'Lernstandscheck LS 4 (Kopie)', visible: 1 },
      ],
    ],
    core_courseformat_update_course: [{}, {}],
    local_coursepilot_update_quiz_settings: [{}],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501, title: 'Lernstandscheck LS 5' }, callMoodle);

  assert.equal(result.title, 'Lernstandscheck LS 5');
  assert.deepEqual(calls.find(([fn]) => fn === 'local_coursepilot_update_quiz_settings'), [
    'local_coursepilot_update_quiz_settings',
    { cmid: 900, name: 'Lernstandscheck LS 5' },
  ]);
});

test('AC: activity types without a dedicated update tool are renamed generically and get a manual-follow-up note', async () => {
  const { callMoodle, calls } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm({ modname: 'forum', name: 'Klassenforum' }), warnings: [] }],
    local_coursepilot_get_modules: [
      [{ cmid: 501, sectionnum: 2, modname: 'forum', name: 'Klassenforum', visible: 1 }],
      [
        { cmid: 501, sectionnum: 2, modname: 'forum', name: 'Klassenforum', visible: 1 },
        { cmid: 950, sectionnum: 2, modname: 'forum', name: 'Klassenforum (Kopie)', visible: 1 },
      ],
    ],
    core_courseformat_update_course: [{}, {}],
    core_update_inplace_editable: [{}],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501 }, callMoodle);

  assert.deepEqual(calls.find(([fn]) => fn === 'core_update_inplace_editable'), [
    'core_update_inplace_editable',
    { component: 'core_course', itemtype: 'activityname', itemid: 950, value: 'Klassenforum' },
  ]);
  assert.ok(result.notes.some(note => note.includes("kein eigenes MCP-Update-Tool")));
});

test('AC: inherited completion and availability produce a hint in the response', async () => {
  const { callMoodle } = makeCallMoodle({
    core_course_get_course_module: [{
      cm: sourceCm({
        completion: 2,
        completionpassgrade: 1,
        completionview: 0,
        completionexpected: 1780000000,
        availability: '{"op":"&","c":[{"type":"completion","cm":123,"e":1}]}',
      }),
      warnings: [],
    }],
    local_coursepilot_get_modules: [
      [{ cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 1 }],
      [
        { cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 1 },
        { cmid: 999, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag (Kopie)', visible: 1 },
      ],
    ],
    core_courseformat_update_course: [{}, {}],
    local_coursepilot_update_assign: [{}],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501 }, callMoodle);

  assert.equal(result.notes.length, 2);
  assert.match(result.notes[0], /Abschlussverfolgung wurde von der Quelle uebernommen/);
  assert.match(result.notes[1], /Voraussetzungen \(availability\) wurden von der Quelle uebernommen/);
});

test('AC (#329): cross-course clone delegates to the local backup/restore adapter and reuses the intra-course rename/visibility postprocessing', async () => {
  const { callMoodle, calls } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm(), warnings: [] }],
    local_coursepilot_clone_activity_to_course: [{ cmid: 4242, courseid: 8, sectionnum: 0 }],
    local_coursepilot_update_assign: [{}],
    core_courseformat_update_course: [{}],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501, courseid: 8 }, callMoodle);

  assert.deepEqual(result, {
    cmid: 4242,
    modname: 'assign',
    title: 'Werkstattauftrag',
    courseid: 8,
    sectionnum: 0,
    visible: 1,
    notes: [],
  });

  assert.deepEqual(calls[0], ['core_course_get_course_module', { cmid: 501 }]);
  assert.deepEqual(calls[1], [
    'local_coursepilot_clone_activity_to_course',
    { cmid: 501, targetcourseid: 8, targetsectionnum: -1 },
  ]);
  assert.deepEqual(calls[2], ['local_coursepilot_update_assign', { cmid: 4242, name: 'Werkstattauftrag' }]);
  assert.deepEqual(calls[3], ['core_courseformat_update_course', { action: 'cm_show', courseid: 8, 'ids[0]': 4242 }]);
});

test('AC (#329): cross-course clone forwards an explicit target section and title', async () => {
  const { callMoodle, calls } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm({ modname: 'quiz', name: 'Lernstandscheck LS 4' }), warnings: [] }],
    local_coursepilot_clone_activity_to_course: [{ cmid: 5001, courseid: 8, sectionnum: 3 }],
    local_coursepilot_update_quiz_settings: [{}],
    core_courseformat_update_course: [{}],
  });

  const result = await tool('moodle_clone_activity').handler(
    { cmid: 501, courseid: 8, sectionnum: 3, title: 'Lernstandscheck LS 5', visible: 0 },
    callMoodle
  );

  assert.equal(result.title, 'Lernstandscheck LS 5');
  assert.equal(result.sectionnum, 3);
  assert.equal(result.visible, 0);
  assert.deepEqual(calls[1], [
    'local_coursepilot_clone_activity_to_course',
    { cmid: 501, targetcourseid: 8, targetsectionnum: 3 },
  ]);
  assert.deepEqual(calls.at(-1), ['core_courseformat_update_course', { action: 'cm_hide', courseid: 8, 'ids[0]': 5001 }]);
});

test('AC (#329): a missing capability in the target course fails loudly instead of silently (propagated from the local adapter)', async () => {
  const { callMoodle } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm(), warnings: [] }],
    local_coursepilot_clone_activity_to_course: [
      new Error('Sorry, but you do not currently have permissions to do that (moodle/course:manageactivities).'),
    ],
  });

  await assert.rejects(
    () => tool('moodle_clone_activity').handler({ cmid: 501, courseid: 8 }, callMoodle),
    /moodle\/course:manageactivities/
  );
});

test('AC (#329): completion hint applies to the cross-course path; availability cannot be resolved cross-course and is cleared instead (#332)', async () => {
  const { callMoodle } = makeCallMoodle({
    core_course_get_course_module: [{
      cm: sourceCm({ completion: 1, availability: '{"op":"&","c":[{"type":"completion","cm":123,"e":1}]}' }),
      warnings: [],
    }],
    local_coursepilot_clone_activity_to_course: [{ cmid: 6001, courseid: 8, sectionnum: 0 }],
    local_coursepilot_update_assign: [{}],
    core_courseformat_update_course: [{}],
    local_coursepilot_set_restriction: [{ cmid: 6001, message: 'Voraussetzungen fuer cmid 6001 entfernt.' }],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501, courseid: 8 }, callMoodle);

  assert.equal(result.notes.length, 2);
  assert.match(result.notes[0], /Abschlussverfolgung wurde von der Quelle uebernommen/);
  assert.match(result.notes[1], /konnten kursuebergreifend nicht sinnvoll uebertragen werden.*wurden entfernt/);
});

test('AC: an unresolvable new cmid (ambiguous module-list diff) fails loudly instead of guessing', async () => {
  const { callMoodle } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm(), warnings: [] }],
    local_coursepilot_get_modules: [
      [{ cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 1 }],
      [{ cmid: 501, sectionnum: 2, modname: 'assign', name: 'Werkstattauftrag', visible: 1 }],
    ],
    core_courseformat_update_course: [{}],
  });

  await assert.rejects(
    () => tool('moodle_clone_activity').handler({ cmid: 501 }, callMoodle),
    /neue cmid nach dem Duplizieren nicht eindeutig bestimmbar/
  );
});

test('a different target section resolves the target section id before duplicating', async () => {
  const { callMoodle, calls } = makeCallMoodle({
    core_course_get_course_module: [{ cm: sourceCm(), warnings: [] }],
    local_coursepilot_get_sections: [[
      { id: 900, sectionnum: 2 },
      { id: 905, sectionnum: 3 },
    ]],
    local_coursepilot_get_modules: [
      [{ cmid: 600, sectionnum: 3, modname: 'page', name: 'Vorlage', visible: 1 }],
      [
        { cmid: 600, sectionnum: 3, modname: 'page', name: 'Vorlage', visible: 1 },
        { cmid: 601, sectionnum: 3, modname: 'assign', name: 'Werkstattauftrag (Kopie)', visible: 1 },
      ],
    ],
    core_courseformat_update_course: [{}, {}],
    local_coursepilot_update_assign: [{}],
  });

  const result = await tool('moodle_clone_activity').handler({ cmid: 501, sectionnum: 3 }, callMoodle);

  assert.equal(result.sectionnum, 3);
  assert.deepEqual(calls.find(([fn]) => fn === 'core_courseformat_update_course' && fn), [
    'core_courseformat_update_course',
    { action: 'cm_duplicate', courseid: 7, 'ids[0]': 501, targetsectionid: 905 },
  ]);
});

test('Coursepilot registers the two additional Core webservices plus the local cross-course adapter for moodle_clone_activity, hidden behind a single MCP tool', () => {
  const services = fs.readFileSync(SERVICES_PATH, 'utf8');

  assert.ok(ALLOWED_WEBSERVICE_FUNCTIONS.includes('core_course_get_course_module'));
  assert.ok(ALLOWED_WEBSERVICE_FUNCTIONS.includes('core_update_inplace_editable'));
  assert.ok(ALLOWED_WEBSERVICE_FUNCTIONS.includes('local_coursepilot_clone_activity_to_course'));
  assert.ok(ALLOWED_MCP_TOOLS.includes('moodle_clone_activity'));
  // Kein zweites MCP-Tool fuer den kursuebergreifenden Pfad - beide Pfade
  // verstecken sich hinter demselben moodle_clone_activity-Seam (#329).
  assert.ok(!ALLOWED_MCP_TOOLS.includes('moodle_clone_activity_to_course'));
  assert.match(services, /'core_course_get_course_module'/);
  assert.match(services, /'core_update_inplace_editable'/);
  assert.match(services, /'local_coursepilot_clone_activity_to_course'/);
});
