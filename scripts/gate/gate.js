#!/usr/bin/env node
/**
 * Gate-Kommando (Spec 0029, #660). Nur Messung: noch keine Schwelle blockiert.
 *
 *   node scripts/gate/gate.js fast            Node-Tests + PHPUnit der Tests zu geaenderten Klassen
 *   node scripts/gate/gate.js full            volle PHPUnit-Suite mit pcov, danach Bericht
 *   node scripts/gate/gate.js report <clover> Bericht aus einem vorhandenen Clover-Bericht
 *
 * PHP laeuft per `docker exec` im Gate-Container (scripts/gate/setup-container.sh).
 * Ausgabe: eine Zeile pro Befund im Format `datei:zeile: regel: text`, zuletzt
 * eine `summary:`-Zeile. Exitcode 1 bei fehlendem/ungueltigem Bericht oder
 * fehlgeschlagenen Tests, nie gruen bei Fehlern. Keine npm-Abhaengigkeiten.
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const REPO = path.resolve(__dirname, '..', '..');
const PLUGIN_REL = 'Plugin/src/local_coursepilot';
const CONTAINER = process.env.GATE_CONTAINER || 'kurspilot-gate-webserver-1';
const GATE_DIR = process.env.GATE_DIR || '/opt/kurspilot-gate';
const PLUGIN_IN_CONTAINER = '/var/www/html/public/local/coursepilot';
const CLOVER_IN_CONTAINER = '/var/www/reports/clover.xml';
// Nur Messwerte fuer die Regelnamen im Bericht; blockieren nichts (ADR 0029).
const FILE_COVERAGE_RULE = 90;
const CRAP_RULE = 8;
const CRAP_HIGH = 30;

function attrs(tag) {
  const out = {};
  for (const m of tag.matchAll(/([\w:-]+)="([^"]*)"/g)) {
    out[m[1]] = m[2];
  }
  return out;
}

function num(value, what) {
  const n = Number(value);
  if (value === undefined || value === '' || !Number.isFinite(n)) {
    throw new Error(`Clover ungueltig: ${what} fehlt oder ist keine Zahl`);
  }
  return n;
}

/**
 * @param {string} xml Clover-Bericht
 * @returns {{total: {statements: number, covered: number}, files: object[], methods: object[]}}
 * @throws {Error} bei leerem oder ungueltigem Bericht
 */
function parseClover(xml) {
  if (!xml || xml.trim() === '') {
    throw new Error('Clover-Bericht ist leer');
  }
  const project = xml.match(/<metrics\s+([^>]*)\/>\s*<\/project>/);
  if (!project) {
    throw new Error('Clover ungueltig: keine Projektsumme (<metrics> vor </project>)');
  }
  const pm = attrs(project[1]);
  const total = { statements: num(pm.statements, 'statements'), covered: num(pm.coveredstatements, 'coveredstatements') };
  if (total.statements <= 0) {
    throw new Error('Clover ungueltig: 0 ausfuehrbare Zeilen');
  }
  const files = [];
  const methods = [];
  for (const block of xml.matchAll(/<file name="([^"]+)">([\s\S]*?)<\/file>/g)) {
    const [, name, body] = block;
    const metricTags = [...body.matchAll(/<metrics\s+([^>]*)\/>/g)];
    if (metricTags.length === 0) {
      throw new Error(`Clover ungueltig: Datei ohne <metrics>: ${name}`);
    }
    const fm = attrs(metricTags[metricTags.length - 1][1]);
    files.push({ name, statements: num(fm.statements, `statements in ${name}`), covered: num(fm.coveredstatements, `coveredstatements in ${name}`) });
    for (const line of body.matchAll(/<line\s+([^>]*type="method"[^>]*)\/>/g)) {
      const a = attrs(line[1]);
      methods.push({
        file: name,
        line: num(a.num, `num in ${name}`),
        name: a.name,
        complexity: num(a.complexity, `complexity von ${a.name}`),
        crap: num(a.crap, `crap von ${a.name}`),
      });
    }
  }
  if (files.length === 0) {
    throw new Error('Clover ungueltig: keine <file>-Eintraege');
  }
  return { total, files, methods };
}

const pct = (covered, statements) => (statements === 0 ? 100 : (covered / statements) * 100);

