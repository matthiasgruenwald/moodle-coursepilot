#!/usr/bin/env node
/**
 * Gate-Kommando (Spec 0029, ADR 0029): Messung und Ratsche. Rot (Exitcode 1) bei jedem
 * Befund der statischen Pruefungen, bei fehlgeschlagenen Tests und bei Ratschenverletzung.
 *
 *   node scripts/gate/gate.js fast            Node-Tests + PHPUnit der Tests zu geaenderten Klassen
 *   node scripts/gate/gate.js full            volle PHPUnit-Suite mit pcov, danach Bericht
 *   node scripts/gate/gate.js report <clover> Bericht aus einem vorhandenen Clover-Bericht (nur Messwerte)
 *   node scripts/gate/gate.js ratchet [clover] Ratsche: Baselines nur besser; mit Clover zusaetzlich Coverage, geaenderte Dateien und Methoden
 *   node scripts/gate/gate.js baseline [clover] Coverage-Baseline anheben (verweigert, wenn der Messwert niedriger ist)
 *   node scripts/gate/gate.js edit            Edit-Hook (Claude/Codex): liest Hook-JSON von stdin, ruft fast fuer Plugin-, Test- und Gate-Dateien
 *   node scripts/gate/gate.js static          statische Pruefungen (moodle-cs, phpdoc, savepoints, Mustache, ESLint, PHPStan, Covers, englische Kommentare, deptrac-Schichtregeln)
 *   node scripts/gate/gate.js phpstan-baseline PHPStan-Baseline neu erzeugen
 *   node scripts/gate/gate.js ranking [n]     Fehlschlags-Rangliste nach Datei und Pruefung aus .gate-failures.log
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

const staticChecks = require('./static');
const failureLog = require('./failure-log');
const ratchet = require('./ratchet');
const deptrac = require('./deptrac');

const { PLUGIN_REL, PLUGIN_IN_CONTAINER, repoPath } = staticChecks;
// GATE_REPO nur fuer Tests: das Repo, dessen Git-Stand und Baselines ausgewertet werden.
const REPO = process.env.GATE_REPO || path.resolve(__dirname, '..', '..');
const BASELINE_REL = 'scripts/gate/baseline.json';
const PHPSTAN_BASELINE_REL = 'scripts/gate/phpstan/phpstan-baseline.neon';
const DEPTRAC_BASELINE_REL = 'scripts/gate/deptrac/deptrac-baseline.yaml';
const PLUGIN_PREFIX = `${PLUGIN_REL}/`;
const CONTAINER = process.env.GATE_CONTAINER || 'kurspilot-gate-webserver-1';
const GATE_DIR = process.env.GATE_DIR || '/opt/kurspilot-gate';
const CLOVER_IN_CONTAINER = '/var/www/reports/clover.xml';
// Regelwerte fuer den Messbericht; blockierend ist die Ratsche (ratchet.js).
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
  if (methods.length === 0) {
    throw new Error('Clover ungueltig: keine Methoden, CRAP waere ungemessen');
  }
  return { total, files, methods };
}

const { pct } = ratchet;

/** Clover-Dateien und -Methoden mit Repo-Pfaden statt Container-Pfaden. */
function repoClover(parsed) {
  return {
    files: parsed.files.map(f => ({ ...f, name: repoPath(f.name) })),
    methods: parsed.methods.map(m => ({ ...m, file: repoPath(m.file) })),
  };
}

/**
 * @param {object} parsed Ergebnis von parseClover
 * @param {{path: string}[]} exclusions Ausschlussliste der Baseline (Pfade relativ zum Plugin)
 * @returns {{lines: string[], summary: object}}
 */
