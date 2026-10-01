'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const tools = fs.readFileSync(path.join(root, 'lib', 'question-bank-tools.js'), 'utf8');
const services = fs.readFileSync(path.join(root, 'legacy', 'local_coursepilot', 'db', 'services.php'), 'utf8');
const external = path.join(root, 'legacy', 'local_coursepilot', 'classes', 'external', 'move_question.php');
const getQuestion = path.join(root, 'legacy', 'local_coursepilot', 'classes', 'external', 'get_question.php');

test('moodle_move_question is a public write tool backed by the local move service', () => {
  assert.match(tools, /name:\s*"moodle_move_question"/);
  assert.match(tools, /local_coursepilot_move_question/);
  assert.match(tools, /questionid:.*questionbankentryid/s);
  assert.match(tools, /targetcategoryid/);
  assert.match(services, /'local_coursepilot_move_question'\s*=>\s*\[/);
  assert.match(services, /'local_coursepilot_move_question',/);
});

test('get_question returns the current category for post-move read-back', () => {
  const source = fs.readFileSync(getQuestion, 'utf8');
  assert.match(source, /'categoryid'\s*=>\s*\(int\).*questioncategoryid/s);
  assert.match(source, /'categoryid'\s*=>\s*new external_value/);
});

test('question move expands the entry to all versions and uses Moodle Core bulk move capabilities', () => {
  const source = fs.readFileSync(external, 'utf8');

  assert.match(source, /question_versions.*questionbankentryid/s);
  assert.match(source, /question_require_capability_on\([^,]+,\s*'move'\)/);
  assert.match(source, /core_question\\external\\move_questions::execute/);
  assert.match(source, /local\/coursepilot:use/);
  assert.match(source, /questionbankentryid/);
  assert.match(source, /targetcategoryid/);
  assert.match(source, /versionids/);
});
