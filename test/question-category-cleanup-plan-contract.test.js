'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { QUESTION_BANK_TOOLS, QUESTION_BANK_READ_ONLY_TOOL_NAMES } = require('../lib/question-bank-tools');

const root = path.join(__dirname, '..');
const external = path.join(root, 'legacy', 'local_coursepilot', 'classes', 'external', 'get_question_category_cleanup_plan.php');
const services = path.join(root, 'legacy', 'local_coursepilot', 'db', 'services.php');

test('question category cleanup plan is read-only and identifies every empty leaf category for manual removal', async () => {
  const tool = QUESTION_BANK_TOOLS.find(({ name }) => name === 'moodle_plan_question_category_cleanup');
  assert.ok(tool, 'read-only cleanup planning tool is exposed');
  assert.deepEqual(tool.inputSchema.required, ['courseid', 'questionbankid']);
  assert.ok(QUESTION_BANK_READ_ONLY_TOOL_NAMES.has('moodle_plan_question_category_cleanup'));

  const calls = [];
  await tool.handler({ courseid: 7, questionbankid: 44 }, async (name, args) => {
    calls.push({ name, args });
    return {
      questionbankname: 'Biologie 9a - Immunsystem',
      removals: [
        { id: 1173, name: 'HITL Cleanup Quelle umbenannt', parent: 1172, editurl: 'https://moodle.test/question/edit.php?cmid=44&cat=1173,55', reason: 'Leere, blattlose Kategorie ohne eigene Fragen und ohne Unterkategorien.' },
      ],
    };
  });

  assert.deepEqual(calls, [{
    name: 'local_coursepilot_get_question_category_cleanup_plan',
    args: { courseid: 7, questionbankid: 44 },
  }]);
});

test('cleanup plan service detects categories without questions and without children, never destructively', () => {
  const source = fs.readFileSync(external, 'utf8');
  const serviceSource = fs.readFileSync(services, 'utf8');

  assert.match(source, /question_bank_entries/);
  assert.match(source, /question_categories/);
  assert.match(source, /edit\.php\?cmid=/);
  assert.match(source, /Leere, blattlose Kategorie/);
  assert.doesNotMatch(source, /delete_records|DELETE FROM/);
  assert.match(serviceSource, /'local_coursepilot_get_question_category_cleanup_plan'\s*=>\s*\[/);
  assert.match(serviceSource, /'local_coursepilot_get_question_category_cleanup_plan',/);
  assert.match(serviceSource, /'type'\s*=>\s*'read'/);
});

test('question bank tool registry contains no destructive category endpoint', () => {
  const serviceSource = fs.readFileSync(services, 'utf8');
  const toolNames = QUESTION_BANK_TOOLS.map(({ name }) => name);
  const serviceNames = [...serviceSource.matchAll(/^\s*'([^']+)'\s*=>\s*\[/gm)].map(([, name]) => name);
  const destructive = /(?:delete|remove|purge|destroy).*(?:question|categor)|(?:question|categor).*(?:delete|remove|purge|destroy)/i;

  assert.equal(toolNames.some(name => destructive.test(name)), false);
  assert.equal(serviceNames.some(name => destructive.test(name)), false);
});
