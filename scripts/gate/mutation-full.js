#!/usr/bin/env node
/**
 * One-off full Infection run in resumable sections (#673), plus the evaluation of its reports.
 *
 *   node scripts/gate/mutation-full.js plan             list sections
 *   node scripts/gate/mutation-full.js run [section]    run open sections serially (done markers and foreign locks are skipped)
 *   node scripts/gate/mutation-full.js report <date>    write the survivor and prune reports to docs/checks/
 *
 * Reuses the gate's Infection setup (same excludes, timeout, bootstrap) and its fresh coverage
 * (`reports/infection-coverage`), but mutates whole files instead of a Git diff. One instance,
 * threads 1: the gate container owns the only prepared test database. Never deletes a test.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const crypto = require('node:crypto');
const { spawnSync } = require('node:child_process');
const mutation = require('./mutation');
const ratchet = require('./ratchet');
const hostLoad = require('./host-load');
const { PLUGIN_REL, PLUGIN_IN_CONTAINER, repoPath } = require('./static');

const REPO = process.env.GATE_REPO || path.resolve(__dirname, '..', '..');
const GATE_DIR = process.env.GATE_DIR || '/opt/kurspilot-gate';
const CONTAINER = process.env.GATE_CONTAINER || 'kurspilot-gate-webserver-1';
const OUT = process.env.FULL_MUTATION_DIR || '/var/lib/afk-pipeline/673';
// Several isolated instances (own container, DB and GATE_DIR) share one queue through exclusive lock files.
const INSTANCE = process.env.FULL_MUTATION_INSTANCE || 'main';
const REVERSE = process.env.FULL_MUTATION_ORDER === 'reverse';
const PREFIX = `${PLUGIN_REL}/`;
const CHUNK = 10;
// Contract tests that guard an interface rather than kill mutants; each exemption names its reason.
const EXEMPT = [
  [/\\privacy\\/, 'Privacy API contract (provider and metadata)'],
  [/(^|\\)(privacy_surface|install|upgrade)\w*_test$/, 'Privacy surface / install / upgrade smoke contract'],
];

/** Group production files into sections: one per directory, chunked so an abort loses little. */
function planSections(files) {
  const groups = new Map();
  for (const file of [...files].sort()) {
    const parts = file.split('/');
    const key = parts.length === 1 ? 'root' : parts.slice(0, parts.length > 2 ? 2 : 1).join('-');
    groups.set(key, [...(groups.get(key) || []), file]);
  }
  const sections = [];
  for (const [key, list] of groups) {
    for (let i = 0; i < list.length; i += CHUNK) {
      sections.push({ name: list.length > CHUNK ? `${key}-${String(i / CHUNK + 1).padStart(2, '0')}` : key, files: list.slice(i, i + CHUNK) });
    }
  }
  return sections;
}

const testKey = (cls, method) => `${cls.split('\\').pop()}::${method.replace(/ with data set.*$/s, '')}`;

/** Test methods named in a PHPUnit failure listing ("1) ns\\class::method"). */
function killedByFromOutput(output) {
  return [...new Set([...output.matchAll(/^\d+\) ([\w\\]+)::(\S+)/gm)].map(m => testKey(m[1], m[2])))];
}

/** Union of the covering test methods Infection passed to PHPUnit (`--filter`) in the debug log. */
function coveredTestsFromLog(log) {
  const covered = new Set();
  for (const m of log.matchAll(/'--filter' '\/([^']*?)\/?'(?=\s|$)/gm)) {
    for (const part of m[1].replace(/\\(.)/g, '$1').split('|')) {
      const t = /^([\w\\]+)::(\S+)/.exec(part);
      if (t) covered.add(testKey(t[1], t[2]));
    }
  }
  return [...covered].sort();
}

/** Every test method of the coverage junit, keyed like the above. */
function testsFromJunit(xml) {
  const tests = new Map();
  for (const m of xml.matchAll(/<testcase name="([^"]*)"[^>]* class="([^"]*)"/g)) {
    tests.set(testKey(m[2], m[1].replace(/&quot;/g, '"')), m[2]);
  }
  return tests;
}

