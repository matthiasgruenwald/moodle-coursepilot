'use strict';

/**
 * Vertragstest: Release-Kandidat der nativen Server-MCP-Linie (Issue #577,
 * Parent #567, Spec 0025 Abschnitt D).
 *
 * Prueft den Release-Weg fuer Coursepilot 2.0 aus
 * Plugin/src/local_coursepilot: AGPL-Lizenz samt Herkunftshinweisen,
 * kanonische Versionsangabe statt eines Prototypwerts und ausschliesslich
 * englische Moodle-Sprachstrings im Paket.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const REPO_ROOT = path.join(__dirname, '..');
const BUILD_SCRIPT = path.join(REPO_ROOT, 'scripts', 'build-native-release.js');
const SRC_DIR = path.join(REPO_ROOT, 'Plugin', 'src', 'local_coursepilot');

function commandAvailable(command) {
  try {
    execFileSync(process.platform === 'win32' ? 'where' : 'which', [command], { stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
}

const ZIP_AVAILABLE = commandAvailable('zip') && commandAvailable('unzip');

function listZipEntries(zipPath) {
  const out = execFileSync('unzip', ['-Z1', zipPath], { encoding: 'utf8' });
  return out.split(/\r?\n/).filter(Boolean);
}

function buildRelease(t) {
  const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'coursepilot-native-release-'));
  t.after(() => fs.rmSync(outDir, { recursive: true, force: true }));
  execFileSync(process.execPath, [BUILD_SCRIPT, '--output', outDir], { encoding: 'utf8' });
  return outDir;
}

test('Release candidate contains native source and an installable ZIP', { timeout: 60000 }, t => {
  if (!ZIP_AVAILABLE) {
    t.skip('zip/unzip nicht verfuegbar - Archiv-Pruefung wird uebersprungen');
    return;
  }
  const outDir = buildRelease(t);
  const staged = path.join(outDir, 'local_coursepilot');
  assert.ok(fs.existsSync(path.join(staged, 'version.php')), 'Quellstand enthaelt version.php');
  assert.ok(fs.existsSync(path.join(staged, 'classes', 'dispatcher.php')), 'Quellstand enthaelt die Laufzeitklassen');

  const version = fs.readFileSync(path.join(staged, 'version.php'), 'utf8');
  const release = version.match(/\$plugin->release\s*=\s*'([^']+)';/)[1];

  const zipPath = path.join(outDir, `local_coursepilot-${release}.zip`);
  assert.ok(fs.existsSync(zipPath), 'ZIP wird mit dem Release-Namen erzeugt');

  const entries = listZipEntries(zipPath);
  assert.ok(entries.includes('local_coursepilot/version.php'), 'version.php liegt im Plugin-Root des Archivs');
  assert.ok(entries.includes('local_coursepilot/db/services.php'), 'Webservice-Definition ist enthalten');
  assert.ok(entries.includes('local_coursepilot/classes/dispatcher.php'), 'MCP-Dispatcher ist enthalten');
  assert.ok(entries.includes('local_coursepilot/LICENSE'), 'Lizenzdatei ist im Archiv enthalten');
  assert.ok(entries.includes('local_coursepilot/NOTICE'), 'Herkunftshinweis ist im Archiv enthalten');

  for (const entry of entries) {
    assert.doesNotMatch(entry, /\.DS_Store$/, 'keine macOS-Metadaten im Archiv');
  }
});

test('Release candidate ships only English Moodle language strings', { timeout: 60000 }, t => {
  const outDir = buildRelease(t);
  const staged = path.join(outDir, 'local_coursepilot');
  assert.ok(fs.existsSync(path.join(staged, 'lang', 'en', 'local_coursepilot.php')), 'englische Lang-Datei ist enthalten');
  assert.ok(!fs.existsSync(path.join(staged, 'lang', 'de')), 'lang/de/ ist im Release-Paket NICHT enthalten');

  // Der deutsche Skill-Korpus ist keine Moodle-Sprachdatei und bleibt bewusst.
  assert.ok(fs.existsSync(path.join(staged, 'skills')), 'Skill-Korpus bleibt im Paket enthalten');
});

test('Release candidate is AGPL-3.0-or-later with attribution', { timeout: 60000 }, t => {
  const outDir = buildRelease(t);
  const staged = path.join(outDir, 'local_coursepilot');

  const license = fs.readFileSync(path.join(staged, 'LICENSE'), 'utf8');
  assert.match(license, /GNU AFFERO GENERAL PUBLIC LICENSE/, 'Release-LICENSE ist AGPL');
  assert.match(license, /Version 3/, 'Release-LICENSE ist Version 3');

  const notice = fs.readFileSync(path.join(staged, 'NOTICE'), 'utf8');
  assert.match(notice, /jtuttas/, 'Herkunftshinweis auf den Upstream bleibt erhalten');
});

test('Plugin and MCP server versions share a canonical source (not 0.1.0)', () => {
  const version = fs.readFileSync(path.join(SRC_DIR, 'version.php'), 'utf8');
  const release = version.match(/\$plugin->release\s*=\s*'([^']+)';/)[1];
  assert.notStrictEqual(release, '0.1.0', 'version.php meldet keinen Prototypwert mehr');

  const dispatcher = fs.readFileSync(path.join(SRC_DIR, 'classes', 'dispatcher.php'), 'utf8');
  assert.doesNotMatch(dispatcher, /'version'\s*=>\s*'0\.1\.0'/, 'Dispatcher meldet keinen festen Prototypwert mehr');
  assert.match(dispatcher, /plugin_release\(\)/, 'Dispatcher liest die Serverversion aus derselben kanonischen Quelle wie version.php');
});

test('Release build validates the license and component', { timeout: 60000 }, t => {
  const outDir = buildRelease(t);
  const staged = path.join(outDir, 'local_coursepilot');
  const version = fs.readFileSync(path.join(staged, 'version.php'), 'utf8');
  assert.match(version, /\$plugin->component\s*=\s*'local_coursepilot';/, 'Komponente im Release-Kandidaten ist local_coursepilot');
});