/** Pfad im Container -> Pfad im Repo (fuer Datei:Zeile-Ausgabe). */
function repoPath(file) {
  return file.startsWith(PLUGIN_IN_CONTAINER) ? PLUGIN_REL + file.slice(PLUGIN_IN_CONTAINER.length) : file;
}

/** @returns {{lines: string[], summary: object}} */
function buildReport(parsed) {
  const lines = [];
  const files = [...parsed.files].sort((a, b) => a.name.localeCompare(b.name));
  for (const f of files) {
    const p = pct(f.covered, f.statements);
    if (f.statements > 0 && p < FILE_COVERAGE_RULE) {
      lines.push(`${repoPath(f.name)}:0: coverage-file: ${p.toFixed(1)}% (${f.covered}/${f.statements}) unter ${FILE_COVERAGE_RULE}%`);
    }
  }
  const methods = [...parsed.methods].sort((a, b) => b.crap - a.crap);
  for (const m of methods) {
    if (m.crap > CRAP_RULE) {
      lines.push(`${repoPath(m.file)}:${m.line}: crap-method: ${m.name} crap=${m.crap} complexity=${m.complexity}`);
    }
  }
  const summary = {
    coverage: Number(pct(parsed.total.covered, parsed.total.statements).toFixed(2)),
    lines: `${parsed.total.covered}/${parsed.total.statements}`,
    files: parsed.files.length,
    filesBelow50: parsed.files.filter(f => f.statements > 0 && pct(f.covered, f.statements) < 50).length,
    methods: parsed.methods.length,
    methodsCrapOver8: parsed.methods.filter(m => m.crap > CRAP_RULE).length,
    methodsCrapOver30: parsed.methods.filter(m => m.crap > CRAP_HIGH).length,
  };
  return { lines, summary };
}

function formatSummary(s) {
  return `summary: coverage=${s.coverage}% lines=${s.lines} files=${s.files} files_below_50=${s.filesBelow50} ` +
    `methods=${s.methods} crap_over_8=${s.methodsCrapOver8} crap_over_30=${s.methodsCrapOver30}`;
}

/** Bericht aus Clover-Datei; wirft bei fehlender/ungueltiger Datei. */
function reportFromFile(cloverPath) {
  if (!cloverPath || !fs.existsSync(cloverPath)) {
    throw new Error(`Clover-Bericht fehlt: ${cloverPath || '(kein Pfad)'}`);
  }
  return buildReport(parseClover(fs.readFileSync(cloverPath, 'utf8')));
}

/**
 * Welche Testdateien gehoeren zu den geaenderten Dateien? Eine geaenderte
 * Produktionsklasse `classes/.../foo.php` gehoert zu Tests mit
 * `#[CoversClass(...foo::class)]`; eine geaenderte Testdatei steht fuer sich.
 * @param {string[]} changed Repo-relative Pfade
 * @param {{file: string, content: string}[]} tests Testdateien (Pfad unter Plugin/)
 * @returns {{tests: string[], unmapped: string[]}}
 */
function mapChangedToTests(changed, tests) {
  const picked = new Set();
  const unmapped = [];
  const prefix = `${PLUGIN_REL}/`;
  for (const rel of changed.filter(f => f.startsWith(prefix) && f.endsWith('.php'))) {
    const inner = rel.slice(prefix.length);
    if (inner.startsWith('tests/')) {
      if (inner.endsWith('_test.php')) {
        picked.add(path.basename(inner, '.php'));
      }
      continue;
    }
    const short = path.basename(inner, '.php');
    const hits = tests.filter(t => new RegExp(`CoversClass\\([^)]*\\b${short}::class`).test(t.content));
    if (hits.length === 0) {
      unmapped.push(rel);
    }
    hits.forEach(t => picked.add(path.basename(t.file, '.php')));
  }
  return { tests: [...picked].sort(), unmapped };
}

function sh(cmd, args, opts = {}) {
  return spawnSync(cmd, args, { cwd: REPO, encoding: 'utf8', stdio: opts.inherit ? 'inherit' : 'pipe', ...opts });
}

function dexec(args, opts = {}) {
  return sh('docker', ['exec', '-w', `${PLUGIN_IN_CONTAINER}`, CONTAINER, ...args], opts);
}

