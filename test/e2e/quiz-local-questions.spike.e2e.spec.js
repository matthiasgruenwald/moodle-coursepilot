'use strict';

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { config, isSpikeProfile } = require('./helpers/env');
const { cleanupQuizActivities } = require('./helpers/spike-quiz-cleanup');

test.skip(!isSpikeProfile || !config.moodleUrl || config.courseId !== 6, 'Spike profile with isolated fixture activities in course 6 required');

test('Quiz-local questions retain versions and quiz references through XML transfer and move over MCP', async ({ request }) => {
  test.setTimeout(120_000);
  const token = execFileSync('bash', [path.resolve(__dirname, '../../scripts/spike-e2e-token.sh')], { encoding: 'utf8' }).trim();
  const cmids = [];
  let exported;
  let sequence = 0;
  const name = `E2E-QuizLocal-${Date.now()}`;
  async function rpc(method, params) {
    const response = await request.post(`${config.moodleUrl.replace(/\/+$/, '')}/local/coursepilot/mcp.php`, {
      headers: { Authorization: `Bearer ${token}` },
      data: { jsonrpc: '2.0', id: ++sequence, method, params },
    });
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.error, JSON.stringify(body.error)).toBeUndefined();
    return body.result;
  }
  async function call(tool, args) {
    const result = await rpc('tools/call', { name: `coursepilot_${tool}`, arguments: args });
    expect(result.isError, JSON.stringify(result.content)).not.toBe(true);
    return result.structuredContent;
  }
  try {
    const tools = await rpc('tools/list', {});
    expect(tools.tools.map(tool => tool.name)).toContain('coursepilot_ensure_quiz_question_categories');
    const quiz = await call('create_quiz', { courseid: config.courseId, sectionnum: 0, mode: 'mini-check',
      fields_json: JSON.stringify({ name, intro: '', subnet: '', browsersecurity: '-' }) });
    cmids.push(quiz.cmid);
    const local = await call('ensure_quiz_question_categories', { courseid: config.courseId, cmid: quiz.cmid });
    expect(local.categories.map(category => category.id)).toContain(local.defaultcategoryid);
    expect(await call('ensure_quiz_question_categories', { courseid: config.courseId, cmid: quiz.cmid })).toEqual(local);
    const category = await call('ensure_question_category', { name: 'Arithmetic', parent: local.defaultcategoryid });
    expect(category.contextid).toBe(local.contextid);
    const question = await call('create_mc_question', { categoryid: category.id, name: 'Addition', questiontext: 'What is 2+2?',
      selectionmode: 'single', generalfeedback: 'Add two pairs.', answers: [
        { answer: '4', fraction: 1, feedback: 'Correct' }, { answer: '5', fraction: 0, feedback: 'Try again' },
      ] });
    const attached = await call('add_questions_to_quiz', { cmid: quiz.cmid, questionids: [question.questionid] });
    expect(attached.slots[0].questionbankentryid).toBe(question.questionbankentryid);
    const updated = await call('update_mc_question', { questionid: question.questionid,
      fields_json: JSON.stringify({ questiontext: 'What is two plus two?' }) });
    expect(updated.version).toBe(2);
    const source = await call('get_question', { categoryid: category.id, questionid: question.questionid });
    expect(source.categoryid).toBe(category.id);
    expect(source.questionbankentryid).toBe(question.questionbankentryid);

    const bank = await call('ensure_question_bank', { courseid: config.courseId, name: `${name}-Bank` });
    cmids.push(bank.questionbankid);
    const target = await call('ensure_question_category', { name: 'Transferred', parent: bank.topcategoryid });
    const xml = await call('export_questions_xml', { questionids: [updated.questionid], targetpath: `${name}.xml` });
    exported = xml.path;
    const args = { categoryid: target.id, xmlpath: exported, location: 'workbench' };
    const suspect = await call('import_questions_xml', args);
    expect(suspect.questions[0].status).toBe('suspect');
    const imported = await call('import_questions_xml', { ...args, confirmed: true });
    const copy = imported.questions[0];
    expect(copy.status).toBe('first_import');
    expect(copy.version).toBe(1);
    expect(copy.questionbankentryid).not.toBe(question.questionbankentryid);
    const readback = await call('get_question', { categoryid: target.id, name: 'Addition' });
    for (const field of ['name', 'questiontext', 'generalfeedback', 'selectionmode', 'defaultmark']) {
      expect(readback[field]).toEqual(source[field]);
    }
    const answerContent = answers => answers.map(({ answer, fraction, feedback }) => ({ answer, fraction, feedback }));
    expect(answerContent(readback.answers)).toEqual(answerContent(source.answers));
    const reimport = await call('import_questions_xml', args);
    expect(reimport.questions[0]).toMatchObject({ status: 'reimport', version: 2, questionbankentryid: copy.questionbankentryid });

    const movetarget = await call('ensure_question_category', { name: 'Moved', parent: bank.topcategoryid });
    const moved = await call('move_question', { questionid: question.questionid, targetcategoryid: movetarget.id });
    expect(moved).toMatchObject({ status: 'moved', questionbankentryid: question.questionbankentryid,
      versionids: [question.questionid, updated.questionid] });
    const after = await call('get_question', { categoryid: movetarget.id, questionid: question.questionid });
    expect(after.categoryid).toBe(movetarget.id);
    expect(after.questiontext).toBe(source.questiontext);
    const slots = await call('add_questions_to_quiz', { cmid: quiz.cmid, questionids: [question.questionid] });
    expect(slots.appended[0].added).toBe(false);
    expect(slots.slots).toHaveLength(1);
    expect(slots.slots[0]).toMatchObject({ questionbankentryid: question.questionbankentryid, questionid: updated.questionid, version: 2 });

    const invalid = await rpc('tools/call', { name: 'coursepilot_ensure_quiz_question_categories',
      arguments: { courseid: config.courseId, cmid: bank.questionbankid } });
    expect(invalid.isError).toBe(true);
  } finally {
    try {
      if (exported) await call('delete_material_files', { paths: [exported] });
    } finally {
      cleanupQuizActivities(cmids, config.courseId);
    }
  }
});
