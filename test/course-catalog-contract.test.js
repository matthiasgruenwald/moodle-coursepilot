const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const repoRoot = path.join(__dirname, '..');
const EXTERNAL_PATH = path.join(
  repoRoot,
  'legacy',
  'local_coursepilot',
  'classes',
  'external',
  'get_course_catalog.php'
);
const SERVICES_PATH = path.join(repoRoot, 'legacy', 'local_coursepilot', 'db', 'services.php');
// Issue #89: moodle_get_course_catalog ist ein Core-Tool; die Tool-Definition
// liegt in lib/core-tools.js, geteilt von moodle-mcp.js und
// moodle-mcp-core.js (ADR 0007).
const MCP_PATH = path.join(repoRoot, 'lib', 'core-tools.js');
const CORE_PATH = path.join(repoRoot, 'skills', 'kurspilot-core.md');
const GET_QUESTION_PATH = path.join(
  repoRoot,
  'legacy',
  'local_coursepilot',
  'classes',
  'external',
  'get_question.php'
);

function read(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

test('read-only course catalog plugin contract covers Moodle planning state', () => {
  assert.ok(fs.existsSync(EXTERNAL_PATH), 'get_course_catalog external class exists');

  const source = read(EXTERNAL_PATH);

  assert.match(source, /class get_course_catalog extends external_api/);
  assert.match(source, /aus Moodle gelesen/);
  assert.match(source, /sectionnum/);
  assert.match(source, /modname/);
  assert.match(source, /detail/);
  assert.match(source, /course_sections/);
  assert.match(source, /course_modules/);
  assert.match(source, /completionpassgrade/);
  assert.match(source, /availability/);
  assert.match(source, /page/);
  assert.match(source, /label/);
  assert.match(source, /assign/);
  assert.match(source, /quiz/);
  assert.match(source, /quiz_slots/);
  assert.match(source, /question_references/);
  assert.match(source, /questioncategoryid/);
  assert.match(source, /grade_items/);

  const services = read(SERVICES_PATH);
  assert.match(services, /'local_coursepilot_get_course_catalog'\s*=>/);
  assert.match(services, /'type'\s*=>\s*'read'/);
  assert.match(services, /'local_coursepilot_get_course_catalog'/);
});

test('read-only question detail includes answers and general feedback', () => {
  const source = read(GET_QUESTION_PATH);

  assert.match(source, /questiontext/);
  assert.match(source, /question_answers/);
  assert.match(source, /answers/);
  assert.match(source, /generalfeedback/);
  assert.match(source, /selectionmode/);
  assert.match(source, /feedback/);
  assert.match(source, /fraction/);
});

test('MCP exposes a compact filterable read-only Moodle catalog tool', () => {
  const source = read(MCP_PATH);

  assert.match(source, /name:\s*"moodle_get_course_catalog"/);
  assert.match(source, /Moodle-Katalogansicht/);
  assert.match(source, /courseid/);
  assert.match(source, /sectionnum/);
  assert.match(source, /modname/);
  assert.match(source, /detail/);
  assert.match(source, /local_coursepilot_get_course_catalog/);
});

test('kurspilot-planen documents catalog gaps and source reconciliation', () => {
  const core = read(CORE_PATH);

  assert.match(core, /moodle_get_course_catalog/);
  assert.match(core, /aus Moodle gelesen/);
  assert.match(core, /Kursstand-Luecke/);
  assert.match(core, /lokal dokumentiert\/geplant/);
  assert.match(core, /Kursstand-Abgleich/);
  assert.match(core, /welche Quelle aktuell gelten soll/);
  assert.match(core, /aktualisiert danach den lokalen Planungsstand/);
});