function buildReport(parsed, exclusions = []) {
  const lines = [];
  const all = repoClover(parsed);
  const kept = name => !ratchet.isExcluded(name, exclusions, PLUGIN_PREFIX);
  const fileList = all.files.filter(f => kept(f.name));
  const methodList = all.methods.filter(m => kept(m.file));
  const total = {
    statements: fileList.reduce((a, f) => a + f.statements, 0),
    covered: fileList.reduce((a, f) => a + f.covered, 0),
  };
  const files = [...fileList].sort((a, b) => a.name.localeCompare(b.name));
  for (const f of files) {
    const p = pct(f.covered, f.statements);
    if (f.statements > 0 && p < FILE_COVERAGE_RULE) {
      lines.push(`${f.name}:0: coverage-file: ${p.toFixed(1)}% (${f.covered}/${f.statements}) unter ${FILE_COVERAGE_RULE}%`);
    }
  }
  const methods = [...methodList].sort((a, b) => b.crap - a.crap);
  for (const m of methods) {
    if (m.crap > CRAP_RULE) {
      lines.push(`${m.file}:${m.line}: crap-method: ${m.name} crap=${m.crap} complexity=${m.complexity}`);
    }
  }
  const perFile = files.map(f => ({ file: f.name, coverage: Number(pct(f.covered, f.statements).toFixed(1)), lines: `${f.covered}/${f.statements}` }));
  const perMethod = methods.map(m => ({ file: m.file, line: m.line, name: m.name, complexity: m.complexity, crap: m.crap }));
  const summary = {
    coverage: Number(pct(total.covered, total.statements).toFixed(2)),
    lines: `${total.covered}/${total.statements}`,
    files: fileList.length,
    filesBelow50: fileList.filter(f => f.statements > 0 && pct(f.covered, f.statements) < 50).length,
    methods: methodList.length,
    methodsCrapOver8: methodList.filter(m => m.crap > CRAP_RULE).length,
    methodsCrapOver30: methodList.filter(m => m.crap > CRAP_HIGH).length,
  };
  return { lines, summary, perFile, perMethod };
}

function formatSummary(s) {
  return `summary: coverage=${s.coverage}% lines=${s.lines} files=${s.files} files_below_50=${s.filesBelow50} ` +
    `methods=${s.methods} crap_over_8=${s.methodsCrapOver8} crap_over_30=${s.methodsCrapOver30}`;
}

function loadParsed(cloverPath) {
  if (!cloverPath || !fs.existsSync(cloverPath)) {
    throw new Error(`Clover-Bericht fehlt: ${cloverPath || '(kein Pfad)'}`);
  }
  return parseClover(fs.readFileSync(cloverPath, 'utf8'));
}

/** Versionierte Baseline (Coverage, Ausschlussliste, Startpunkt der Ratsche); fehlt sie, ist das Gate rot. */
function loadBaseline() {
  const file = path.join(REPO, BASELINE_REL);
  if (!fs.existsSync(file)) {
    throw new Error(`Baseline fehlt: ${BASELINE_REL}`);
  }
  const b = JSON.parse(fs.readFileSync(file, 'utf8'));
  if (!b.coverage || !(b.coverage.statements > 0) || !Array.isArray(b.excluded)) {
    throw new Error(`Baseline ungueltig: ${BASELINE_REL} braucht coverage.{covered,statements} und excluded[]`);
  }
  return b;
}