function requireContainer() {
  const r = sh('docker', ['inspect', '-f', '{{.State.Running}}', CONTAINER]);
  if (r.status !== 0 || r.stdout.trim() !== 'true') {
    throw new Error(`Gate-Container ${CONTAINER} laeuft nicht: bash scripts/gate/setup-container.sh`);
  }
}

function preparePlugin() {
  const sync = sh('bash', [path.join(__dirname, 'sync-plugin.sh')], { env: { ...process.env, GATE_DIR } });
  if (sync.status !== 0) {
    throw new Error(`Plugin-Sync fehlgeschlagen: ${sync.stderr}`);
  }
  const cfg = sh('docker', ['exec', '-w', '/var/www/html', CONTAINER, 'php', 'public/admin/tool/phpunit/cli/util.php', '--buildcomponentconfigs']);
  if (cfg.status !== 0) {
    throw new Error(`PHPUnit-Konfiguration nicht erzeugt: ${cfg.stdout}${cfg.stderr}`);
  }
}

function phpunit(extra, coverage) {
  const php = ['php', '-d', 'memory_limit=-1'];
  if (coverage) {
    php.push('-d', 'pcov.enabled=1', '-d', `pcov.directory=${PLUGIN_IN_CONTAINER}`);
  }
  return dexec([...php, '/var/www/html/vendor/bin/phpunit', '-c', 'phpunit.xml', ...extra], { inherit: true });
}

function nodeTests() {
  return sh('npm', ['test', '--silent'], { inherit: true }).status === 0;
}

function changedFiles() {
  const tracked = sh('git', ['diff', '--name-only', 'HEAD']).stdout;
  const fresh = sh('git', ['ls-files', '--others', '--exclude-standard']).stdout;
  return `${tracked}\n${fresh}`.split('\n').filter(Boolean);
}

function readTests() {
  const dir = path.join(REPO, PLUGIN_REL, 'tests');
  const out = [];
  const walk = d => fs.readdirSync(d, { withFileTypes: true }).forEach(e => {
    const p = path.join(d, e.name);
    if (e.isDirectory()) {
      walk(p);
    } else if (e.name.endsWith('_test.php')) {
      out.push({ file: p, content: fs.readFileSync(p, 'utf8') });
    }
  });
  walk(dir);
  return out;
}

function runFast() {
  let ok = nodeTests();
  const { tests, unmapped } = mapChangedToTests(changedFiles(), readTests());
  unmapped.forEach(f => console.log(`${f}:0: no-test-mapped: keine Testklasse mit CoversClass fuer diese Datei`));
  if (tests.length > 0) {
    requireContainer();
    preparePlugin();
    ok = phpunit(['--filter', `/\\b(${tests.join('|')})\\b/`], false).status === 0 && ok;
  }
  console.log(`summary: mode=fast node=${ok ? 'ok' : 'fail'} phpunit_tests=${tests.length}`);
  return ok;
}

function runFull() {
  requireContainer();
  preparePlugin();
  fs.rmSync(path.join(GATE_DIR, 'reports', 'clover.xml'), { force: true });
  if (phpunit(['--coverage-clover', CLOVER_IN_CONTAINER], true).status !== 0) {
    console.log('summary: mode=full phpunit=fail');
    return false;
  }
  return printReport(path.join(GATE_DIR, 'reports', 'clover.xml'));
}

function printReport(cloverPath) {
  const { lines, summary } = reportFromFile(cloverPath);
  lines.forEach(l => console.log(l));
  console.log(formatSummary(summary));
  return true;
}

function main(argv) {
  const [mode, arg] = argv;
  try {
    if (mode === 'fast') {
      return runFast();
    }
    if (mode === 'full') {
      return runFull();
    }
    if (mode === 'report') {
      return printReport(arg);
    }
    console.error('Aufruf: gate.js fast | full | report <clover.xml>');
    return false;
  } catch (e) {
    console.log(`gate:0: gate-error: ${e.message}`);
    return false;
  }
}

if (require.main === module) {
  process.exit(main(process.argv.slice(2)) ? 0 : 1);
}

module.exports = { parseClover, buildReport, formatSummary, reportFromFile, mapChangedToTests };
