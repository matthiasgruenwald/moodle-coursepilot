'use strict';

/**
 * Vertragstest: Marketplace-Artefakt und Mirror-Export (Issue #190, Parent #146,
 * Spezifikation docs/specs/0003-coursepilot-marketplace-readiness.md).
 *
 * Ein wiederholbarer Release-Prozess erzeugt das GPL-lizenzierte Moodle-Plugin
 * als installierbares ZIP und als vollstaendigen Quellinhalt fuer den spaeteren
 * schreibgeschuetzten Marketplace-Mirror. Das primaere Repository behaelt den
 * AGPL-lizenzierten MCP, Installer, Skills und Entwicklungsmaterial.
 *
 * Dieser Test erzwingt:
 *  - AC: Der Release-Prozess erzeugt ein Moodle-installierbares Coursepilot-
 *        Plugin-Archiv aus der umbenannten Plugin-Quelle (local_coursepilot).
 *  - AC: Der Mirror-Export hat das Moodle-Plugin als einziges Repository-Root
 *        und schliesst MCP, Installer, Skills, Tests und Entwicklungsdateien aus.
 *  - AC: Plugin-Lizenz ist GPL-3.0-or-later; primaere MCP/Installer-Lizenz bleibt
 *        AGPL-3.0-or-later; die Upstream-MIT-Hinweise bleiben erhalten.
 *  - AC: Automatisierte Artefakt-Pruefungen sichern Root-Layout, Komponenten-
 *        Identitaet, noetiges Lizenzmaterial und ausgeschlossene Inhalte.
 *  - AC: Primaeres README und Mirror-README erklaeren ihre unterschiedlichen
 *        Zwecke und verweisen Nutzer an das primaere Repository.
 */

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const REPO_ROOT = path.join(__dirname, '..');
const BUILD_PLUGIN_SCRIPT = path.join(REPO_ROOT, 'scripts', 'build-plugin.js');
const BUILD_MIRROR_SCRIPT = path.join(REPO_ROOT, 'scripts', 'build-mirror-export.js');
const PLUGIN_SRC = path.join(REPO_ROOT, 'legacy', 'local_coursepilot');

const PRIMARY_LICENSE = path.join(REPO_ROOT, 'LICENSE');
const PRIMARY_NOTICE = path.join(REPO_ROOT, 'NOTICE');
const PRIMARY_README = path.join(REPO_ROOT, 'README.md');
const PLUGIN_LICENSE = path.join(PLUGIN_SRC, 'LICENSE');
const PLUGIN_README = path.join(PLUGIN_SRC, 'README.md');

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

function walkRelative(dir) {
  const found = [];
  const stack = [''];
  while (stack.length) {
    const rel = stack.pop();
    const abs = path.join(dir, rel);
    for (const entry of fs.readdirSync(abs, { withFileTypes: true })) {
      const relChild = rel ? path.join(rel, entry.name) : entry.name;
      if (entry.isDirectory()) {
        stack.push(relChild);
      } else {
        found.push(relChild);
      }
    }
  }
  return found;
}

// ─────────────────────────────────────────────────────────────
// AC: Installierbares Coursepilot-Plugin-Archiv (Release-Prozess)
// ─────────────────────────────────────────────────────────────

test('Release-Prozess erzeugt ein installierbares local_coursepilot-Archiv', { timeout: 60000 }, t => {
  if (!ZIP_AVAILABLE) {
    t.skip('zip/unzip nicht verfuegbar - Archiv-Pruefung wird uebersprungen');
    return;
  }
  const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'coursepilot-plugin-zip-'));
  try {
    execFileSync(process.execPath, [BUILD_PLUGIN_SCRIPT, '--output', outDir], { encoding: 'utf8' });
    const zipPath = path.join(outDir, 'local_coursepilot.zip');
    assert.ok(fs.existsSync(zipPath), 'Plugin-ZIP wird erzeugt');

    const entries = listZipEntries(zipPath);
    // Installierbares Moodle-local-Plugin: version.php liegt unter local_coursepilot/.
    assert.ok(entries.includes('local_coursepilot/version.php'), 'version.php liegt im Plugin-Root des Archivs');
    assert.ok(entries.includes('local_coursepilot/db/services.php'), 'Webservice-Definition ist enthalten');
    assert.ok(entries.includes('local_coursepilot/lang/en/local_coursepilot.php'), 'englische Lang-Datei ist enthalten');
    assert.ok(entries.includes('local_coursepilot/LICENSE'), 'GPL-Lizenzdatei ist im Archiv enthalten');

    for (const entry of entries) {
      assert.doesNotMatch(entry, /\.DS_Store$/, 'keine macOS-Metadaten im Archiv');
    }
  } finally {
    fs.rmSync(outDir, { recursive: true, force: true });
  }
});

