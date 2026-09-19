'use strict';

/**
 * Vertragstest: Versionstreuer XML-Frageimport (Issue #327, KP-011,
 * ADR-0001-Nachtrag zum Reimport-Verhalten).
 *
 * PHP-Webservices koennen hier nicht gegen ein echtes Moodle laufen (kein
 * Testmoodle in der Sandbox, siehe Integrationstests unter
 * test/integration/). Dieser Test prueft daher die Quelltextstruktur der
 * neuen PHP-Klasse (Pattern: upload-question-image-contract.test.js,
 * question-move-contract.test.js) sowie den JS-Tool-Wrapper.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const EXTERNAL_DIR = path.join(
  __dirname, '..', 'legacy', 'local_coursepilot', 'classes', 'external'
);
const IMPORT_PATH = path.join(EXTERNAL_DIR, 'import_questions_xml.php');
const SERVICES_PATH = path.join(
  __dirname, '..', 'legacy', 'local_coursepilot', 'db', 'services.php'
);

test('import_questions_xml.php parst rein lesend ueber qformat_xml::readquestions() und bricht bei Parse-Fehlern komplett ab', () => {
  const source = fs.readFileSync(IMPORT_PATH, 'utf8');

  assert.match(source, /namespace local_coursepilot\\external;/);
  assert.match(source, /class import_questions_xml extends external_api/);

  // Parameter exakt wie in docs/specs/0014 gefordert.
  assert.match(source, /'categoryid'\s*=>\s*new external_value\(PARAM_INT/);
  assert.match(source, /'xmlcontent'\s*=>\s*new external_value\(PARAM_RAW/);
  assert.match(source, /'allownew'\s*=>\s*new external_value\(PARAM_BOOL/);

  // qformat_xml::readquestions() ist reines Parsen, kein importprocess().
  assert.match(source, /new qformat_xml\(\)/);
  assert.match(source, /\$qformat->readquestions\(\$lines\)/);
  assert.doesNotMatch(source, /->importprocess\(\)/,
    'importprocess() erzeugt immer neue question_bank_entries-Eintraege und darf nicht genutzt werden');

  // Parse-Fehler und leeres Ergebnis brechen den gesamten Aufruf ab, bevor
  // irgendetwas geschrieben wird.
  assert.match(source, /catch \(\\Throwable \$e\)/);
  assert.match(source, /throw new \\invalid_parameter_exception\('Ungueltiges Moodle-XML/);
  assert.match(source, /throw new \\invalid_parameter_exception\('Das XML enthaelt keine importierbaren Fragen/);

  // Parsen passiert komplett vor jedem Schreibzugriff (self::parse() vor der
  // Schleife ueber import_one()).
  const parseCallIndex = source.indexOf('self::parse(');
  const importOneDefIndex = source.indexOf('private static function import_one(');
  assert.ok(parseCallIndex > -1 && importOneDefIndex > -1 && parseCallIndex < importOneDefIndex,
    'parse() muss vor jedem Schreibzugriff aufgerufen werden');
});

test('import_questions_xml.php schreibt ueber question_type::save_question(), nicht ueber importprocess()', () => {
  const source = fs.readFileSync(IMPORT_PATH, 'utf8');

  assert.match(source, /question_bank::get_qtype\(\$question->qtype\)/);
  assert.match(source, /\$qtype->save_question\(\$towrite, \$form\)/);

  // $form->status muss explizit gesetzt werden (bekannte Lektion aus Issue #321).
  assert.match(source, /\$form->status = question_version_status::QUESTION_STATUS_READY;/);

  // Neue Version nur, wenn ein bestehender questionid bekannt ist.
  assert.match(source, /if \(\$oldquestionid !== null\) \{\s*\$towrite->id = \$oldquestionid;/);
});

test('import_questions_xml.php matcht ausschliesslich innerhalb der Zielkategorie: idnumber vor Namens-Fallback', () => {
  const source = fs.readFileSync(IMPORT_PATH, 'utf8');

  assert.match(source, /function find_match\(int \$categoryid, string \$xmlidnumber, string \$name\): \?\\stdClass/);
  assert.match(source, /'questioncategoryid' => \$categoryid,\s*'idnumber'\s*=> \$xmlidnumber,/);

  // Namens-Fallback ist im Code klar als "nur ohne idnumber" gekennzeichnet
  // und liegt hinter dem idnumber-Zweig.
  const idnumberBranch = source.indexOf("if (\$xmlidnumber !== '') {");
  const nameFallbackComment = source.indexOf('Namens-Fallback ausschliesslich ohne idnumber im XML');
  assert.ok(idnumberBranch > -1 && nameFallbackComment > idnumberBranch,
    'idnumber-Matching muss vor dem Namens-Fallback stehen');

  // idnumber ohne Treffer und Namenstreffer sind beides Verdachtsfaelle
  // (ambiguous = true), kein Erstimport.
  assert.match(source, /'ambiguous'\s*=>\s*true,[\s\S]*?'questionid'\s*=>\s*0,/);
});

test('import_questions_xml.php schreibt bei einem Verdachtsfall nichts ohne allownew=true', () => {
  const source = fs.readFileSync(IMPORT_PATH, 'utf8');

  const ambiguousBlock = source.match(/if \(\$match->ambiguous\) \{[\s\S]*?\n {8}\}/);
  assert.ok(ambiguousBlock, 'ambiguous-Zweig gefunden');
  assert.match(ambiguousBlock[0], /if \(!\$allownew\) \{/);
  assert.match(ambiguousBlock[0], /'status'\s*=>\s*'skipped_ambiguous',/);
  // Der skipped_ambiguous-Rueckgabepfad ruft weder save() noch $DB-Schreib-
  // funktionen auf.
  const skippedReturn = ambiguousBlock[0].match(/if \(!\$allownew\) \{([\s\S]*?)\}/)[1];
  assert.doesNotMatch(skippedReturn, /self::save\(/);
});

test('import_questions_xml.php liefert name/questionbankentryid/version/status je Frage, questiontext_old/new bei versioned und Verdachtsfall', () => {
  const source = fs.readFileSync(IMPORT_PATH, 'utf8');

  const returns = source.match(/public static function execute_returns\(\)[\s\S]*$/)[0];
  assert.match(returns, /'name'\s*=>\s*new external_value\(PARAM_TEXT/);
  assert.match(returns, /'questionbankentryid'\s*=>\s*new external_value\(PARAM_INT/);
  assert.match(returns, /'version'\s*=>\s*new external_value\(PARAM_INT/);
  assert.match(returns, /'status'\s*=>\s*new external_value\(PARAM_ALPHANUMEXT/);
  assert.match(returns, /'questiontext_old'\s*=>\s*new external_value\(PARAM_RAW[\s\S]*?VALUE_OPTIONAL/);
  assert.match(returns, /'questiontext_new'\s*=>\s*new external_value\(PARAM_RAW[\s\S]*?VALUE_OPTIONAL/);
});

test('import_questions_xml.php nennt bei idnumber ohne Kategorie-Treffer zusaetzlich xml_idnumber, categoryid und nahe Kandidaten (docs/specs/0014)', () => {
  const source = fs.readFileSync(IMPORT_PATH, 'utf8');

  assert.match(source, /function find_name_candidates\(int \$categoryid, string \$name\): array/);
  assert.match(source, /'reason'\s*=>\s*'idnumber_notfound',/);

  const ambiguousBlock = source.match(/if \(\$match->ambiguous\) \{[\s\S]*?\n {8}\}/)[0];
  assert.match(ambiguousBlock, /if \(\$match->reason === 'idnumber_notfound'\) \{/);
  assert.match(ambiguousBlock, /\$result\['xml_idnumber'\] = \$match->idnumber;/);
  assert.match(ambiguousBlock, /\$result\['categoryid'\]\s*=\s*\(int\) \$category->id;/);
  assert.match(ambiguousBlock, /\$result\['candidates'\]\s*=\s*self::find_name_candidates/);

  const returns = source.match(/public static function execute_returns\(\)[\s\S]*$/)[0];
  assert.match(returns, /'xml_idnumber'\s*=>\s*new external_value\(PARAM_TEXT[\s\S]*?VALUE_OPTIONAL/);
  assert.match(returns, /'categoryid'\s*=>\s*new external_value\(PARAM_INT[\s\S]*?VALUE_OPTIONAL/);
  assert.match(returns, /'candidates'\s*=>\s*new external_multiple_structure/);
});

test('import_questions_xml.php prueft Capability moodle/question:add im Kategorie-Kontext', () => {
  const source = fs.readFileSync(IMPORT_PATH, 'utf8');

  assert.match(source, /require_capability\('local\/coursepilot:use', \$context\)/);
  assert.match(source, /require_capability\('moodle\/question:add', \$context\)/);
});

test('db/services.php registriert local_coursepilot_import_questions_xml im Coursepilot-Dienst', () => {
  const source = fs.readFileSync(SERVICES_PATH, 'utf8');

  assert.match(source, /'local_coursepilot_import_questions_xml'\s*=>\s*\[/);
  assert.match(source, /classname'\s*=>\s*'local_coursepilot\\external\\import_questions_xml'/);

  const serviceBlock = source.match(/'functions'\s*=>\s*\[(.*?)\],\s*'restrictedusers'/s)[1];
  assert.match(serviceBlock, /'local_coursepilot_import_questions_xml'/);
});

test('lib/question-bank-tools.js registriert moodle_import_questions_xml als Write-Tool mit categoryid+xmlcontent Pflicht', () => {
  const {
    QUESTION_BANK_TOOLS,
    QUESTION_BANK_READ_ONLY_TOOL_NAMES,
  } = require('../lib/question-bank-tools');

  const tool = QUESTION_BANK_TOOLS.find(t => t.name === 'moodle_import_questions_xml');
  assert.ok(tool, 'moodle_import_questions_xml ist registriert');
  assert.deepEqual(tool.inputSchema.required, ['categoryid', 'xmlcontent']);
  assert.equal(tool.inputSchema.properties.allownew.default, false);
  assert.ok(!QUESTION_BANK_READ_ONLY_TOOL_NAMES.has('moodle_import_questions_xml'),
    'Import ist ein Write-Tool, kein readOnly-Tool');
});

test('moodle_import_questions_xml-Handler reicht categoryid/xmlcontent durch und sendet allownew als 0/1 (PARAM_BOOL akzeptiert per REST keine "true"/"false"-Strings)', async () => {
  const { executeQuestionBankTool } = require('../lib/question-bank-tools');

  let received = null;
  const fakeCallMoodle = async (fn, args) => {
    received = { fn, args };
    return { questions: [] };
  };

  await executeQuestionBankTool(fakeCallMoodle, 'moodle_import_questions_xml', {
    categoryid: 7,
    xmlcontent: '<quiz></quiz>',
  });

  assert.equal(received.fn, 'local_coursepilot_import_questions_xml');
  assert.deepEqual(received.args, { categoryid: 7, xmlcontent: '<quiz></quiz>', allownew: 0 });

  await executeQuestionBankTool(fakeCallMoodle, 'moodle_import_questions_xml', {
    categoryid: 7,
    xmlcontent: '<quiz></quiz>',
    allownew: true,
  });
  assert.equal(received.args.allownew, 1);
});
