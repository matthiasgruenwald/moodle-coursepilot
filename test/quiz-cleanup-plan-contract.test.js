'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { QUIZ_TOOLS } = require('../lib/quiz-tools');

const root = path.join(__dirname, '..');
const external = path.join(root, 'legacy', 'local_coursepilot', 'classes', 'external', 'get_quiz_cleanup_plan.php');
const services = path.join(root, 'legacy', 'local_coursepilot', 'db', 'services.php');

test('quiz cleanup plan blocks deletion and identifies every obsolete slot for manual removal', async () => {
  const tool = QUIZ_TOOLS.find(({ name }) => name === 'moodle_plan_quiz_cleanup');
  assert.ok(tool, 'read-only cleanup planning tool is exposed');
  assert.deepEqual(tool.inputSchema.required, ['cmid', 'keep_questionbankentryids']);
  assert.match(tool.description, /löscht weder Quiz-Slots noch Fragen/);

  const calls = [];
  await tool.handler({ cmid: 44, keep_questionbankentryids: [101] }, async (name, args) => {
    calls.push({ name, args });
    return {
      quizname: 'Version 2',
      editurl: 'https://moodle.test/mod/quiz/edit.php?cmid=44',
      removals: [
        { slot: 2, questionbankentryid: 202, questionid: 302, version: 3, questionname: 'Alte Frage', categoryid: 12, categoryname: 'Kapitel 2', reason: 'Nicht in der neuen Quizversion vorgesehen.' },
        { slot: 3, questionbankentryid: 303, questionid: 403, version: 1, questionname: 'Noch eine alte Frage', categoryid: 13, categoryname: 'Kapitel 3', reason: 'Nicht in der neuen Quizversion vorgesehen.' },
      ],
    };
  });

  assert.deepEqual(calls, [{
    name: 'local_coursepilot_get_quiz_cleanup_plan',
    args: { cmid: 44, 'keep_questionbankentryids[0]': 101 },
  }]);
});

test('cleanup plan service returns catalog-backed slot, question, category and direct edit URL details', () => {
  const source = fs.readFileSync(external, 'utf8');
  const serviceSource = fs.readFileSync(services, 'utf8');

  assert.match(source, /quiz_slots/);
  assert.match(source, /question_references/);
  assert.match(source, /question_bank_entries/);
  assert.match(source, /question_categories/);
  assert.match(source, /question_versions/);
  assert.match(source, /questionname/);
  assert.match(source, /categoryname/);
  assert.match(source, /edit\.php\?cmid=/);
  assert.match(source, /Nicht in der neuen Quizversion vorgesehen/);
  assert.match(source, /nicht aus der Fragensammlung gelöscht/);
  assert.match(serviceSource, /'local_coursepilot_get_quiz_cleanup_plan'\s*=>\s*\[/);
  assert.match(serviceSource, /'local_coursepilot_get_quiz_cleanup_plan',/);
  assert.match(serviceSource, /'type'\s*=>\s*'read'/);
});

test('exact public registries contain no destructive quiz or question endpoint', () => {
  const serviceSource = fs.readFileSync(services, 'utf8');
  const toolNames = QUIZ_TOOLS.map(({ name }) => name);
  const serviceNames = [...serviceSource.matchAll(/^\s*'([^']+)'\s*=>\s*\[/gm)].map(([, name]) => name);
  const destructive = /(?:delete|remove|purge|destroy).*(?:quiz|slot|question)|(?:quiz|slot|question).*(?:delete|remove|purge|destroy)/i;

  assert.deepEqual(toolNames, [
    'moodle_create_quiz',
    'moodle_update_quiz_settings',
    'moodle_plan_quiz_cleanup',
    'moodle_add_questions_to_quiz',
  ]);
  assert.equal(toolNames.some(name => destructive.test(name)), false);
  assert.equal(serviceNames.some(name => destructive.test(name)), false);
});