/** The differing region of the mutated line(s): `before => after`, a few characters of context. */
function changeOf(m) {
  const a = m.originalSourceCode.split('\n');
  const b = m.mutatedSourceCode.split('\n');
  let first = 0;
  while (first < a.length && a[first] === b[first]) first++;
  let tail = 0;
  while (tail < a.length - first && tail < b.length - first && a[a.length - 1 - tail] === b[b.length - 1 - tail]) tail++;
  const x = a.slice(first, a.length - tail).join(' ').replace(/\s+/g, ' ');
  const y = b.slice(first, b.length - tail).join(' ').replace(/\s+/g, ' ');
  let p = 0;
  while (p < x.length && x[p] === y[p]) p++;
  let q = 0;
  while (q < x.length - p && q < y.length - p && x[x.length - 1 - q] === y[y.length - 1 - q]) q++;
  const cut = t => t.slice(Math.max(0, p - 20), t.length - q + Math.min(q, 20)).trim();
  return `${cut(x)} => ${cut(y)}`.slice(0, 200);
}

/** Digest of a plugin tree (paths and contents), ignoring caches and the generated PHPUnit configs. */
function treeDigest(root) {
  const entries = fs.readdirSync(root, { recursive: true, withFileTypes: true })
    .filter(e => e.isFile() && e.name !== 'phpunit.xml')
    .map(e => path.relative(root, path.join(e.parentPath, e.name)))
    .filter(rel => !rel.split(path.sep).includes('.phpunit.cache'))
    .sort();
  const hash = crypto.createHash('sha256');
  for (const rel of entries) hash.update(`${rel}\0${crypto.createHash('sha256').update(fs.readFileSync(path.join(root, rel))).digest('hex')}\n`);
  return hash.digest('hex');
}

/** Flat per-mutant rows; killed mutants whose PHPUnit never started are errors, not kills. */
function compact(report, ignores, environment, sourceHashes) {
  const rows = [];
  const open = new Set();
  const files = new Map();
  for (const group of ['escaped', 'killed', 'timeouted', 'errored', 'syntaxErrors', 'uncovered', 'ignored']) {
    for (const r of report[group]) files.set(repoPath(r.mutator.originalFilePath), 'all');
  }
  for (const f of mutation.evaluate(report, files, ignores, true, environment, sourceHashes)) {
    if (f.rule === 'mutation-survived') open.add(`${f.file}:${f.line}:${f.text}`);
  }
  const add = (group, status, row) => {
    const id = mutation.identity(row.mutator);
    const added = { file: id.file, line: id.line, mutator: id.mutator, status };
    if (group === 'killed') added.killedBy = killedByFromOutput(row.processOutput);
    if (group === 'escaped') {
      added.ignored = !open.has(`${id.file}:${id.line}:${id.mutator} hash=${id.hash}`);
      added.change = changeOf(row.mutator);
    }
    rows.push(added);
  };
  for (const [group, status] of [['escaped', 'escaped'], ['timeouted', 'timeout'], ['errored', 'error'], ['syntaxErrors', 'syntax'], ['uncovered', 'uncovered'], ['ignored', 'ignored']]) {
    report[group].forEach(row => add(group, status, row));
  }
  for (const row of report.killed) add('killed', /^\s*Fatal error: \$CFG->/m.test(row.processOutput) ? 'error' : 'killed', row);
  return rows;
}

function summarize(rows, skipped = 0) {
  const count = s => rows.filter(r => r.status === s).length;
  const killed = count('killed') + count('timeout');
  const covered = rows.length - count('uncovered');
  return {
    total: rows.length, killed, timeout: count('timeout'), escaped: count('escaped'), escapedIgnored: rows.filter(r => r.status === 'escaped' && r.ignored).length,
    uncovered: count('uncovered'), unattributed: rows.filter(r => r.status === 'killed' && r.killedBy.length === 0).length, error: count('error') + count('syntax'), skipped,
    msi: rows.length ? Math.round(10000 * killed / rows.length) / 100 : null,
    coveredMsi: covered ? Math.round(10000 * killed / covered) / 100 : null,
  };
}

/** Tests that never killed a mutant, split into never-covering (A) and covering-but-no-kill (B). */
function pruneCandidates(allTests, rows, covered) {
  const killers = new Set(rows.flatMap(r => r.killedBy || []));
  const result = { neverCovers: [], coversNoKill: [], exempt: [] };
  for (const [key, cls] of [...allTests].sort()) {
    if (killers.has(key)) continue;
    const exempt = EXEMPT.find(([re]) => re.test(cls));
    if (exempt) result.exempt.push({ key, reason: exempt[1] });
    else (covered.has(key) ? result.coversNoKill : result.neverCovers).push(key);
  }
  return result;
}

// --- CLI ------------------------------------------------------------------------------------

