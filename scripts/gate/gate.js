#!/usr/bin/env node
/**
 * Gate-Kommando (Spec 0029, ADR 0029): Messung und Ratsche. Rot (Exitcode 1) bei jedem
 * Befund der statischen Pruefungen, bei fehlgeschlagenen Tests und bei Ratschenverletzung.
 *
 *   node scripts/gate/gate.js fast            Node-Tests + PHPUnit der Tests zu geaenderten Klassen
 *   node scripts/gate/gate.js full            Node-Tests + volle PHPUnit-Suite mit pcov, danach Bericht
 *   node scripts/gate/gate.js report <clover> Bericht aus einem vorhandenen Clover-Bericht (nur Messwerte)
 *   node scripts/gate/gate.js ratchet [clover] Ratsche: Baselines nur besser; mit Clover zusaetzlich Coverage, geaenderte Dateien und Methoden
 *   node scripts/gate/gate.js baseline [clover] Coverage-Baseline anheben (verweigert, wenn der Messwert niedriger ist)
 *   node scripts/gate/gate.js edit            Edit-Hook (Claude/Codex): liest Hook-JSON von stdin, ruft fast fuer Plugin-, Test- und Gate-Dateien
 *   node scripts/gate/gate.js static          statische Pruefungen (moodle-cs, phpdoc, savepoints, Mustache, ESLint, PHPStan, Covers, englische Kommentare, deptrac-Schichtregeln)
 *   node scripts/gate/gate.js phpstan-baseline PHPStan-Baseline neu erzeugen
 *   node scripts/gate/gate.js ranking [n]     Fehlschlags-Rangliste nach Datei und Pruefung aus .gate-failures.log
 *   node scripts/gate/gate.js mutation        Infection auf geänderten Produktionszeilen
 *   node scripts/gate/gate.js mutation-pilot <classes/dir> Infection-Pilot auf einem Verzeichnis (Trend)
 *   node scripts/gate/gate.js mutation-report <json> vorhandenen Infection-Bericht gegen den Git-Diff prüfen
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
const crypto = require('node:crypto');

const staticChecks = require('./static');
const failureLog = require('./failure-log');
const ratchet = require('./ratchet');
const deptrac = require('./deptrac');
const mutation = require('./mutation');
const hostLoad = require('./host-load');

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
  if (value === undefined || value.trim() === '' || !Number.isFinite(n) || n < 0) {
    throw new Error(`Clover ungueltig: ${what} fehlt oder ist keine nichtnegative Zahl`);
  }
  return n;
}

/** Coverage counts must describe a possible measurement. */
function coverageMetrics(metrics, where) {
  const statements = num(metrics.statements, `statements ${where}`);
  const covered = num(metrics.coveredstatements, `coveredstatements ${where}`);
  if (covered > statements) {
    throw new Error(`Clover ungueltig: coveredstatements > statements ${where}`);
  }
  return { statements, covered };
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
  const total = coverageMetrics(pm, 'im Projekt');
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
    files.push({ name, ...coverageMetrics(fm, `in ${name}`) });
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
  return parseBaseline(fs.readFileSync(file, 'utf8'));
}

