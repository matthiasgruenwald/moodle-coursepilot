'use strict';

/**
 * Vertragstest: Lernendendaten-Schutzgrenze (Issue #187, Parent #146,
 * Spezifikation docs/specs/0003-coursepilot-marketplace-readiness.md,
 * Abschnitt "Datenschutz und Datenzugriff").
 *
 * Coursepilot gibt ueber MCP-Tools und Moodle-Webservices ausschliesslich
 * lehrkraftbezogene Kursgestaltungsoberflaeche frei. Von Lernenden erzeugte
 * oder personenbezogene Daten (Aufgabenabgaben, Forenbeitraege, Quizversuche,
 * Bewertungen, Teilnehmendenlisten) sind nicht erreichbar.
 *
 * Die erlaubte Oberflaeche ist eine positive Allowlist
 * (lib/data-protection-allowlist.js). Dieser Test erzwingt:
 *  - AC1: MCP-Tool- und Webservice-Oberflaeche entsprechen exakt der Allowlist
 *         (ein spaeter hinzugefuegter Dienst scheitert hier bis zur
 *         ausdruecklichen Allowlist-Pflege).
 *  - AC2: Weder Allowlist noch reale Oberflaeche enthalten Lernendendaten-
 *         Werkzeuge; die Lese-Oberflaeche ist auf Lehrkraftinhalte begrenzt.
 *  - AC3: Kursgestaltungs-Werkzeuge bleiben fuer berechtigte Lehrkraefte
 *         verfuegbar.
 *  - AC4: Moodle-Privacy-API ist als null_provider umgesetzt und behauptet
 *         keine nicht unterstuetzten Lernendendaten.
 *  - AC5: README dokumentiert die Grenze in Lehrkraftsprache, ohne dem Plugin
 *         einen KI-Anbieter-Aufruf zuzuschreiben.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const {
  ALLOWED_MCP_TOOLS,
  ALLOWED_WEBSERVICE_FUNCTIONS,
  READ_WEBSERVICE_FUNCTIONS,
  FORBIDDEN_SURFACE_TOKENS,
  findForbiddenNames,
} = require('../lib/data-protection-allowlist');

const PLUGIN_ROOT = path.join(__dirname, '..', 'legacy', 'local_coursepilot');
const SERVICES_PATH = path.join(PLUGIN_ROOT, 'db', 'services.php');
const PRIVACY_PROVIDER_PATH = path.join(PLUGIN_ROOT, 'classes', 'privacy', 'provider.php');
const LANG_PATH = path.join(PLUGIN_ROOT, 'lang', 'en', 'local_coursepilot.php');
const README_PATH = path.join(__dirname, '..', 'README.md');
const SERVER_PATH = path.join(__dirname, '..', 'moodle-mcp.js');

const servicesSource = fs.readFileSync(SERVICES_PATH, 'utf8');

function declaredWebserviceFunctions() {
  const names = [];
  const pattern = /'([a-z0-9_]+)'\s*=>\s*\[\s*'classname'/g;
  let match;
  while ((match = pattern.exec(servicesSource)) !== null) {
    names.push(match[1]);
  }
  return names;
}

function serviceExposedFunctions() {
  const blockMatch = servicesSource.match(/'functions'\s*=>\s*\[(.*?)\],\s*'restrictedusers'/s);
  assert.ok(blockMatch, 'Coursepilot service functions block exists');
  const names = [];
  const itemPattern = /'([a-z0-9_]+)'/g;
  let match;
  while ((match = itemPattern.exec(blockMatch[1])) !== null) {
    names.push(match[1]);
  }
  return names;
}

function readTypedWebserviceFunctions() {
  const names = [];
  const pattern = /'([a-z0-9_]+)'\s*=>\s*\[[^\]]*?'type'\s*=>\s*'read'/gs;
  let match;
  while ((match = pattern.exec(servicesSource)) !== null) {
    names.push(match[1]);
  }
  return names;
}

function startServer() {
  const child = spawn('node', [SERVER_PATH], {
    env: {
      ...process.env,
      MOODLE_URL: 'https://example.test/moodle',
      MOODLE_TOKEN: 'dummy-token',
    },
  });

  let stdoutBuffer = '';
  const pending = [];

  child.stdout.on('data', chunk => {
    stdoutBuffer += chunk;
    const lines = stdoutBuffer.split('\n');
    stdoutBuffer = lines.pop();
    for (const line of lines) {
      if (!line.trim()) continue;
      const next = pending.shift();
      if (next) next(JSON.parse(line));
    }
  });

  return {
    request(message) {
      child.stdin.write(`${JSON.stringify(message)}\n`);
      return new Promise(resolve => pending.push(resolve));
    },
    stop() {
      child.stdin.end();
    },
  };
}

async function listLiveToolNames() {
  const server = startServer();
  try {
    const response = await server.request({ jsonrpc: '2.0', id: 1, method: 'tools/list' });
    return response.result.tools.map(tool => tool.name);
  } finally {
    server.stop();
  }
}

// ─────────────────────────────────────────────────────────────
// AC1: positive Allowlist, erzwungen gegen die reale Oberflaeche
// ─────────────────────────────────────────────────────────────

test('AC1: MCP-Tool-Oberflaeche entspricht exakt der positiven Allowlist', async () => {
  const liveTools = await listLiveToolNames();

  assert.deepEqual([...liveTools].sort(), [...ALLOWED_MCP_TOOLS].sort());
});

test('AC1: Webservice-Oberflaeche entspricht exakt der positiven Allowlist', () => {
  const exposed = serviceExposedFunctions();
  const declared = declaredWebserviceFunctions();

  assert.deepEqual([...exposed].sort(), [...ALLOWED_WEBSERVICE_FUNCTIONS].sort());

  const undeclared = declared.filter(name => !ALLOWED_WEBSERVICE_FUNCTIONS.includes(name));
  assert.deepEqual(undeclared, [], 'keine deklarierte Webservice-Funktion ausserhalb der Allowlist');
});

test('AC1: Allowlist ist duplikatfrei und unveraenderlich', () => {
  assert.equal(new Set(ALLOWED_MCP_TOOLS).size, ALLOWED_MCP_TOOLS.length);
  assert.equal(new Set(ALLOWED_WEBSERVICE_FUNCTIONS).size, ALLOWED_WEBSERVICE_FUNCTIONS.length);
  assert.ok(Object.isFrozen(ALLOWED_MCP_TOOLS));
  assert.ok(Object.isFrozen(ALLOWED_WEBSERVICE_FUNCTIONS));
});

// ─────────────────────────────────────────────────────────────
// AC2: keine Lernendendaten-Oberflaeche
// ─────────────────────────────────────────────────────────────

test('AC2: Allowlist enthaelt keine Lernendendaten-Werkzeuge', () => {
  assert.deepEqual(findForbiddenNames(ALLOWED_MCP_TOOLS), []);
  assert.deepEqual(findForbiddenNames(ALLOWED_WEBSERVICE_FUNCTIONS), []);
});

test('AC2: reale Oberflaeche enthaelt keine Lernendendaten-Werkzeuge', async () => {
  const liveTools = await listLiveToolNames();

  assert.deepEqual(findForbiddenNames(liveTools), []);
  assert.deepEqual(findForbiddenNames(declaredWebserviceFunctions()), []);
  assert.deepEqual(findForbiddenNames(serviceExposedFunctions()), []);
});

test('AC2: Lese-Oberflaeche der Webservices ist auf Lehrkraftinhalte begrenzt', () => {
  const reads = readTypedWebserviceFunctions();

  assert.deepEqual([...reads].sort(), [...READ_WEBSERVICE_FUNCTIONS].sort());
  assert.deepEqual(findForbiddenNames(reads), []);
});

test('AC2: Forum-Kursgestaltung ist erlaubt, Forum-Beitraege-Lesen bleibt gesperrt (#224)', () => {
  // Das Anlegen/Aendern einer Forum-Aktivitaet ist Lehrkraft-Kursgestaltung.
  assert.deepEqual(findForbiddenNames(['moodle_create_forum', 'moodle_update_forum']), []);
  assert.ok(ALLOWED_MCP_TOOLS.includes('moodle_create_forum'));
  assert.ok(ALLOWED_MCP_TOOLS.includes('moodle_update_forum'));

  // Die Lernendendaten-Oberflaeche (Diskussionen/Beitraege lesen) bleibt
  // gesperrt: Moodles forum-lesende Webservices heissen durchgaengig
  // "...discussion(s)/...posts" und laufen in den "discussion"-Token.
  const forumReadSurface = [
    'moodle_get_forum_discussions',
    'mod_forum_get_forum_discussion_posts',
    'local_coursepilot_get_discussions',
  ];
  const violations = findForbiddenNames(forumReadSurface);
  assert.equal(violations.length, forumReadSurface.length);
  for (const violation of violations) {
    assert.equal(violation.token, 'discussion');
  }
});

// ─────────────────────────────────────────────────────────────
// AC3: Kursgestaltungs-Werkzeuge bleiben verfuegbar
// ─────────────────────────────────────────────────────────────

test('AC3: Kursgestaltungs-Werkzeuge bleiben in der Allowlist und live verfuegbar', async () => {
  const authoringTools = [
    'moodle_create_page',
    'moodle_update_page',
    'moodle_create_assign',
    'moodle_update_assign',
    'moodle_create_quiz',
    'moodle_update_quiz_settings',
    'moodle_add_questions_to_quiz',
    'moodle_create_label',
    'moodle_create_url',
    'moodle_update_section',
    'moodle_ensure_section',
    'moodle_upload_assignfile',
    'moodle_ensure_question_bank',
    'moodle_create_mc_question',
    'moodle_get_course_catalog',
    'moodle_get_sections',
    'moodle_get_modules',
  ];

  for (const tool of authoringTools) {
    assert.ok(ALLOWED_MCP_TOOLS.includes(tool), `${tool} bleibt allowlisted`);
  }

  const liveTools = await listLiveToolNames();
  for (const tool of authoringTools) {
    assert.ok(liveTools.includes(tool), `${tool} bleibt live sichtbar`);
  }
});

// ─────────────────────────────────────────────────────────────
// AC4: Privacy-API beschreibt die tatsaechliche Verarbeitung
// ─────────────────────────────────────────────────────────────

test('AC4: Privacy-Provider ist ein null_provider ohne Lernendendaten-Behauptung', () => {
  assert.ok(fs.existsSync(PRIVACY_PROVIDER_PATH), 'classes/privacy/provider.php existiert');
  const source = fs.readFileSync(PRIVACY_PROVIDER_PATH, 'utf8');

  assert.match(source, /namespace\s+local_coursepilot\\privacy;/);
  assert.match(source, /implements\s+null_provider/);
  assert.match(source, /use\s+core_privacy\\local\\metadata\\null_provider;/);
  assert.match(source, /public static function get_reason\(\)\s*:\s*string/);
  assert.match(source, /return\s+'privacy:metadata';/);
  // null_provider deklariert bewusst keine gespeicherten Daten: kein
  // metadata\provider und keine export/delete-Personendaten-Behauptung.
  assert.doesNotMatch(source, /implements\s+metadata\\provider/);
  assert.doesNotMatch(source, /add_database_table/);
  assert.doesNotMatch(source, /add_subsystem_link/);
});

test('AC4: privacy:metadata behauptet keine nicht unterstuetzten Lernendendaten', () => {
  const source = fs.readFileSync(LANG_PATH, 'utf8');
  const match = source.match(/\$string\['privacy:metadata'\]\s*=\s*'((?:[^'\\]|\\.)*)';/s);

  assert.ok(match, 'privacy:metadata Lang-String vorhanden');
  const text = match[1].toLowerCase();
  assert.match(text, /does not store any personal data/);
  // Die Erklaerung nennt die Schutzgrenze, ohne zu behaupten, dass solche
  // Daten verarbeitet/gespeichert werden.
  assert.match(text, /learner/);
  assert.doesNotMatch(text, /stores learner/);
  assert.doesNotMatch(text, /collects learner/);
});

// ─────────────────────────────────────────────────────────────
// AC5: Lehrkraft-Dokumentation der Schutzgrenze
// ─────────────────────────────────────────────────────────────

test('AC5: README dokumentiert die Schutzgrenze ohne KI-Anbieter-Aufruf des Plugins', () => {
  const source = fs.readFileSync(README_PATH, 'utf8');

  assert.match(source, /## Datenschutz/);
  // Ausgeschlossene Lernendendaten-Oberflaechen werden benannt.
  assert.match(source, /Aufgabenabgaben/);
  assert.match(source, /Forenbeitr/);
  assert.match(source, /Quizversuch/);
  assert.match(source, /Bewertung/);
  assert.match(source, /Teilnehmendenlisten/);
  // Das Plugin ruft selbst keinen KI-Anbieter auf; die Weitergabe an einen
  // Anbieter entsteht erst durch die Lehrkraft ueber den lokalen KI-Client.
  assert.match(source, /ruft\s+(?:selbst\s+)?keinen?\s+KI-Anbieter\s+auf/i);
  assert.match(source, /Anbieter/);
});