/** Bericht aus Clover-Datei; wirft bei fehlender/ungueltiger Datei. */
function reportFromFile(cloverPath) {
  return buildReport(loadParsed(cloverPath), loadBaseline().excluded);
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

// Im Edit-Hook gehoert die Kindausgabe auf stderr, nur dort liest der Agent sie.
let childStdio = 'inherit';

function sh(cmd, args, opts = {}) {
  return spawnSync(cmd, args, { cwd: REPO, encoding: 'utf8', stdio: opts.inherit ? childStdio : 'pipe', ...opts });
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

function syncPlugin() {
  const sync = sh('bash', [path.join(__dirname, 'sync-plugin.sh')], { env: { ...process.env, GATE_DIR } });
  if (sync.status !== 0) {
    throw new Error(`Plugin-Sync fehlgeschlagen: ${sync.stderr}`);
  }
}

function preparePlugin() {
  syncPlugin();
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

/** Ergebnis einer Pruefung ohne Dateibezug ins Fehlschlagslog. */
function logOutcome(check, ok) {
  failureLog.append([[new Date().toISOString(), check, '-', ok ? 'ok' : 'fail']]);
}

function nodeTests() {
  const ok = sh('npm', ['test', '--silent'], { inherit: true }).status === 0;
  logOutcome('node-tests', ok);
  return ok;
}

function changedFiles() {
  const tracked = sh('git', ['diff', '--name-only', 'HEAD']);
  const fresh = sh('git', ['ls-files', '--others', '--exclude-standard']);
  if (tracked.status !== 0 || fresh.status !== 0) {
    throw new Error(`git-Aufruf fehlgeschlagen: ${tracked.stderr}${fresh.stderr}`);
  }
  return `${tracked.stdout}\n${fresh.stdout}`.split('\n').filter(Boolean);
}

function runFast() {
  let ok = nodeTests();
  const { tests, unmapped } = mapChangedToTests(changedFiles(), staticChecks.readPluginTests(REPO));
  unmapped.forEach(f => console.log(`${f}:0: no-test-mapped: keine Testklasse mit CoversClass fuer diese Datei`));
  requireContainer();
  if (tests.length > 0) {
    preparePlugin();
    const passed = phpunit(['--filter', `/\\b(${tests.join('|')})\\b/`], false).status === 0;
    logOutcome('phpunit', passed);
    ok = passed && ok;
  } else {
    syncPlugin();
  }
  ok = printRatchet() && ok;
  ok = printStatic(staticChecks.FAST_CHECKS) && ok;
  console.log(`summary: mode=fast node=${ok ? 'ok' : 'fail'} phpunit_tests=${tests.length}`);
  return ok;
}

function runFull() {
  requireContainer();
  preparePlugin();
  fs.rmSync(path.join(GATE_DIR, 'reports', 'clover.xml'), { force: true });
  const passed = phpunit(['--coverage-clover', CLOVER_IN_CONTAINER], true).status === 0;
  logOutcome('phpunit', passed);
  if (!passed) {
    console.log('summary: mode=full phpunit=fail');
    return false;
  }
  const clover = path.join(GATE_DIR, 'reports', 'clover.xml');
  printReport(clover, path.join(GATE_DIR, 'reports', 'gate-report.json'), true);
  const ratcheted = printRatchet(clover);
  return printStatic(staticChecks.ALL_CHECKS) && ratcheted;
}

/** Statische Pruefungen: Befunde als Zeilen, vollstaendig als JSON; Werkzeugfehler sind rot. */
function printStatic(names) {
  const report = staticChecks.runStatic(names, { container: CONTAINER, repo: REPO });
  fs.mkdirSync(path.join(GATE_DIR, 'reports'), { recursive: true });
  fs.writeFileSync(path.join(GATE_DIR, 'reports', 'gate-static.json'), JSON.stringify({ summary: report.summary, errors: report.errors, results: report.results }, null, 1));
  report.lines.forEach(l => console.log(l));
  report.errors.forEach(e => console.log(`gate:0: gate-error: ${e}`));
  console.log(report.summary);
  return report.errors.length === 0 && report.blocking === 0;
}

function runStaticOnly() {
  requireContainer();
  syncPlugin();
  return printStatic(staticChecks.ALL_CHECKS);
}

function runPhpstanBaseline() {
  requireContainer();
  syncPlugin();
  staticChecks.generatePhpstanBaseline({ container: CONTAINER, repo: REPO, gateDir: GATE_DIR });
  console.log('summary: phpstan-baseline erzeugt');
  return true;
}

/** `datei:zeile: regel: text`-Zeilen der Messwerte als fail-Eintraege. */
function coverageEntries(lines) {
  const now = new Date().toISOString();
  return lines.map(l => l.match(/^(.+?):\d+: ([\w-]+):/)).filter(Boolean).map(m => [now, m[2], m[1], 'fail']);
}

function printReport(cloverPath, jsonPath, logFailures = false) {
  const { lines, summary, perFile, perMethod } = reportFromFile(cloverPath);
  if (jsonPath) {
    // Vollstaendiger Bericht: Coverage je Datei, CRAP je Methode.
    fs.writeFileSync(jsonPath, JSON.stringify({ summary, files: perFile, methods: perMethod }, null, 1));
  }
  if (logFailures) {
    failureLog.append(coverageEntries(lines));
  }
  lines.forEach(l => console.log(l));
  console.log(formatSummary(summary));
  return true;
}

function git(args) {
  const r = sh('git', args, { maxBuffer: 256 * 1024 * 1024 });
  return r.status === 0 ? r.stdout : null;
}

/** Inhalt einer Datei im Ref oder `null` (Datei oder Ref fehlt). */
const gitShow = (ref, rel) => git(['show', `${ref}:${rel}`]);

const isAncestor = (a, b) => sh('git', ['merge-base', '--is-ancestor', a, b]).status === 0;

/**
 * Vergleichsstand der Ratsche: `GATE_BASE_REF`, sonst der juengere von Merge-Base mit dem
 * Ziel-Branch (`origin/dev`, `dev`) und dem Startpunkt `armedAt` der Baseline, sonst HEAD.
 * Der Startpunkt verhindert, dass das einmalige Aufraeumen vor dem Scharfschalten als
 * "geaendert" gilt; nach dem Merge ist die Merge-Base juenger und gewinnt.
 */
function resolveBase(armedAt) {
  if (process.env.GATE_BASE_REF) {
    return process.env.GATE_BASE_REF;
  }
  const heads = ['origin/dev', 'dev'].map(ref => (git(['merge-base', 'HEAD', ref]) || '').trim()).filter(Boolean);
  const armedCandidate = armedAt && git(['rev-parse', '--verify', `${armedAt}^{commit}`]) && isAncestor(armedAt, 'HEAD') ? [armedAt] : [];
  const candidates = [...heads.slice(0, 1), ...armedCandidate];
  if (candidates.length === 0) {
    return 'HEAD';
  }
  return candidates.reduce((a, b) => (a === b || isAncestor(a, b) ? b : a));
}

/**
 * Aenderungen seit `base` (Arbeitsbaum): geaenderte Zeilen je Datei, neue unversionierte
 * Dateien als `all`, Umbenennungen als Map alt -> neu. Eine unversionierte Datei, deren Inhalt
 * einer geloeschten versionierten gleicht, ist eine Umbenennung und keine neue Datei.
 * @returns {{changed: Map<string, [number, number][]|'all'>, renames: Map<string, string>}}
 */
function changesSince(base) {
  const diff = git(['diff', '-U0', '--no-color', '-M', base, '--']);
  const status = git(['diff', '--name-status', '-M', base, '--']);
  const fresh = git(['ls-files', '--others', '--exclude-standard']);
  if (diff === null || status === null || fresh === null) {
    throw new Error(`git diff gegen ${base} fehlgeschlagen`);
  }
  const changed = ratchet.parseChangedLines(diff);
  const renames = new Map();
  const deleted = new Map();
  for (const row of status.split('\n')) {
    const [code, from, to] = row.split('\t');
    if (code && code[0] === 'R') {
      renames.set(from, to);
    } else if (code === 'D') {
      deleted.set(git(['rev-parse', `${base}:${from}`]), from);
    }
  }
  for (const file of fresh.split('\n').filter(Boolean)) {
    const from = deleted.get(git(['hash-object', '--', file]));
    if (from) {
      renames.set(from, file);
    } else {
      changed.set(file, 'all');
    }
  }
  return { changed, renames };
}

/**
 * Ratsche. Ohne Clover nur die Baselines (schnell, fuer fast); mit Clover zusaetzlich
 * Gesamt-Coverage, geaenderte Dateien (>= 90 %) und geaenderte Methoden (CRAP <= 8).
 * @returns {{lines: string[], ok: boolean}}
 */
function runRatchet(cloverPath) {
  const baseline = loadBaseline();
  const base = resolveBase(baseline.armedAt);
  const found = [];
  const baseBaseline = gitShow(base, BASELINE_REL);
  const phpstanNow = fs.readFileSync(path.join(REPO, PHPSTAN_BASELINE_REL), 'utf8');
  const pairs = text => deptrac.parseBaseline(text).map(e => `${e.from} -> ${e.to}`);
  const deptracNow = fs.readFileSync(path.join(REPO, DEPTRAC_BASELINE_REL), 'utf8');
  const deptracBase = gitShow(base, DEPTRAC_BASELINE_REL);
  const { changed, renames } = changesSince(base);
  // Baseline-Pfade sind Container-relativ (`../html/public/local/coursepilot/...`).
  const baselinePrefix = '../html/public/local/coursepilot/';
  const renamedBaselinePath = p => {
    const to = p.startsWith(baselinePrefix) && renames.get(PLUGIN_PREFIX + p.slice(baselinePrefix.length));
    return to ? baselinePrefix + to.slice(PLUGIN_PREFIX.length) : p;
  };
  found.push(...ratchet.phpstanFindings(phpstanNow, gitShow(base, PHPSTAN_BASELINE_REL), PHPSTAN_BASELINE_REL, renamedBaselinePath));
  found.push(...ratchet.deptracFindings(pairs(deptracNow), deptracBase === null ? null : pairs(deptracBase), DEPTRAC_BASELINE_REL));
  let measuredNote = 'ohne Clover (nur Baselines)';
  const baseCoverage = baseBaseline === null ? null : JSON.parse(baseBaseline).coverage;
  found.push(...ratchet.loweredFindings(baseline.coverage, baseCoverage));
  if (cloverPath) {
    const { files, methods } = repoClover(loadParsed(cloverPath));
    const measured = ratchet.totals(files, baseline.excluded, PLUGIN_PREFIX);
    found.push(...ratchet.coverageFindings(measured, baseline.coverage));
    found.push(...ratchet.changedFindings({
      files,
      methods,
      changed,
      readSource: rel => fs.readFileSync(path.join(REPO, rel), 'utf8').split('\n'),
      exclusions: baseline.excluded,
      pluginPrefix: PLUGIN_PREFIX,
    }));
    measuredNote = `coverage=${pct(measured.covered, measured.statements).toFixed(2)}% baseline=${pct(baseline.coverage.covered, baseline.coverage.statements).toFixed(2)}%`;
  }
  const lines = found.map(ratchet.formatFinding);
  lines.push(`summary: ratchet base=${base.slice(0, 12)} ${measuredNote} violations=${found.length}`);
  return { lines, ok: found.length === 0 };
}

function printRatchet(cloverPath) {
  const r = runRatchet(cloverPath);
  r.lines.forEach(l => console.log(l));
  return r.ok;
}

/** Hebt die Coverage-Baseline an; nie nach unten. */
function runBaseline(cloverPath) {
  const baseline = loadBaseline();
  const measured = ratchet.totals(repoClover(loadParsed(cloverPath)).files, baseline.excluded, PLUGIN_PREFIX);
  if (ratchet.coverageFindings(measured, baseline.coverage).length > 0) {
    throw new Error('Messwert liegt unter der Baseline, die Baseline wird nie gesenkt');
  }
  const next = { ...baseline, coverage: { covered: measured.covered, statements: measured.statements } };
  fs.writeFileSync(path.join(REPO, BASELINE_REL), `${JSON.stringify(next, null, 2)}\n`);
  console.log(`summary: baseline coverage=${pct(measured.covered, measured.statements).toFixed(2)}% lines=${measured.covered}/${measured.statements}`);
  return true;
}

/** Dateien, bei denen ein Edit das Gate ausloest: Plugin-PHP, Node-Tests, Gate-Skripte und -Konfiguration. */
const EDIT_TRIGGER = /^(Plugin\/src\/local_coursepilot\/.*\.php|test\/.*\.js|scripts\/gate\/.*)$/;

/**
 * Edit-Hook fuer Claude (PostToolUse) und Codex: dasselbe Gate wie pre-commit. Befunde auf
 * stderr und Exitcode 2, damit der Agent sie als Rueckmeldung bekommt und weiterarbeitet.
 */
function runEditHook(stdin) {
  let files = [];
  try {
    const input = JSON.parse(stdin).tool_input || {};
    // Claude: file_path; Codex apply_patch: Patch-Text in command.
    files = [input.file_path, ...String(input.command || '').matchAll(/^\*\*\* (?:Add|Update) File: (.+)$/gm)].map(f => (Array.isArray(f) ? f[1] : f));
  } catch (e) {
    // Keine lesbare Hook-Eingabe: kein Edit-Ereignis, nichts zu pruefen (Hook darf nie selbst blockieren).
    return true;
  }
  if (!files.some(f => f && EDIT_TRIGGER.test(path.relative(REPO, path.resolve(REPO, f))))) {
    return true;
  }
  childStdio = ['ignore', 2, 2];
  const out = [];
  const log = console.log;
  console.log = (...a) => out.push(a.join(' '));
  let ok;
  try {
    ok = runFast();
  } catch (e) {
    out.push(`gate:0: gate-error: ${e.message}`);
    ok = false;
  } finally {
    console.log = log;
  }
  if (!ok) {
    process.stderr.write(`${out.filter(l => !/: deptrac-baselined: /.test(l)).join('\n')}\n`);
    process.exitCode = 2;
  }
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
    if (mode === 'static') {
      return runStaticOnly();
    }
    if (mode === 'phpstan-baseline') {
      return runPhpstanBaseline();
    }
    if (mode === 'report') {
      return printReport(arg);
    }
    if (mode === 'ratchet') {
      return printRatchet(arg);
    }
    if (mode === 'baseline') {
      return runBaseline(arg || path.join(GATE_DIR, 'reports', 'clover.xml'));
    }
    if (mode === 'edit') {
      return runEditHook(fs.readFileSync(0, 'utf8'));
    }
    if (mode === 'ranking') {
      console.log(failureLog.report(arg ? Number(arg) : undefined));
      return true;
    }
    console.error('Aufruf: gate.js fast | full | static | phpstan-baseline | report <clover.xml> | ratchet [clover.xml] | baseline [clover.xml] | edit | ranking [anzahl]');
    return false;
  } catch (e) {
    if (mode !== 'ranking') {
      failureLog.logAbort(mode);
    }
    console.log(`gate:0: gate-error: ${e.message}`);
    return false;
  }
}

if (require.main === module) {
  const ok = main(process.argv.slice(2));
  process.exit(process.exitCode || (ok ? 0 : 1));
}

module.exports = { parseClover, buildReport, formatSummary, reportFromFile, mapChangedToTests, runRatchet };
