'use strict';

/**
 * Vertragstest: Bild-Upload in Fragen + Copy-on-Version (Issue #326, KP-006).
 *
 * PHP-Webservices koennen hier nicht gegen ein echtes Moodle laufen (kein
 * Testmoodle in der Sandbox, siehe Integrationstests unter
 * test/integration/). Dieser Test prueft daher die Quelltextstruktur der
 * neuen/geaenderten PHP-Klassen (Pattern: fileupload-helper-contract.test.js,
 * question-move-contract.test.js) sowie den JS-Tool-Wrapper.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const EXTERNAL_DIR = path.join(
  __dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'external'
);
const UPLOAD_PATH = path.join(EXTERNAL_DIR, 'upload_question_image.php');
const GET_QUESTION_PATH = path.join(EXTERNAL_DIR, 'get_question.php');
const VERSION_HELPER_PATH = path.join(
  __dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'mc_question_version.php'
);

test('upload_question_image.php validiert area, answerid-Pflicht und MIME/Groesse ueber fileupload_helper', () => {
  const source = fs.readFileSync(UPLOAD_PATH, 'utf8');

  assert.match(source, /namespace local_coursepilot\\external;/);
  assert.match(source, /class upload_question_image extends external_api/);

  // Nur questiontext/answerfeedback erlaubt.
  assert.match(source, /'questiontext',\s*'answerfeedback'/);
  assert.match(source, /invalid_parameter_exception/);

  // answerid ist bei answerfeedback Pflicht und wird gegen die Frage geprueft.
  assert.match(source, /area'\]\s*===\s*'answerfeedback'/);
  assert.match(source, /answerid'\]\s*<=\s*0/);
  assert.match(source, /question_answers'.*'question'\s*=>\s*\$params\['questionid'\]/s);

  // 5-MB-Limit und MIME-Nur-Bilder-Pruefung ueber den gemeinsamen Helper.
  assert.match(source, /MAX_BYTES\s*=\s*5\s*\*\s*1024\s*\*\s*1024/);
  assert.match(source, /fileupload_helper::decode_and_validate\(\s*\$params\['content'\],\s*self::MAX_BYTES\s*\)/);
  assert.match(source, /str_starts_with\(\$detectedmimetype,\s*'image\/'\)/);

  // Idempotenz: Delete-vor-Write vor create_file.
  const deleteIndex = source.indexOf('fileupload_helper::delete_existing');
  const createIndex = source.indexOf('fileupload_helper::create_file(');
  assert.ok(deleteIndex > -1 && createIndex > -1 && deleteIndex < createIndex,
    'delete_existing muss vor create_file aufgerufen werden (Idempotenz bei gleichem Dateinamen)');

  // Component 'question', itemid = questionid (questiontext) bzw. answerid (answerfeedback).
  assert.match(source, /'question',\s*\$params\['area'\],\s*\$itemid/);

  // Rueckgabe: fileid/filename/@@PLUGINFILE@@-Snippet, kein automatisches
  // Schreiben in questiontext/generalfeedback (zweiphasiges Muster).
  assert.match(source, /@@PLUGINFILE@@/);
  assert.doesNotMatch(source, /\$DB->update_record\('question'/);

  // Capability-Pruefung analog zu update_mc_question.php.
  assert.match(source, /require_capability\('local\/coursepilot:use', \$context\)/);
  assert.match(source, /require_capability\('moodle\/question:add', \$context\)/);
});

test('db/services.php registriert local_coursepilot_upload_question_image im Coursepilot-Dienst', () => {
  const servicesPath = path.join(
    __dirname, '..', 'legacy', 'local_coursepilot', 'db', 'services.php'
  );
  const source = fs.readFileSync(servicesPath, 'utf8');

  assert.match(source, /'local_coursepilot_upload_question_image'\s*=>\s*\[/);
  assert.match(source, /classname'\s*=>\s*'local_coursepilot\\external\\upload_question_image'/);

  const serviceBlock = source.match(/'functions'\s*=>\s*\[(.*?)\],\s*'restrictedusers'/s)[1];
  assert.match(serviceBlock, /'local_coursepilot_upload_question_image'/);
});

test('mc_question_version::update kopiert Dateien der Vorgaengerversion per file_storage::create_file_from_storedfile', () => {
  const source = fs.readFileSync(VERSION_HELPER_PATH, 'utf8');

  assert.match(source, /function copy_files_to_new_version/);
  assert.match(source, /create_file_from_storedfile/);

  // Fragenebene: questiontext + generalfeedback vom alten aufs neue questionid.
  assert.match(source, /\['questiontext', 'generalfeedback'\]/);

  // Antwortebene: answer + answerfeedback, gemappt per Position (alt->neu).
  assert.match(source, /\['answer', 'answerfeedback'\]/);
  assert.match(source, /min\(count\(\$oldanswerids\), count\(\$newanswerids\)\)/);

  // update() ruft den Copy-Schritt nach dem save_question()-Aufruf auf.
  const updateFnMatch = source.match(/public static function update\([\s\S]*?\n    \}/);
  assert.ok(updateFnMatch, 'update()-Methode gefunden');
  assert.match(updateFnMatch[0], /self::copy_files_to_new_version\(/);
});

test('update_mc_question.php reicht den aufgeloesten Kontext an mc_question_version::update() durch', () => {
  const updatePath = path.join(EXTERNAL_DIR, 'update_mc_question.php');
  const source = fs.readFileSync(updatePath, 'utf8');

  assert.match(source, /mc_question_version::update\(\s*\$context->id,/);
});

test('get_question.php liefert Datei-Metadaten je Bereich, ohne pluginfile.php-URLs', () => {
  const source = fs.readFileSync(GET_QUESTION_PATH, 'utf8');

  assert.match(source, /function collect_files/);
  assert.match(source, /get_area_files\(\$contextid, 'question', \$filearea, \$itemid/);

  // Alle vier Bereiche werden abgedeckt: Fragenebene + je Antwort.
  assert.match(source, /collect_files\(\$fs, \$context->id, 'questiontext', \$question->id\)/);
  assert.match(source, /collect_files\(\$fs, \$context->id, 'generalfeedback', \$question->id\)/);
  assert.match(source, /collect_files\(\$fs, \$context->id, 'answer', \$a\['id'\]\)/);
  assert.match(source, /collect_files\(\$fs, \$context->id, 'answerfeedback', \$a\['id'\]\)/);

  // Strukturierte Felder, keine pluginfile.php-URL-Konstruktion.
  assert.match(source, /'filearea'\s*=>\s*\$filearea/);
  assert.match(source, /'filesize'\s*=>\s*\(int\) \$file->get_filesize\(\)/);
  assert.match(source, /'mimetype'\s*=>\s*\(string\) \$file->get_mimetype\(\)/);
  assert.doesNotMatch(source, /new \\?moodle_url\(/, 'kein pluginfile.php-URL-Aufbau im Read-back');
});

test('lib/question-bank-tools.js registriert moodle_upload_question_image als Write-Tool mit lokalem Dateipfad', () => {
  const {
    QUESTION_BANK_TOOLS,
    QUESTION_BANK_READ_ONLY_TOOL_NAMES,
  } = require('../lib/question-bank-tools');

  const tool = QUESTION_BANK_TOOLS.find(t => t.name === 'moodle_upload_question_image');
  assert.ok(tool, 'moodle_upload_question_image ist registriert');
  assert.deepEqual(tool.inputSchema.required, ['questionid', 'area', 'filepath']);
  assert.deepEqual(tool.inputSchema.properties.area.enum, ['questiontext', 'answerfeedback']);
  assert.ok(!QUESTION_BANK_READ_ONLY_TOOL_NAMES.has('moodle_upload_question_image'),
    'Upload ist ein Write-Tool, kein readOnly-Tool');
});

test('moodle_upload_question_image-Handler wirft verstaendlich bei fehlender lokaler Datei und fehlendem answerid', async () => {
  const { executeQuestionBankTool } = require('../lib/question-bank-tools');

  await assert.rejects(
    () => executeQuestionBankTool(async () => ({}), 'moodle_upload_question_image', {
      questionid: 1,
      area: 'questiontext',
      filepath: '/nonexistent/does-not-exist.png',
    }),
    /Datei nicht gefunden/
  );

  const tmpFile = path.join(require('node:os').tmpdir(), `kp-upload-test-${Date.now()}.png`);
  fs.writeFileSync(tmpFile, Buffer.from([0x89, 0x50, 0x4e, 0x47]));
  try {
    await assert.rejects(
      () => executeQuestionBankTool(async () => ({}), 'moodle_upload_question_image', {
        questionid: 1,
        area: 'answerfeedback',
        filepath: tmpFile,
      }),
      /answerid ist bei area=answerfeedback erforderlich/
    );
  } finally {
    fs.unlinkSync(tmpFile);
  }
});