const read = f => fs.readFileSync(f, 'utf8');
const stamp = () => new Date().toISOString();

function productionFiles() {
  const baseline = JSON.parse(read(path.join(REPO, 'scripts/gate/baseline.json')));
  const root = path.join(REPO, PLUGIN_REL);
  return fs.readdirSync(root, { recursive: true, withFileTypes: true })
    .filter(e => e.isFile() && e.name.endsWith('.php'))
    .map(e => path.relative(root, path.join(e.parentPath, e.name)))
    .filter(f => !f.startsWith('tests/') && !ratchet.isExcluded(`${PREFIX}${f}`, baseline.excluded, PREFIX));
}

async function waitForHost() {
  for (let i = 0; ; i++) {
    try { return hostLoad.assertHostCapacity(); } catch (e) {
      if (i >= 36) throw e;
      console.log(`${stamp()} host busy (${e.message}), waiting 5 min`);
      await new Promise(r => setTimeout(r, 300000));
    }
  }
}

/** Another gate command may re-sync the mount and drop the generated PHPUnit config: rebuild it, never the source. */
function ensurePhpunitConfig() {
  if (fs.existsSync(path.join(GATE_DIR, 'plugin', 'phpunit.xml'))) return;
  const built = spawnSync('docker', ['exec', '-w', '/var/www/html', CONTAINER, 'php', 'public/admin/tool/phpunit/cli/util.php', '--buildcomponentconfigs'], { encoding: 'utf8' });
  if (built.status !== 0) throw new Error(`PHPUnit config not built: ${built.stdout}${built.stderr}`);
}

function runSection(section, ignores, baseline, environment, sourceHashes, assertMount) {
  ensurePhpunitConfig();
  const dir = path.join(GATE_DIR, 'reports', 'full673');
  const tmp = `${section.name}-tmp`;
  fs.mkdirSync(dir, { recursive: true });
  const config = {
    source: { directories: [PLUGIN_IN_CONTAINER], excludes: ['tests', ...baseline.excluded.map(e => e.path)] },
    phpUnit: { configDir: PLUGIN_IN_CONTAINER, customPath: '/var/www/html/vendor/bin/phpunit' },
    timeout: 1800,
    bootstrap: '/var/www/reports/infection-bootstrap.php',
    logs: { json: `/var/www/reports/full673/${section.name}.json`, text: `/var/www/reports/full673/${section.name}.log` },
    tmpDir: `/var/www/reports/full673/${tmp}`,
    mutators: { '@default': true },
  };
  fs.writeFileSync(path.join(dir, `${section.name}-config.json`), JSON.stringify(config, null, 2));
  const args = [`--configuration=/var/www/reports/full673/${section.name}-config.json`, '--threads=1', '--only-covering-test-cases',
    '--no-progress', '--log-verbosity=all', '--debug', '--coverage=/var/www/reports/infection-coverage', '--skip-initial-tests',
    `--filter=${section.files.join(',')}`];
  const started = Date.now();
  const result = spawnSync('docker', ['exec', '-w', PLUGIN_IN_CONTAINER, CONTAINER, 'php', '-d', 'memory_limit=-1', '/opt/dev-tools/vendor/bin/infection', ...args],
    { stdio: ['ignore', fs.openSync(path.join(OUT, 'sections', `${section.name}.stdout`), 'w'), 'inherit'] });
  const seconds = (Date.now() - started) / 1000;
  if (result.status !== 0) throw new Error(`section ${section.name}: Infection exit ${result.status}`);
  assertMount();
  const jsonPath = path.join(dir, `${section.name}.json`);
  const logPath = path.join(dir, `${section.name}.log`);
  const report = JSON.parse(read(jsonPath));
  const rows = compact(report, ignores, environment, sourceHashes);
  if (rows.length !== report.stats.totalMutantsCount - (report.stats.skippedCount || 0)) throw new Error(`section ${section.name}: row count mismatch`);
  const covered = coveredTestsFromLog(read(logPath));
  const raw = path.join(OUT, 'raw');
  fs.mkdirSync(raw, { recursive: true });
  for (const f of [jsonPath, logPath]) fs.writeFileSync(path.join(raw, `${path.basename(f)}.gz`), zlib.gzipSync(fs.readFileSync(f)));
  fs.writeFileSync(path.join(OUT, 'sections', `${section.name}.compact.json`), JSON.stringify({ section: section.name, files: section.files, covered, rows }));
  fs.rmSync(dir, { recursive: true, force: true });
  const info = { section: section.name, instance: INSTANCE, files: section.files.length, seconds, exit: 0, finished: stamp(), skipped: report.stats.skippedCount || 0, ...summarize(rows) };
  const done = path.join(OUT, 'sections', `${section.name}.done`);
  fs.writeFileSync(`${done}.tmp`, JSON.stringify(info));
  fs.renameSync(`${done}.tmp`, done);
  console.log(`${stamp()} done ${JSON.stringify(info)}`);
}