// ─────────────────────────────────────────────────────────────
// AC: Mirror-Export hat das Plugin als einziges Repository-Root
// ─────────────────────────────────────────────────────────────

function buildMirror(t) {
  const outDir = fs.mkdtempSync(path.join(os.tmpdir(), 'coursepilot-mirror-'));
  t.after(() => fs.rmSync(outDir, { recursive: true, force: true }));
  execFileSync(process.execPath, [BUILD_MIRROR_SCRIPT, '--output', outDir], { encoding: 'utf8' });
  return outDir;
}

test('Mirror-Export legt das Plugin als Repository-Root ab', { timeout: 60000 }, t => {
  const root = buildMirror(t);

  for (const required of ['version.php', 'README.md', 'LICENSE', 'lib.php']) {
    assert.ok(fs.existsSync(path.join(root, required)), `Mirror-Root enthaelt ${required}`);
  }
  for (const requiredDir of ['classes', 'db', 'lang']) {
    assert.ok(fs.statSync(path.join(root, requiredDir)).isDirectory(), `Mirror-Root enthaelt ${requiredDir}/`);
  }
  assert.ok(fs.existsSync(path.join(root, 'lang', 'en', 'local_coursepilot.php')), 'englische Lang-Datei im Mirror');
  assert.ok(fs.existsSync(path.join(root, 'lang', 'de', 'local_coursepilot.php')), 'deutsche Lang-Datei im Mirror');

  // Das Plugin ist das Root: keine verschachtelte local_coursepilot/-Ebene.
  assert.ok(!fs.existsSync(path.join(root, 'local_coursepilot')), 'kein verschachteltes local_coursepilot/-Verzeichnis');

  const version = fs.readFileSync(path.join(root, 'version.php'), 'utf8');
  assert.match(version, /\$plugin->component\s*=\s*'local_coursepilot';/, 'Komponente im Mirror ist local_coursepilot');
});

test('Mirror-Export schliesst MCP, Installer, Skills, Tests und Entwicklungsdateien aus', { timeout: 60000 }, t => {
  const root = buildMirror(t);

  const forbidden = [
    'moodle-mcp.js', 'package.json', 'setup.sh', 'setup.ps1',
    'CONTEXT.md', 'AGENTS.md', 'CLAUDE.md', 'NOTICE',
    'scripts', 'skills', 'test', 'docs', 'lib', 'dist', 'assets', 'templates', 'node_modules', '.git',
  ];
  for (const name of forbidden) {
    assert.ok(!fs.existsSync(path.join(root, name)), `Mirror darf ${name} nicht enthalten`);
  }

  const files = walkRelative(root);
  for (const rel of files) {
    assert.doesNotMatch(rel, /\.DS_Store$/, 'keine macOS-Metadaten im Mirror');
    assert.doesNotMatch(rel, /moodle-mcp/i, 'kein MCP-Bestandteil im Mirror');
  }
});

// ─────────────────────────────────────────────────────────────
// AC: Lizenz-Split (Plugin GPL, Primaer AGPL, Upstream MIT)
// ─────────────────────────────────────────────────────────────

