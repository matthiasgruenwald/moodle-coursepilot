const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const repoRoot = path.join(__dirname, '..');
const NATIVE_CATALOG_PATH = path.join(
  repoRoot, 'Plugin', 'src', 'local_coursepilot', 'classes', 'external', 'get_course_catalog.php'
);
const PRIVACY_SURFACE_PATH = path.join(repoRoot, 'Plugin', 'src', 'local_coursepilot', 'classes', 'privacy_surface.php');
const SERVICES_PATH = path.join(repoRoot, 'Plugin', 'src', 'local_coursepilot', 'db', 'services.php');
const TOOL_REGISTRY_PATH = path.join(repoRoot, 'Plugin', 'src', 'local_coursepilot', 'classes', 'tool_registry.php');

function read(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

// Seit #378 gibt es eine einzige Werkzeug-Registrierung
// (classes/tool_registry.php); dispatcher.php, db/services.php und
// privacy_surface.php leiten ihre Listen daraus ab statt eigene Kopien zu
// fuehren. Extrahiert den TOOLS-Eintrag eines Werkzeugs (8-Leerzeichen-
// Einrueckung fuer Schluessel und schliessende Klammer).
function extractRegistryEntry(source, toolName) {
  const re = new RegExp(`'${toolName}'\\s*=>\\s*\\[([\\s\\S]*?)\\n {8}\\],`);
  const match = source.match(re);
  assert.ok(match, `Registry-Eintrag fuer ${toolName} nicht gefunden`);
  return match[1];
}

// Extracts the concatenated PHP string literal assigned to a
// tool_registry TOOLS key, e.g. 'key' => 'a' . 'b' . 'c'.
function extractToolField(entry, fieldName) {
  const re = new RegExp(
    `'${fieldName}'\\s*=>\\s*((?:'(?:[^'\\\\]|\\\\.)*'\\s*(?:\\.\\s*)?)+)`
  );
  const match = entry.match(re);
  assert.ok(match, `Feld ${fieldName} nicht gefunden`);
  const parts = match[1].match(/'(?:[^'\\]|\\.)*'/g);
  return parts.map((p) => p.slice(1, -1)).join('');
}

test('coursepilot_get_course_catalog is a self-contained port with the same contract fields as the local tool, no cross-plugin dependency', () => {
  assert.ok(fs.existsSync(NATIVE_CATALOG_PATH), 'natives get_course_catalog fehlt');

  const source = read(NATIVE_CATALOG_PATH);

  assert.match(source, /namespace local_coursepilot\\external;/);
  assert.match(source, /class get_course_catalog extends external_api/);
  assert.match(source, /aus Moodle gelesen/);
  assert.match(source, /sectionnum/);
  assert.match(source, /modname/);
  assert.match(source, /detail/);
  assert.match(source, /course_sections/);
  assert.match(source, /course_modules/);
  assert.match(source, /completionpassgrade/);
  assert.match(source, /availability/);
  assert.match(source, /quiz_slots/);
  assert.match(source, /question_references/);

  // Eigene Capability-Pruefung, konsistent mit db/services.php/privacy_surface.
  assert.match(source, /require_capability\('local\/coursepilot:use', \$context\)/);

  // Das Plugin steht fuer sich: kein `use`-Import aus einem anderen
  // local_*-Plugin. Urspruenglich gegen den lokalen Altstand gerichtet (Spec
  // 0012, Fund aus dem PHPUnit-Lauf zu #341); der traegt seit ADR 0024
  // denselben Komponentennamen und liegt in legacy/, wird also nie zusammen
  // installiert. Ein Import aus einem fremden Plugin waere auf der Instanz ein
  // Fatal Error "Class ... not found".
  assert.doesNotMatch(source, /^use local_(?!coursepilot\b)/m);

  // Maskierung ueber die eigene, geteilte reine Funktion, nicht inline im
  // Katalogcode dupliziert.
  assert.match(source, /use local_coursepilot\\availability_privacy;/);
  assert.match(source, /availability_privacy::sanitize\(/);
});

test('availability_privacy is a self-contained shared pure function within local_coursepilot, no cross-plugin dependency', () => {
  const path_ = path.join(repoRoot, 'Plugin', 'src', 'local_coursepilot', 'classes', 'availability_privacy.php');
  assert.ok(fs.existsSync(path_), 'local_coursepilot availability_privacy fehlt');

  const source = read(path_);
  assert.match(source, /namespace local_coursepilot;/);
  assert.match(source, /class availability_privacy/);
  assert.match(source, /function sanitize\(string \$availability\): string/);
  assert.match(source, /'\*\*\*'/);
  assert.doesNotMatch(source, /^use local_(?!coursepilot\b)/m);
});

test('coursepilot_get_course_catalog is registered as a read-only tool and Moodle webservice function', () => {
  const registry = read(TOOL_REGISTRY_PATH);
  const entry = extractRegistryEntry(registry, 'coursepilot_get_course_catalog');

  assert.match(entry, /'function'\s*=>\s*'local_coursepilot_get_course_catalog'/);
  assert.match(entry, /'classname'\s*=>\s*'local_coursepilot\\external\\get_course_catalog'/);
  assert.doesNotMatch(entry, /'write'\s*=>\s*true/);

  // privacy_surface und db/services.php muessen tatsaechlich aus der
  // Registry ableiten (#378), nicht eigene Kopien fuehren - sonst kann die
  // eine Quelle wieder auseinanderlaufen.
  const surface = read(PRIVACY_SURFACE_PATH);
  assert.match(surface, /tool_registry::allowed_tools\(\)/);

  const services = read(SERVICES_PATH);
  assert.match(services, /tool_registry::service_functions\(\)/);
  assert.match(services, /tool_registry::service_function_names\(\)/);
});

test('coursepilot_get_course_catalog tool description documents source, detail levels, masking, and explicit grouping', () => {
  const registry = read(TOOL_REGISTRY_PATH);
  const entry = extractRegistryEntry(registry, 'coursepilot_get_course_catalog');
  const description = extractToolField(entry, 'description');

  assert.match(description, /aus Moodle gelesen/);
  assert.match(description, /full/);
  assert.match(description, /compact/);
  assert.match(description, /maskiert/);
  assert.match(description, /Gruppennamen/);
  // Echte Umlaute in lehrkraftsichtbaren Texten (#521), nicht ae/oe/ue.
  assert.match(description, /ausdrücklich/);

  const byteLength = Buffer.byteLength(description, 'utf8');
  assert.ok(byteLength < 2048, `Beschreibung ist ${byteLength} Bytes lang, muss unter 2 KB bleiben`);
});

test('tools() builds a real inputSchema for coursepilot_get_course_catalog instead of an empty one', () => {
  const registry = read(TOOL_REGISTRY_PATH);
  const entry = extractRegistryEntry(registry, 'coursepilot_get_course_catalog');

  assert.match(entry, /'schema'\s*=>\s*\[/);
  assert.match(entry, /'courseid'\s*=>\s*\['type'\s*=>\s*'number'/);
  assert.match(entry, /'required'\s*=>\s*\['courseid'\]/);
});