async function run(only) {
  const baseline = JSON.parse(read(path.join(REPO, 'scripts/gate/baseline.json')));
  const ignores = mutation.parseIgnores(read(path.join(REPO, 'scripts/gate/mutation-ignore.json')));
  const environment = mutation.parseEnvironment(read(path.join(GATE_DIR, 'reports', 'infection-environment.json')));
  const sections = planSections(productionFiles());
  const dependencies = ignores.flatMap(e => Object.keys(e.sourceHashes || {}));
  const hashed = [...new Set([...sections.flatMap(s => s.files.map(f => `${PREFIX}${f}`)), ...dependencies, 'composer.lock'])];
  const sourceHashes = Object.fromEntries(hashed.map(f => [f, crypto.createHash('sha256').update(fs.readFileSync(path.join(REPO, f))).digest('hex')]));
  fs.mkdirSync(path.join(OUT, 'sections'), { recursive: true });
  fs.writeFileSync(path.join(OUT, 'sources.json'), JSON.stringify(sourceHashes, null, 2));
  // The measured source is fixed by the first start; the container mount must equal it before and after every section.
  const digestFile = path.join(OUT, 'plugin-digest.txt');
  const pluginDigest = treeDigest(path.join(REPO, PLUGIN_REL));
  if (fs.existsSync(digestFile) && read(digestFile).trim() !== pluginDigest) throw new Error('plugin source changed since the full run started');
  fs.writeFileSync(digestFile, `${pluginDigest}\n`);
  const assertMount = () => {
    const mounted = treeDigest(path.join(GATE_DIR, 'plugin'));
    if (mounted !== pluginDigest) throw new Error(`mount ${GATE_DIR}/plugin differs from the measured source (${mounted} != ${pluginDigest})`);
  };
  const marker = (s, ext) => path.join(OUT, 'sections', `${s.name}.${ext}`);
  // A dead run of this instance left its locks behind; foreign instances keep theirs.
  for (const s of sections) {
    if (fs.existsSync(marker(s, 'lock')) && read(marker(s, 'lock')) === INSTANCE && !fs.existsSync(marker(s, 'done'))) fs.rmSync(marker(s, 'lock'));
  }
  for (const section of REVERSE ? [...sections].reverse() : sections) {
    if (only && section.name !== only) continue;
    if (fs.existsSync(marker(section, 'done'))) continue;
    await waitForHost();
    assertMount();
    try { fs.writeFileSync(marker(section, 'lock'), INSTANCE, { flag: 'wx' }); } catch (e) { if (e.code === 'EEXIST') continue; throw e; }
    console.log(`${stamp()} start ${section.name} (${section.files.length} files) instance=${INSTANCE}`);
    try {
      runSection(section, ignores, baseline, environment, sourceHashes, assertMount);
    } catch (e) { fs.rmSync(marker(section, 'lock'), { force: true }); throw e; }
  }
  if (sections.every(s => fs.existsSync(path.join(OUT, 'sections', `${s.name}.done`)))) fs.writeFileSync(path.join(OUT, 'full.done'), stamp());
}

const sectionData = () => fs.readdirSync(path.join(OUT, 'sections')).filter(f => f.endsWith('.compact.json')).sort()
  .map(f => JSON.parse(read(path.join(OUT, 'sections', f))));

