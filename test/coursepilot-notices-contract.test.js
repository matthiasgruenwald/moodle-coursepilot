'use strict';

/**
 * Vertragstest: Produktoberflaeche, Sprachen und Neuinstallation (Issue #189,
 * Parent #146, Spezifikation docs/specs/0003-coursepilot-marketplace-readiness.md).
 *
 * Coursepilot wird einheitlich als Produktname gefuehrt. Moodle nutzt Englisch
 * als Basissprache und liefert Deutsch nur voruebergehend bis zur AMOS-Pflege.
 * Administrator:innen muessen eine alte local_aicoursecreator-Installation
 * deinstallieren, bevor sie local_coursepilot installieren. Kurspilot nutzt
 * einen lokal konfigurierten KI-Client; das Plugin ruft selbst keinen
 * KI-Anbieter auf und gibt keine Lernendendaten frei.
 *
 * Dieser Test erzwingt:
 *  - AC: Moodle-Strings liegen auf Englisch (Basis) vor und eine vollstaendige
 *        voruebergehende deutsche Uebersetzung deckt dieselben Schluessel ab.
 *  - AC: Die voruebergehende deutsche Auslieferung und die spaetere
 *        AMOS-Uebergabe sind sichtbar dokumentiert (Lang-Datei, README,
 *        Release Notes, Mirror-README).
 *  - AC: README, Release Notes und Mirror-README erklaeren die notwendige
 *        Neuinstallation (local_aicoursecreator deinstallieren) und verweisen
 *        auf Moodle 5.0+ sowie das primaere Repository.
 *  - AC: Dieselben Orte erklaeren den lokal konfigurierten KI-Client und die
 *        ausgeschlossenen Lernendendaten.
 *
 * Prosa wird nicht wortwoertlich getestet - nur das Vorhandensein der
 * tragenden Begriffe (siehe Spezifikation, Abschnitt Testing Decisions).
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const REPO_ROOT = path.join(__dirname, '..');
const PLUGIN_ROOT = path.join(REPO_ROOT, 'legacy', 'local_coursepilot');
const LANG_EN_PATH = path.join(PLUGIN_ROOT, 'lang', 'en', 'local_coursepilot.php');
const LANG_DE_PATH = path.join(PLUGIN_ROOT, 'lang', 'de', 'local_coursepilot.php');
const README_PATH = path.join(REPO_ROOT, 'README.md');
const RELEASE_NOTES_PATH = path.join(REPO_ROOT, 'RELEASE_NOTES.md');
const PLUGIN_README_PATH = path.join(PLUGIN_ROOT, 'README.md');

function read(filePath) {
  return fs.readFileSync(filePath, 'utf8');
}

function langStringKeys(source) {
  return [...source.matchAll(/\$string\['([^']+)'\]\s*=/g)].map(match => match[1]).sort();
}

// ─────────────────────────────────────────────────────────────
// Moodle-Strings: Englisch als Basis + vollstaendiges Deutsch
// ─────────────────────────────────────────────────────────────

test('Englische Lang-Datei ist die Basis mit den erwarteten Schluesseln', () => {
  assert.ok(fs.existsSync(LANG_EN_PATH), 'lang/en/local_coursepilot.php existiert');
  const keys = langStringKeys(read(LANG_EN_PATH));
  for (const expected of ['pluginname', 'privacy:metadata', 'coursepilot:use']) {
    assert.ok(keys.includes(expected), `englischer Lang-Schluessel ${expected} vorhanden`);
  }
});

test('Deutsche Lang-Datei ist vollstaendig: dieselben Schluessel wie Englisch', () => {
  assert.ok(fs.existsSync(LANG_DE_PATH), 'lang/de/local_coursepilot.php existiert');
  const enKeys = langStringKeys(read(LANG_EN_PATH));
  const deKeys = langStringKeys(read(LANG_DE_PATH));
  assert.deepEqual(deKeys, enKeys, 'deutsche Uebersetzung deckt alle englischen Schluessel ab');
});

test('Deutsche Lang-Datei benennt die ausgeschlossenen Lernendendaten', () => {
  const source = read(LANG_DE_PATH);
  const match = source.match(/\$string\['privacy:metadata'\]\s*=\s*'((?:[^'\\]|\\.)*)';/s);
  assert.ok(match, 'deutscher privacy:metadata Lang-String vorhanden');
  const text = match[1];
  assert.match(text, /Aufgabenabgaben/);
  assert.match(text, /Forenbeitr/);
  assert.match(text, /Quizversuch/);
  assert.match(text, /Bewertung/);
  assert.match(text, /Teilnehmendenlisten/);
});

test('Deutsche Lang-Datei ist als voruebergehend bis zur AMOS-Pflege markiert', () => {
  const source = read(LANG_DE_PATH);
  assert.match(source, /AMOS/);
  assert.match(source, /vor[uü]bergehend|vorlaeufig|temporary/i);
});

// ─────────────────────────────────────────────────────────────
// Gemeinsame Hinweis-Buendel in README, Release Notes, Mirror-README
// ─────────────────────────────────────────────────────────────

function assertNeuinstallationHinweis(source, label) {
  assert.match(source, /local_aicoursecreator/, `${label}: alte Komponente benannt`);
  assert.match(source, /local_coursepilot/, `${label}: neue Komponente benannt`);
  assert.match(source, /deinstallier|uninstall/i, `${label}: Deinstallation erklaert`);
}

function assertMoodle5Hinweis(source, label) {
  assert.match(source, /Moodle 5\.0/, `${label}: Moodle 5.0+ benannt`);
}

function assertKiClientHinweis(source, label) {
  assert.match(source, /KI-Client|KI-Anbieter|AI client|AI provider/i, `${label}: KI-Client/Anbieter benannt`);
  assert.match(source, /lokal|local/i, `${label}: lokal konfigurierter KI-Client erklaert`);
}

function assertLernendendatenHinweis(source, label) {
  assert.match(source, /Aufgabenabgaben|assignment\s+submissions/i, `${label}: Aufgabenabgaben ausgeschlossen`);
  assert.match(source, /Forenbeitr|forum\s+posts/i, `${label}: Forenbeitraege ausgeschlossen`);
  assert.match(source, /Quizversuch|quiz\s+attempts/i, `${label}: Quizversuche ausgeschlossen`);
  assert.match(source, /Bewertung|grades/i, `${label}: Bewertungen ausgeschlossen`);
  assert.match(source, /Teilnehmendenlisten|participant\s+lists/i, `${label}: Teilnehmendenlisten ausgeschlossen`);
}

function assertAmosHinweis(source, label) {
  assert.match(source, /AMOS/, `${label}: AMOS-Uebergabe benannt`);
  assert.match(source, /Deutsch|German/i, `${label}: voruebergehende deutsche Auslieferung benannt`);
}

function assertPrimaeresRepository(source, label) {
  assert.match(source, /matthiasgruenwald\/moodle-coursepilot/, `${label}: primaeres Repository verlinkt`);
}

test('README erklaert Neuinstallation, Moodle 5.0+, KI-Client, Lernendendaten-Grenze, AMOS und primaeres Repository', () => {
  const source = read(README_PATH);
  assertNeuinstallationHinweis(source, 'README');
  assertMoodle5Hinweis(source, 'README');
  assertKiClientHinweis(source, 'README');
  assertLernendendatenHinweis(source, 'README');
  assertAmosHinweis(source, 'README');
  assertPrimaeresRepository(source, 'README');
});

test('Release Notes erklaeren Neuinstallation, Moodle 5.0+, KI-Client, Lernendendaten-Grenze und AMOS', () => {
  assert.ok(fs.existsSync(RELEASE_NOTES_PATH), 'RELEASE_NOTES.md existiert');
  const source = read(RELEASE_NOTES_PATH);
  assertNeuinstallationHinweis(source, 'RELEASE_NOTES');
  assertMoodle5Hinweis(source, 'RELEASE_NOTES');
  assertKiClientHinweis(source, 'RELEASE_NOTES');
  assertLernendendatenHinweis(source, 'RELEASE_NOTES');
  assertAmosHinweis(source, 'RELEASE_NOTES');
  assertPrimaeresRepository(source, 'RELEASE_NOTES');
});

test('Mirror-README (Plugin) erklaert Neuinstallation, Moodle 5.0+, KI-Client, Lernendendaten-Grenze, AMOS und primaeres Repository', () => {
  const source = read(PLUGIN_README_PATH);
  assertNeuinstallationHinweis(source, 'Plugin-README');
  assertMoodle5Hinweis(source, 'Plugin-README');
  assertKiClientHinweis(source, 'Plugin-README');
  assertLernendendatenHinweis(source, 'Plugin-README');
  assertAmosHinweis(source, 'Plugin-README');
  assertPrimaeresRepository(source, 'Plugin-README');
});

test('README und Mirror-README behaupten kein Moodle 4.x mehr', () => {
  assert.doesNotMatch(read(README_PATH), /Moodle 4\.[0x]/, 'README nennt kein Moodle 4.x');
  assert.doesNotMatch(read(PLUGIN_README_PATH), /Moodle 4\.[0x]/, 'Plugin-README nennt kein Moodle 4.x');
});
