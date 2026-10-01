#!/usr/bin/env node
/**
 * Exportiert das Moodle-Plugin als alleiniges Root eines Marketplace-Mirrors.
 *
 * Der Mirror (spaeter: schreibgeschuetztes Repository `moodle-local_coursepilot`
 * fuer das Moodle Plugin Directory) enthaelt ausschliesslich das GPL-lizenzierte
 * Moodle-Plugin als Repository-Root. MCP, Installer, Skills, Tests und sonstige
 * Entwicklungsdateien des primaeren Repositorys erscheinen dort nicht.
 *
 * Optionale Argumente:
 *   --output <verzeichnis>  Zielverzeichnis des Mirror-Roots (Standard: dist/mirror)
 *
 * macOS-Metadaten (.DS_Store) werden vom Export ausgeschlossen.
 */

const fs = require('fs');
const path = require('path');

const PLUGIN_SRC = path.join(__dirname, '..', 'legacy', 'local_coursepilot');
const DEFAULT_OUTPUT = path.join(__dirname, '..', 'dist', 'mirror');

function parseOutputDir(argv) {
  const index = argv.indexOf('--output');
  if (index === -1) {
    return DEFAULT_OUTPUT;
  }
  const value = argv[index + 1];
  if (!value) {
    process.stderr.write('--output erwartet ein Verzeichnis\n');
    process.exit(1);
  }
  return path.resolve(value);
}

function fail(message) {
  process.stderr.write(`Mirror-Export fehlgeschlagen: ${message}\n`);
  process.exit(1);
}

if (!fs.existsSync(path.join(PLUGIN_SRC, 'version.php'))) {
  fail(`Plugin-Quelle fehlt: ${PLUGIN_SRC}`);
}

const outputDir = parseOutputDir(process.argv.slice(2));

fs.rmSync(outputDir, { recursive: true, force: true });
fs.mkdirSync(outputDir, { recursive: true });

fs.cpSync(PLUGIN_SRC, outputDir, {
  recursive: true,
  filter: source => path.basename(source) !== '.DS_Store',
});

const requiredFiles = ['version.php', 'README.md', 'LICENSE', 'lib.php'];
for (const file of requiredFiles) {
  if (!fs.existsSync(path.join(outputDir, file))) {
    fail(`benoetigte Datei fehlt im Mirror-Root: ${file}`);
  }
}

const version = fs.readFileSync(path.join(outputDir, 'version.php'), 'utf8');
if (!/\$plugin->component\s*=\s*'local_coursepilot';/.test(version)) {
  fail('version.php nennt nicht die Komponente local_coursepilot');
}

const license = fs.readFileSync(path.join(outputDir, 'LICENSE'), 'utf8');
if (!/GNU GENERAL PUBLIC LICENSE/.test(license) || !/Version 3/.test(license) || /AFFERO/.test(license)) {
  fail('LICENSE im Mirror ist nicht GPL-3.0');
}

function assertNoJunk(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const abs = path.join(dir, entry.name);
    if (entry.name === '.DS_Store') {
      fail('.DS_Store im Mirror-Export');
    }
    if (entry.isDirectory()) {
      assertNoJunk(abs);
    }
  }
}
assertNoJunk(outputDir);

process.stdout.write(`Mirror-Root erstellt: ${path.relative(process.cwd(), outputDir)}\n`);
process.stdout.write('Inhalt: ausschliesslich das GPL-lizenzierte Moodle-Plugin (local_coursepilot)\n');