test('Plugin-Lizenz ist GPL-3.0-or-later', () => {
  assert.ok(fs.existsSync(PLUGIN_LICENSE), 'Plugin-Quelle hat eine LICENSE-Datei');
  const text = fs.readFileSync(PLUGIN_LICENSE, 'utf8');
  assert.match(text, /GNU GENERAL PUBLIC LICENSE/, 'GPL-Lizenztext vorhanden');
  assert.match(text, /Version 3/, 'GPL Version 3 vorhanden');
  assert.doesNotMatch(text, /AFFERO/, 'Plugin-Lizenz ist keine AGPL');

  const version = fs.readFileSync(path.join(PLUGIN_SRC, 'version.php'), 'utf8');
  assert.match(version, /GNU General Public License/, 'version.php verweist auf die GPL');
  assert.match(version, /version 3 of the License, or[\s\S]*?any later version/i, 'version.php erklaert GPL-3.0-or-later');
});

test('Primaere Lizenz bleibt AGPL-3.0-or-later mit Upstream-MIT-Hinweis', () => {
  const license = fs.readFileSync(PRIMARY_LICENSE, 'utf8');
  assert.match(license, /GNU AFFERO GENERAL PUBLIC LICENSE/, 'primaere LICENSE ist AGPL');

  const pkg = JSON.parse(fs.readFileSync(path.join(REPO_ROOT, 'package.json'), 'utf8'));
  assert.strictEqual(pkg.license, 'AGPL-3.0-or-later', 'package.json deklariert AGPL-3.0-or-later');

  const notice = fs.readFileSync(PRIMARY_NOTICE, 'utf8');
  assert.match(notice, /MIT License/, 'Upstream-MIT-Lizenz bleibt erhalten');
  assert.match(notice, /jtuttas/, 'Upstream-Urheber bleibt benannt');
});

test('Mirror-Export liefert die GPL-Lizenzdatei aus', { timeout: 60000 }, t => {
  const root = buildMirror(t);
  const text = fs.readFileSync(path.join(root, 'LICENSE'), 'utf8');
  assert.match(text, /GNU GENERAL PUBLIC LICENSE/, 'Mirror-LICENSE ist GPL');
  assert.match(text, /Version 3/, 'Mirror-LICENSE ist Version 3');
  assert.doesNotMatch(text, /AFFERO/, 'Mirror-LICENSE ist keine AGPL');
});

// ─────────────────────────────────────────────────────────────
// AC: README-Material erklaert die unterschiedlichen Zwecke
// ─────────────────────────────────────────────────────────────

test('Primaeres README verlinkt Primaer- und Mirror-Repository und benennt den MCP-Bestandteil', () => {
  const readme = fs.readFileSync(PRIMARY_README, 'utf8');
  assert.match(readme, /matthiasgruenwald\/moodle-coursepilot/, 'primaeres Repository unter dem veroeffentlichten Namen benannt');
  assert.match(readme, /https:\/\/github\.com\/matthiasgruenwald\/moodle-local_coursepilot/, 'Mirror-Repository verlinkt');
  assert.match(readme, /MCP/i, 'primaerer Zweck: MCP benannt');
  assert.match(readme, /Mirror|Plugin Directory|Marketplace/i, 'Mirror/Marketplace-Zweck benannt');
  assert.match(readme, /GPL-3\.0/, 'GPL-Lizenz des Plugins benannt');
  assert.match(readme, /AGPL-3\.0/, 'AGPL-Lizenz des Primaerprojekts benannt');
});

test('Mirror-README verlinkt das Primaer-Repository und benennt den noetigen lokalen Coursepilot-MCP', () => {
  const readme = fs.readFileSync(PLUGIN_README, 'utf8');
  assert.match(readme, /https:\/\/github\.com\/matthiasgruenwald\/moodle-coursepilot/, 'primaeres Repository fuer Entwicklung/Support verlinkt');
  assert.match(readme, /Coursepilot-MCP|local Coursepilot MCP/i, 'noetiger lokaler MCP-Bestandteil benannt');
  assert.match(readme, /Mirror|Plugin Directory|Marketplace/i, 'Mirror-Zweck benannt');
  assert.match(readme, /GPL-3\.0/, 'GPL-Lizenz im Mirror-README benannt');
  assert.match(readme, /schreibgesch|read-only|read only/i, 'schreibgeschuetzter Charakter benannt');
});