/** Reject impossible coverage counts in both working and target baselines. */
function parseBaseline(text) {
  const b = JSON.parse(text);
  if (!Number.isInteger(b.coverage?.covered) || !Number.isInteger(b.coverage?.statements)
      || b.coverage.covered < 0 || b.coverage.statements <= 0 || b.coverage.covered > b.coverage.statements
      || !Array.isArray(b.excluded)) {
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
 * @param {string[]} interfaces Changed interface paths, tested via adapter contract groups.
 * @returns {{tests: string[], unmapped: string[]}}
 */
function mapChangedToTests(changed, tests, interfaces = []) {
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
    // Interfaces have no executable coverage target; adapters declare their contract group.
    const target = interfaces.includes(rel)
      ? new RegExp(`\\bGroup\\(['"]${short}['"]\\)`)
      : new RegExp(`CoversClass\\([^)]*\\b${short}::class`);
    const hits = tests.filter(t => target.test(t.content));
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
  const changed = changedFiles();
  const interfaces = changed.filter(rel => {
    if (!rel.startsWith(`${PLUGIN_PREFIX}classes/`) || !rel.endsWith('.php')) return false;
    const file = path.join(REPO, rel);
    return fs.existsSync(file) && new RegExp(`^interface\\s+${path.basename(rel, '.php')}\\b`, 'm')
      .test(fs.readFileSync(file, 'utf8'));
  });
  const { tests, unmapped } = mapChangedToTests(changed, staticChecks.readPluginTests(REPO), interfaces);
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
  hostLoad.assertHostCapacity();
  const nodePassed = nodeTests();
  requireContainer();
  preparePlugin();
  fs.rmSync(path.join(GATE_DIR, 'reports', 'clover.xml'), { force: true });
  const coverageDir = path.join(GATE_DIR, 'reports', 'infection-coverage');
  fs.rmSync(coverageDir, { recursive: true, force: true });
  fs.mkdirSync(coverageDir, { recursive: true });
  const passed = phpunit(['--coverage-clover', CLOVER_IN_CONTAINER,
    '--coverage-xml', '/var/www/reports/infection-coverage/coverage-xml',
    '--log-junit', '/var/www/reports/infection-coverage/junit.xml'], true).status === 0;
  logOutcome('phpunit', passed);
  if (!passed) {
    console.log('summary: mode=full phpunit=fail');
  }
  const clover = path.join(GATE_DIR, 'reports', 'clover.xml');
  printReport(clover, path.join(GATE_DIR, 'reports', 'gate-report.json'), true);
  const ratcheted = printRatchet(clover);
  const staticPassed = printStatic(staticChecks.ALL_CHECKS);
  const mutated = passed && runMutation(undefined, true);
  return staticPassed && ratcheted && nodePassed && passed && mutated;
}

/** Execute real Infection; the host supplies its authoritative Git diff to the mount. */
function runMutation(pilotDirectory, reuseCoverage = false) {
  const ignores = mutation.parseIgnores(fs.readFileSync(path.join(REPO, 'scripts/gate/mutation-ignore.json'), 'utf8'));
  const baseline = loadBaseline();
  const { base, changed } = mutationChanges();
  let files;
  if (pilotDirectory) {
    const directory = path.join(REPO, PLUGIN_REL, pilotDirectory);
    if (!pilotDirectory.startsWith('classes/') || pilotDirectory.includes('..') || !fs.statSync(directory).isDirectory()) {
      throw new Error('Pilot requires a production directory below classes/');
    }
    files = fs.readdirSync(directory, { recursive: true, withFileTypes: true })
      .filter(e => e.isFile() && e.name.endsWith('.php'))
      .map(e => path.relative(REPO, path.join(e.parentPath, e.name)));
  } else {
    files = [...changed.keys()].filter(f => f.startsWith(PLUGIN_PREFIX) && f.endsWith('.php')
      && !f.startsWith(`${PLUGIN_PREFIX}tests/`) && fs.existsSync(path.join(REPO, f))
      && !ratchet.isExcluded(f, baseline.excluded, PLUGIN_PREFIX));
  }
  fs.mkdirSync(path.join(GATE_DIR, 'reports'), { recursive: true });
  if (files.length === 0) {
    logOutcome('mutation', true);
    console.log('summary: mutation no changed production PHP');
    return true;
  }
  requireContainer();
  if (!reuseCoverage) preparePlugin();
  const prefix = pilotDirectory ? 'infection-pilot' : 'infection';
  const hostReport = path.join(GATE_DIR, 'reports', `${prefix}.json`);
  fs.rmSync(hostReport, { force: true });
  const environmentPath = path.join(GATE_DIR, 'reports', `${prefix}-environment.json`);
  fs.rmSync(environmentPath, { force: true });
  let environment = null;
  if (ignores.some(e => e.qtypeSourceHash !== undefined)) {
    fs.copyFileSync(path.join(__dirname, 'infection-environment.php'), path.join(GATE_DIR, 'reports', 'infection-environment.php'));
    const captured = dexec(['php', '/var/www/reports/infection-environment.php', '/var/www/html/public']);
    if (captured.status !== 0) throw new Error(`Infection environment capture failed: ${captured.stderr}`);
    fs.writeFileSync(environmentPath, captured.stdout);
    environment = mutation.parseEnvironment(captured.stdout);
  }
  const dependencies = ignores.flatMap(e => Object.keys(e.sourceHashes || {}));
  const sourceHashes = Object.fromEntries([...new Set([...files, ...dependencies, 'composer.lock'])]
    .map(f => [f, crypto.createHash('sha256').update(fs.readFileSync(path.join(REPO, f))).digest('hex')]));
  fs.writeFileSync(path.join(GATE_DIR, 'reports', `${prefix}-sources.json`), JSON.stringify(sourceHashes, null, 2));
  const config = {
    source: { directories: [PLUGIN_IN_CONTAINER], excludes: ['tests', ...baseline.excluded.map(e => e.path)] },
    phpUnit: { configDir: PLUGIN_IN_CONTAINER, customPath: '/var/www/html/vendor/bin/phpunit' },
    // The measured full suite takes 17 minutes; broad covering sets must actually run.
    timeout: 1800,
    bootstrap: '/var/www/reports/infection-bootstrap.php',
    logs: { json: `/var/www/reports/${prefix}.json`, text: `/var/www/reports/${prefix}.log` },
    tmpDir: `/var/www/reports/${prefix}-tmp`,
    mutators: { '@default': true },
  };
  fs.writeFileSync(path.join(GATE_DIR, 'reports', `${prefix}-config.json`), JSON.stringify(config, null, 2));
  fs.copyFileSync(path.join(__dirname, 'infection-bootstrap.php'), path.join(GATE_DIR, 'reports', 'infection-bootstrap.php'));
  const args = [`--configuration=/var/www/reports/${prefix}-config.json`, '--threads=1',
    '--only-covering-test-cases', '--no-progress', '--log-verbosity=all', '--debug',
    `--filter=${files.map(f => f.slice(PLUGIN_PREFIX.length)).join(',')}`];
  if (reuseCoverage) {
    args.push('--coverage=/var/www/reports/infection-coverage', '--skip-initial-tests');
  } else {
    args.push(`--initial-tests-php-options=-d memory_limit=-1 -d pcov.enabled=1 -d pcov.directory=${PLUGIN_IN_CONTAINER}`);
    const mapped = mapChangedToTests(files, staticChecks.readPluginTests(REPO));
    if (mapped.unmapped.length === 0 && mapped.tests.length > 0) {
      args.push(`--test-framework-options=--filter=/\\b(${mapped.tests.join('|')})\\b/`);
    }
  }
  const dockerArgs = ['exec', '-w', PLUGIN_IN_CONTAINER];
  if (!pilotDirectory) {
    const headers = [];
    for (const file of files) {
      const inner = file.slice(PLUGIN_PREFIX.length);
      const ranges = changed.get(file);
      const spans = ranges === 'all' ? [[1, fs.readFileSync(path.join(REPO, file), 'utf8').split('\n').length]] : ranges;
      headers.push(`diff --git a/${inner} b/${inner}`, ...spans.map(([a, b]) => `@@ -0,0 +${a},${b - a + 1} @@`));
    }
    fs.writeFileSync(path.join(GATE_DIR, 'reports', 'infection.diff'), headers.join('\n') + '\n');
    fs.writeFileSync(path.join(GATE_DIR, 'reports', 'infection-files.txt'), files.map(f => f.slice(PLUGIN_PREFIX.length)).join('\n') + '\n');
    const bin = path.join(GATE_DIR, 'reports', 'infection-bin');
    fs.mkdirSync(bin, { recursive: true });
    fs.copyFileSync(path.join(__dirname, 'infection-git.sh'), path.join(bin, 'git'));
    fs.chmodSync(path.join(bin, 'git'), 0o755);
    dockerArgs.push('-e', 'PATH=/var/www/reports/infection-bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', '-e', `GATE_MUTATION_BASE=${base}`);
    args.push('--git-diff-lines', `--git-diff-base=${base}`);
  }
  const started = Date.now();
  const result = sh('docker', [...dockerArgs, CONTAINER, 'php', '-d', 'memory_limit=-1', '/opt/dev-tools/vendor/bin/infection', ...args], { inherit: true });
  const elapsedSeconds = (Date.now() - started) / 1000;
  fs.writeFileSync(path.join(GATE_DIR, 'reports', `${prefix}.exit`), `${result.status}\n`);
  fs.writeFileSync(path.join(GATE_DIR, 'reports', `${prefix}-timer.json`), JSON.stringify({ elapsedSeconds, exit: result.status, base, files, args }, null, 2));
  if (result.status !== 0) throw new Error(`Infection failed: exit ${result.status}`);
  const report = mutation.parseReport(fs.readFileSync(hostReport, 'utf8'));
  const found = mutation.evaluate(report, pilotDirectory ? new Map() : changed, ignores, !pilotDirectory, environment, sourceHashes);
  found.map(ratchet.formatFinding).forEach(line => console.log(line));
  failureLog.append(found.map(f => [new Date().toISOString(), f.rule, f.file, 'fail']));
  logOutcome('mutation', found.length === 0);
  console.log(`summary: mutation total=${report.stats.totalMutantsCount} escaped=${report.stats.escapedCount} errors=${report.stats.errorCount + report.stats.syntaxErrorCount} violations=${found.length} seconds=${elapsedSeconds}`);
  return found.length === 0;
}

function mutationChanges() {
  const base = resolveBase(loadBaseline().armedAt);
  const ancestor = (git(['merge-base', 'HEAD', base]) || '').trim();
  if (!ancestor) throw new Error(`No mutation comparison ancestor for ${base}`);
  return { base: ancestor, ...changesSince(ancestor) };
}

function printMutationReport(file) {
  const ignores = mutation.parseIgnores(fs.readFileSync(path.join(REPO, 'scripts/gate/mutation-ignore.json'), 'utf8'));
  const report = mutation.parseReport(fs.readFileSync(file, 'utf8'));
  const environmentPath = file.replace(/\.json$/, '-environment.json');
  const environment = environmentPath !== file && fs.existsSync(environmentPath)
    ? mutation.parseEnvironment(fs.readFileSync(environmentPath, 'utf8')) : null;
  const sourcesPath = file.replace(/\.json$/, '-sources.json');
  const sources = sourcesPath !== file && fs.existsSync(sourcesPath) && ignores.some(e => e.sourceHashes !== undefined)
    ? mutation.parseSourceHashes(fs.readFileSync(sourcesPath, 'utf8')) : {};
  const found = mutation.evaluate(report, mutationChanges().changed, ignores, false, environment, sources);
  found.map(ratchet.formatFinding).forEach(line => console.log(line));
  failureLog.append(found.map(f => [new Date().toISOString(), f.rule, f.file, 'fail']));
  logOutcome('mutation', found.length === 0);
  console.log(`summary: mutation total=${report.stats.totalMutantsCount} escaped=${report.stats.escapedCount} violations=${found.length}`);
  return found.length === 0;
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
  if ((git(['rev-parse', '--is-shallow-repository']) || '').trim() !== 'false') {
    throw new Error('Ratsche braucht vollstaendige Git-Historie (fetch-depth: 0)');
  }
  if (process.env.GATE_BASE_REF) {
    if (!git(['rev-parse', '--verify', `${process.env.GATE_BASE_REF}^{commit}`])) {
      throw new Error(`Vergleichsref ungueltig: ${process.env.GATE_BASE_REF}`);
    }
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
  const requiredBaseline = rel => {
    const text = gitShow(base, rel);
    if (text === null) {
      throw new Error(`Zielbranch-Baseline fehlt: ${rel} in ${base}; explizite Bootstrap-Abnahme erforderlich`);
    }
    return text;
  };
  const baseBaseline = parseBaseline(requiredBaseline(BASELINE_REL));
  const phpstanBase = requiredBaseline(PHPSTAN_BASELINE_REL);
  const phpstanNow = fs.readFileSync(path.join(REPO, PHPSTAN_BASELINE_REL), 'utf8');
  const pairs = text => deptrac.parseBaseline(text).map(e => `${e.from} -> ${e.to}`);
  const deptracNow = fs.readFileSync(path.join(REPO, DEPTRAC_BASELINE_REL), 'utf8');
  const deptracBase = requiredBaseline(DEPTRAC_BASELINE_REL);
  const changeBase = (git(['merge-base', 'HEAD', base]) || '').trim();
  if (!changeBase) {
    throw new Error(`Keine gemeinsame Git-Historie mit Vergleichsref ${base}`);
  }
  const { changed, renames } = changesSince(changeBase);
  // Baseline-Pfade sind Container-relativ (`../html/public/local/coursepilot/...`).
  const baselinePrefix = '../html/public/local/coursepilot/';
  const renamedBaselinePath = p => {
    const to = p.startsWith(baselinePrefix) && renames.get(PLUGIN_PREFIX + p.slice(baselinePrefix.length));
    return to ? baselinePrefix + to.slice(PLUGIN_PREFIX.length) : p;
  };
  found.push(...ratchet.phpstanFindings(phpstanNow, phpstanBase, PHPSTAN_BASELINE_REL, renamedBaselinePath));
  found.push(...ratchet.deptracFindings(pairs(deptracNow), pairs(deptracBase), DEPTRAC_BASELINE_REL));
  let measuredNote = 'ohne Clover (nur Baselines)';
  const baseCoverage = baseBaseline.coverage;
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
    if (mode === 'mutation-report') {
      return printMutationReport(arg);
    }
    if (mode === 'mutation') {
      hostLoad.assertHostCapacity();
      return runMutation();
    }
    if (mode === 'mutation-pilot') {
      if (!arg) throw new Error('mutation-pilot requires a production directory');
      return runMutation(arg);
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
    console.error('Aufruf: gate.js fast | full | static | phpstan-baseline | report <clover.xml> | ratchet [clover.xml] | baseline [clover.xml] | edit | ranking [anzahl] | mutation | mutation-pilot <classes/dir> | mutation-report <json>');
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