function report(date) {
  const data = sectionData();
  const infos = data.map(d => JSON.parse(read(path.join(OUT, 'sections', `${d.section}.done`))));
  const rows = data.flatMap(d => d.rows);
  const covered = new Set(data.flatMap(d => d.covered));
  const total = summarize(rows, infos.reduce((n, i) => n + i.skipped, 0));
  const seconds = infos.reduce((n, i) => n + i.seconds, 0);
  const out = (n, text) => fs.writeFileSync(path.join(REPO, 'docs/checks', `673-mutationsvolllauf-${date}-${n}.md`), text);
  const tableRow = i => `| ${i.section} | ${i.files} | ${i.total} | ${i.killed} | ${i.escaped} (${i.escapedIgnored}) | ${i.uncovered} | ${i.error} | ${i.msi} | ${Math.round(i.seconds)} |`;
  const byFile = new Map();
  for (const r of rows.filter(r => r.status === 'escaped')) byFile.set(r.file, [...(byFile.get(r.file) || []), r]);
  let survivors = `# Überlebende Mutanten je Datei (#673, ${date})\n\nQuelle und Methode: siehe [Hauptbericht](673-mutationsvolllauf-${date}.md). `
    + `Gesamt ${total.escaped} Überlebende, davon ${total.escapedIgnored} mit begründetem exaktem Ignore in \`scripts/gate/mutation-ignore.json\` (gelistet als „ignoriert“), `
    + `${total.escaped - total.escapedIgnored} offen. Offene Überlebende sind eine Arbeitsliste, keine Gate-Verletzung.\n\n`
    + '| Abschnitt | Dateien | Mutanten | getötet | überlebt (ignoriert) | ungedeckt | Fehler | MSI % | Sekunden |\n|---|---:|---:|---:|---:|---:|---:|---:|---:|\n'
    + `${infos.map(tableRow).join('\n')}\n| **gesamt** | ${infos.reduce((n, i) => n + i.files, 0)} | ${total.total} | ${total.killed} | ${total.escaped} (${total.escapedIgnored}) | ${total.uncovered} | ${total.error} | ${total.msi} | ${Math.round(seconds)} |\n`;
  for (const [file, list] of [...byFile].sort(([a], [b]) => (a < b ? -1 : 1))) {
    const open = list.filter(r => !r.ignored).length;
    survivors += `\n## ${file} (${list.length} überlebt, ${open} offen)\n\n${list.sort((a, b) => a.line - b.line).map(r => `- ${r.line} ${r.mutator}${r.ignored ? ' (ignoriert)' : ''}: \`${r.change.replace(/`/g, "'")}\``).join('\n')}\n`;
  }
  out('ueberlebende', survivors);
  const all = testsFromJunit(read(path.join(GATE_DIR, 'reports', 'infection-coverage', 'junit.xml')));
  const prune = pruneCandidates(all, rows, covered);
  const list = (items, f = x => x) => (items.length ? items.map(i => `- ${f(i)}`).join('\n') : '- (keine)');
  out('prune', `# Prune-Bericht (#673, ${date})\n\nListe für eine menschliche Entscheidung. Kein Test wurde gelöscht und keiner wird automatisch gelöscht.\n\n`
    + `Methode: Als Töter eines Mutanten zählt der in \`processOutput\` genannte fehlgeschlagene Test (PHPUnit bricht beim ersten Fehlschlag ab). Ein Test, der denselben Mutanten töten würde, aber nach dem ersten Töter lief, bleibt daher unsichtbar: Kandidaten sind eine **Obermenge** der wirklich redundanten Tests und müssen einzeln geprüft werden. Datenprovider-Fälle sind je Testmethode zusammengefasst. Testbestand: ${all.size} Methoden aus der Coverage-Junit-Datei.\n\n`
    + `- A (deckt keinen Mutanten, kein Töter): ${prune.neverCovers.length}\n- B (deckt Mutanten, tötet keinen): ${prune.coversNoKill.length}\n- ausgenommen: ${prune.exempt.length}\n\n`
    + `## A: deckt keine mutierte Zeile\n\n${list(prune.neverCovers)}\n\n## B: deckt mutierte Zeilen, tötet keinen Mutanten\n\n${list(prune.coversNoKill)}\n\n`
    + `## Ausgenommene Vertragstests\n\n${list(prune.exempt, e => `${e.key}: ${e.reason}`)}\n`);
  console.log(JSON.stringify({ total, seconds, prune: { A: prune.neverCovers.length, B: prune.coversNoKill.length, exempt: prune.exempt.length }, sections: infos.length }));
}

module.exports = { treeDigest, changeOf, planSections, killedByFromOutput, coveredTestsFromLog, testsFromJunit, compact, summarize, pruneCandidates, testKey };

if (require.main === module) {
  const [cmd, arg] = process.argv.slice(2);
  if (cmd === 'plan') planSections(productionFiles()).forEach(s => console.log(`${s.name}\t${s.files.length}`));
  else if (cmd === 'run') run(arg).catch(e => { console.error(e.message); process.exit(1); });
  else if (cmd === 'report' && arg) report(arg);
  else { console.error('usage: mutation-full.js plan | run [section] | report <date>'); process.exit(2); }
}
