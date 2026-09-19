'use strict';

/**
 * Vertragstest: End-to-End-Identitaet local_coursepilot (Issue #188, Parent #146,
 * Spezifikation docs/specs/0003-coursepilot-marketplace-readiness.md).
 *
 * Eine frische Moodle-5.0+-Installation nutzt Coursepilot ausschliesslich als
 * local_coursepilot. Plugin-Komponente, PHP-Namensraum, Webservice-Funktionen,
 * Dienst-Shortname, Capability-Namen, MCP-Aufrufe und Build-Artefakt tragen
 * dieselbe neue Identitaet. Eine Kompatibilitaets- oder Datenmigration von
 * local_aicoursecreator gibt es bewusst nicht.
 *
 * Dieser Test erzwingt:
 *  - AC: Plugin-Komponente ist local_coursepilot mit Moodle-5.0-Mindestversion.
 *  - AC: Webservice-Funktionen, Klassennamen und Dienst-Shortname nutzen die
 *        neue Identitaet; keine alte Identitaet in db/services.php.
 *  - AC: Capability und Lang-Datei nutzen die neue Identitaet.
 *  - AC: End-to-End ruft der MCP-Server ausschliesslich local_coursepilot-
 *        Webservices auf, und jeder aufgerufene Dienst ist im Plugin deklariert.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const REPO_ROOT = path.join(__dirname, '..');
const PLUGIN_ROOT = path.join(REPO_ROOT, 'legacy', 'local_coursepilot');
const VERSION_PATH = path.join(PLUGIN_ROOT, 'version.php');
const SERVICES_PATH = path.join(PLUGIN_ROOT, 'db', 'services.php');
const ACCESS_PATH = path.join(PLUGIN_ROOT, 'db', 'access.php');
const LANG_PATH = path.join(PLUGIN_ROOT, 'lang', 'en', 'local_coursepilot.php');
const PRIVACY_PROVIDER_PATH = path.join(PLUGIN_ROOT, 'classes', 'privacy', 'provider.php');
const LIB_DIR = path.join(REPO_ROOT, 'lib');

// Moodle 5.0.0 (Build 20250414, Branch 500) - frische Installationen ab 5.0.
const MOODLE_5_0_VERSION = 2025041400;

test('Plugin-Komponente ist local_coursepilot mit Moodle-5.0-Mindestversion', () => {
  const version = fs.readFileSync(VERSION_PATH, 'utf8');

  assert.match(version, /\$plugin->component\s*=\s*'local_coursepilot';/);
  assert.doesNotMatch(version, /\$plugin->component\s*=\s*'local_aicoursecreator';/);

  const requiresMatch = version.match(/\$plugin->requires\s*=\s*(\d+);/);
  assert.ok(requiresMatch, 'plugin->requires ist gesetzt');
  assert.ok(
    Number(requiresMatch[1]) >= MOODLE_5_0_VERSION,
    `plugin->requires (${requiresMatch[1]}) verlangt mindestens Moodle 5.0 (${MOODLE_5_0_VERSION})`
  );
});

test('Webservice-Funktionen, Klassennamen und Dienst-Shortname nutzen local_coursepilot', () => {
  const services = fs.readFileSync(SERVICES_PATH, 'utf8');

  assert.doesNotMatch(services, /aicoursecreator/i, 'keine alte Identitaet in db/services.php');

  const declared = [...services.matchAll(/'([a-z0-9_]+)'\s*=>\s*\[\s*'classname'/g)].map(m => m[1]);
  assert.ok(declared.length > 0, 'Plugin-Funktionen sind deklariert');
  for (const name of declared) {
    assert.ok(name.startsWith('local_coursepilot_'), `${name} nutzt die neue Identitaet`);
  }

  const classnames = [...services.matchAll(/'classname'\s*=>\s*'([^']+)'/g)].map(m => m[1]);
  assert.ok(classnames.length > 0, 'Klassennamen sind deklariert');
  for (const classname of classnames) {
    assert.ok(classname.startsWith('local_coursepilot\\external\\'), `${classname} nutzt den neuen Namensraum`);
  }

  assert.match(services, /'shortname'\s*=>\s*'coursepilot'/, 'Dienst-Shortname ist coursepilot');
});

test('Capability und Lang-Datei nutzen die neue Identitaet', () => {
  const access = fs.readFileSync(ACCESS_PATH, 'utf8');
  assert.match(access, /'local\/coursepilot:use'\s*=>\s*\[/);
  assert.doesNotMatch(access, /aicoursecreator/i, 'keine alte Identitaet in db/access.php');

  assert.ok(fs.existsSync(LANG_PATH), 'lang/en/local_coursepilot.php existiert');
  const lang = fs.readFileSync(LANG_PATH, 'utf8');
  assert.match(lang, /\$string\['coursepilot:use'\]/, 'Lang-Schluessel coursepilot:use vorhanden');
  assert.doesNotMatch(lang, /aicoursecreator/i, 'keine alte Identitaet in der Lang-Datei');
});

test('Privacy-Provider nutzt den local_coursepilot-Namensraum', () => {
  const source = fs.readFileSync(PRIVACY_PROVIDER_PATH, 'utf8');
  assert.match(source, /namespace\s+local_coursepilot\\privacy;/);
  assert.doesNotMatch(source, /local_aicoursecreator/, 'keine alte Identitaet im Privacy-Provider');
});

test('End-to-End: MCP-Server ruft ausschliesslich local_coursepilot-Webservices auf', () => {
  const executableSources = [];
  for (const file of fs.readdirSync(LIB_DIR)) {
    if (file.endsWith('.js')) {
      executableSources.push([path.join('lib', file), fs.readFileSync(path.join(LIB_DIR, file), 'utf8')]);
    }
  }
  for (const file of fs.readdirSync(REPO_ROOT)) {
    if (/^moodle-mcp.*\.js$/.test(file)) {
      executableSources.push([file, fs.readFileSync(path.join(REPO_ROOT, file), 'utf8')]);
    }
  }

  // Issue #189: lib/setup-render.js enthaelt bewusst einen lehrkraftsichtbaren
  // Migrationshinweis ("deinstallieren Sie local_aicoursecreator"). Das ist
  // UI-Prosa und keine alte Identitaet im Ausfuehrungspfad; die eigentliche
  // End-to-End-Sicherung unten (callMoodle nutzt ausschliesslich
  // local_coursepilot_* und jeder Dienst ist deklariert) bleibt unveraendert.
  const MIGRATION_NOTICE_SOURCES = new Set([path.join('lib', 'setup-render.js')]);

  for (const [label, source] of executableSources) {
    if (MIGRATION_NOTICE_SOURCES.has(label)) continue;
    assert.doesNotMatch(source, /aicoursecreator/i, `keine alte Identitaet in ${label}`);
  }

  const called = new Set();
  for (const [, source] of executableSources) {
    for (const match of source.matchAll(/callMoodle\(\s*["']([a-z0-9_]+)["']/g)) {
      called.add(match[1]);
    }
  }

  const pluginCalls = [...called].filter(name => name.startsWith('local_'));
  assert.ok(pluginCalls.length > 0, 'MCP-Server ruft Plugin-Webservices auf');
  for (const name of pluginCalls) {
    assert.ok(name.startsWith('local_coursepilot_'), `${name} nutzt die neue Identitaet`);
  }

  const services = fs.readFileSync(SERVICES_PATH, 'utf8');
  const declared = new Set(
    [...services.matchAll(/'(local_coursepilot_[a-z0-9_]+)'\s*=>\s*\[\s*'classname'/g)].map(m => m[1])
  );
  for (const name of pluginCalls) {
    assert.ok(declared.has(name), `${name} ist in db/services.php deklariert (End-to-End-Identitaet)`);
  }
});
