#!/usr/bin/env node
/**
 * Baut das installierbare Release-Kandidat-ZIP und den zugehoerigen
 * Quellstand der nativen Server-MCP-Linie aus Plugin/src/local_coursepilot
 * (Issue #577, Spec 0025 Abschnitt D).
 *
 * Anders als scripts/build-plugin.js (baut aus dem eingefrorenen Altstand
 * legacy/local_coursepilot) ist dies der Release-Weg fuer Coursepilot 2.0.
 * Der Altstand bleibt davon unberuehrt und weiterhin separat benutzbar.
 *
 * Ausgabe unter --output (Standard: dist/native-release/):
 *   local_coursepilot/            Quellstand - genau der Inhalt des ZIPs,
 *                                 ungezippt, fuer eine spaetere
 *                                 Marketplace-Einreichung oder Begutachtung.
 *   local_coursepilot-<release>.zip  Installierbares Moodle-Plugin-Archiv.
 *
 * Nur englische Moodle-Sprachstrings werden ausgeliefert (ADR 0024): das
 * deutsche lang/de/ des Entwicklungsbaums wird nicht mitgezippt, die
 * Uebersetzung folgt nach der Freigabe ueber AMOS. Der deutsche Skill-Korpus
 * (skills/) ist keine Moodle-Sprachdatei und bleibt enthalten.
 */

const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const REPO_ROOT = path.join(__dirname, '..');
const SRC_DIR = path.join(REPO_ROOT, 'Plugin', 'src', 'local_coursepilot');
const ROOT_NOTICE = path.join(REPO_ROOT, 'NOTICE');

function fail(message) {
  process.stderr.write(`Native Release-Build fehlgeschlagen: ${message}\n`);
  process.exit(1);
}

function parseOutputDir(argv) {
  const index = argv.indexOf('--output');
  if (index === -1) {
    return path.join(REPO_ROOT, 'dist', 'native-release');
  }
  const value = argv[index + 1];
  if (!value) {
    fail('--output erwartet ein Verzeichnis');
  }
  return path.resolve(value);
}

function readPluginMeta(versionPhpPath) {
  const text = fs.readFileSync(versionPhpPath, 'utf8');
  const component = text.match(/\$plugin->component\s*=\s*'([^']+)';/);
  const version = text.match(/\$plugin->version\s*=\s*(\d+);/);
  const release = text.match(/\$plugin->release\s*=\s*'([^']+)';/);
  if (!component || !version || !release) {
    fail(`version.php ohne component/version/release: ${versionPhpPath}`);
  }
  return { component: component[1], version: version[1], release: release[1] };
}

if (!fs.existsSync(path.join(SRC_DIR, 'version.php'))) {
  fail(`Quellverzeichnis fehlt: ${SRC_DIR}`);
}

const outputDir = parseOutputDir(process.argv.slice(2));
const stagingDir = path.join(outputDir, 'local_coursepilot');

fs.rmSync(outputDir, { recursive: true, force: true });
fs.mkdirSync(outputDir, { recursive: true });

// Quellstand: Kopie des nativen Plugin-Baums, ohne macOS-Metadaten und ohne
// die deutsche Moodle-Sprachdatei (nur lang/en/ wird ausgeliefert).
fs.cpSync(SRC_DIR, stagingDir, {
  recursive: true,
  filter: source => {
    if (path.basename(source) === '.DS_Store') {
      return false;
    }
    const rel = path.relative(SRC_DIR, source);
    if (rel === path.join('lang', 'de')) {
      return false;
    }
    return true;
  },
});

// Herkunftshinweis (AGPL + Upstream-MIT auf jtuttas/MoodleMcp) reist mit dem
// Artefakt, nicht nur im primaeren Repository.
fs.copyFileSync(ROOT_NOTICE, path.join(stagingDir, 'NOTICE'));

const meta = readPluginMeta(path.join(stagingDir, 'version.php'));
if (meta.component !== 'local_coursepilot') {
  fail(`version.php nennt nicht die Komponente local_coursepilot: ${meta.component}`);
}

const license = fs.readFileSync(path.join(stagingDir, 'LICENSE'), 'utf8');
if (!/AFFERO/.test(license) || !/Version 3/.test(license)) {
  fail('LICENSE im Release-Kandidaten ist nicht AGPL-3.0');
}

if (fs.existsSync(path.join(stagingDir, 'lang', 'de'))) {
  fail('lang/de/ ist im Release-Paket enthalten - nur lang/en/ darf ausgeliefert werden');
}
if (!fs.existsSync(path.join(stagingDir, 'lang', 'en', 'local_coursepilot.php'))) {
  fail('lang/en/local_coursepilot.php fehlt im Release-Paket');
}

const zipPath = path.join(outputDir, `local_coursepilot-${meta.release}.zip`);
execFileSync('zip', ['-r', '-X', zipPath, 'local_coursepilot', '-x', '*.DS_Store'], {
  cwd: outputDir,
  stdio: 'inherit',
});

process.stdout.write(`Quellstand: ${path.relative(process.cwd(), stagingDir)}\n`);
process.stdout.write(`ZIP: ${path.relative(process.cwd(), zipPath)}\n`);
process.stdout.write(`Plugin-Release: ${meta.release} (Version ${meta.version})\n`);
