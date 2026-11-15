#!/usr/bin/env node
/**
 * Full kill matrix (#753): for every mutant ALL failing tests, not only the first one.
 *
 * Infection 0.29 sets stopOnDefect="true" per mutant, so `killedBy` names only the first failing test.
 * `mutation-allfail.sh` (Infection `phpUnit.customPath`) lifts that and keeps JUnit, text output and the mutated
 * source per mutant; this module maps those artefacts back to the Infection report and derives a prune PROPOSAL.
 *
 *   FULL_MUTATION_ALLFAIL=1 FULL_MUTATION_INSTANCE=<id> GATE_DIR=... FULL_MUTATION_DIR=... node scripts/gate/mutation-full.js run
 *   node scripts/gate/mutation-matrix.js report <date>      matrix + prune proposal to docs/checks/ (reads FULL_MUTATION_DIR)
 *
 * It never deletes or edits a test. Pure functions are exported for node --test.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const crypto = require('node:crypto');

const testKey = (cls, method) => `${cls.split('\\').pop()}::${method.replace(/ with data set.*$/s, '')}`;
const sha256 = text => crypto.createHash('sha256').update(text).digest('hex');
const decode = s => s.replace(/&quot;/g, '"').replace(/&apos;|&#0?39;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

/** Contract tests (CODING_STANDARDS T4, Spec 0029 story 33): never prune candidates. Class-name rules. */
const EXEMPT_CLASS = [
  [/\\privacy\\/, 'Privacy API contract (provider and metadata)'],
  [/(^|\\)(privacy_surface|install|upgrade)\w*_test$/, 'Privacy surface / install / upgrade smoke contract'],
];
/** Evidence in the test body, not in its name: tests that assert a published interface. */
const CONTRACT_BODY = [
  [/privacy_surface\s*::/, 'asserts the privacy surface'],
  [/db\/(services|access)\.php/, 'asserts the registered service / capability definition'],
  [/\$CFG->dirroot\s*\.\s*'\/local\/coursepilot\/db\//, 'asserts the registered service / capability definition'],
  [/\ballowlist\b/i, 'asserts the published tool allowlist'],
];
/** Tests whose result may come from a child process (pcov does not see it): kept out of the proposal. */
const SUBPROCESS_BODY = [/phpunit_process_bootstrap/, /\bproc_open\s*\(/, /\bshell_exec\s*\(/, /\bpassthru\s*\(/, /new\s+\\?Symfony\\Component\\Process/, /\bpopen\s*\(/, /[^\w>$]exec\s*\(\s*['"$]/];

/** Parse a PHPUnit JUnit log: every test method that ran and, per method, assertion failures and errors. */
function parseJunit(xml) {
  const ran = new Set();
  const failures = new Set();
  const errors = new Set();
  for (const m of xml.matchAll(/<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g)) {
    const attrs = Object.fromEntries([...m[1].matchAll(/(\w+)="([^"]*)"/g)].map(a => [a[1], decode(a[2])]));
    if (!attrs.name || !attrs.class) continue;
    const key = testKey(attrs.class, attrs.name);
    ran.add(key);
    const body = m[2] || '';
    if (/<failure\b/.test(body)) failures.add(key);
    if (/<error\b/.test(body)) errors.add(key);
  }
  return { ran: [...ran].sort(), failures: [...failures].sort(), errors: [...errors].sort() };
}

/**
 * Tests named under "Triggered by:" in PHPUnit's text output ("N test(s) triggered M PHP warning(s):", deprecations,
 * notices, risky). These never appear in JUnit.
 */
function parseTriggers(output) {
  const found = [];
  const head = /^(\d+) tests? (?:triggered \d+ ([A-Za-z][A-Za-z ]*?)s?|considered (risky)):\s*$/gm;
  const heads = [...output.matchAll(head)];
  heads.forEach((h, i) => {
    const kind = h[3] ? 'risky' : h[2].toLowerCase().replace(/^php /, 'php ');
    const segment = output.slice(h.index + h[0].length, i + 1 < heads.length ? heads[i + 1].index : output.length);
    for (const by of segment.matchAll(/Triggered by:\s*\n((?:\s*\*[^\n]*\n(?:[ \t]+[^\n*][^\n]*\n)?)+)/g)) {
      for (const t of by[1].matchAll(/^\s*\* ([\w\\]+)::(\S+)/gm)) found.push({ test: testKey(t[1], t[2]), kind });
    }
    if (h[3]) for (const t of segment.matchAll(/^\s*\d+\) ([\w\\]+)::(\S+)/gm)) found.push({ test: testKey(t[1], t[2]), kind });
  });
  const seen = new Set();
  return found.filter(f => { const k = `${f.kind}|${f.test}`; return seen.has(k) ? false : (seen.add(k), true); });
}

/** One matrix row from an Infection result plus the artefacts the wrapper kept for it. */
function buildRow(base, status, data) {
  const row = { ...base, status, ran: [], assertionFailures: [], errors: [], warnings: [], risky: [], complete: true };
  if (!data) {
    // No artefacts: fine for mutants that ran no test, otherwise the failing set is unknown.
    row.complete = ['uncovered', 'syntax', 'ignored'].includes(status);
    return row;
  }
  const j = data.junit ? parseJunit(data.junit) : { ran: [], failures: [], errors: [] };
  const triggers = parseTriggers(data.output || '');
  row.ran = j.ran;
  row.assertionFailures = j.failures;
  row.errors = j.errors.filter(t => !j.failures.includes(t));
  row.warnings = triggers.filter(t => t.kind !== 'risky').map(t => `${t.kind}: ${t.test}`);
  row.risky = triggers.filter(t => t.kind === 'risky').map(t => t.test);
  // JUnit missing (crash, timeout kill) means the failing set is unknown, not empty.
  row.complete = Boolean(data.junit) && status !== 'timeout' && status !== 'error';
  return row;
}

/** Tests that make a killed mutant a kill: failures and errors; if there are none, the tests behind a warning kill. */
function killers(row) {
  const strong = [...new Set([...row.assertionFailures, ...row.errors])].sort();
  if (strong.length || !['killed', 'timeout'].includes(row.status)) return { strong, weak: [] };
  return { strong, weak: [...new Set(row.warnings.map(w => w.replace(/^[^:]*: /, '')))] };
}

/** Protectors of a mutant: every test whose removal could turn it into a survivor. Unknown data protects all that ran. */
function protectors(row) {
  if (!['killed', 'timeout', 'error'].includes(row.status)) return [];
  const { strong, weak } = killers(row);
  const known = [...strong, ...weak];
  return known.length ? known : row.ran;
}

/** Why a test body is a contract or a possible child-process test (null = neither). Evidence is the source, not the name. */
function classifyBody(source) {
  const contract = CONTRACT_BODY.find(([re]) => re.test(source));
  const sub = SUBPROCESS_BODY.find(re => re.test(source));
  return { contract: contract ? contract[1] : null, subprocess: sub ? sub.source : null };
}

/**
 * Prune proposal from the matrix. `tests`: Map key -> { cls, file, line, time, source? }.
 * Tier 1 = test fails for no mutant. Tier 2 = every mutant it kills is also killed by a test that stays.
 * Exempt (contract) and subprocess-suspect tests are never proposed.
 */
function proposal(tests, rows) {
  const protect = new Map();
  const incomplete = [];
  for (const r of rows) {
    const p = protectors(r);
    const k = killers(r);
    if (['killed', 'timeout', 'error'].includes(r.status) && (!r.complete || k.strong.length + k.weak.length === 0)) incomplete.push(r);
    protect.set(r, new Set(p));
  }
  const killsOf = new Map();
  const ranOf = new Map();
  for (const [r, set] of protect) for (const t of set) killsOf.set(t, [...(killsOf.get(t) || []), r]);
  for (const r of rows) for (const t of r.ran) ranOf.set(t, (ranOf.get(t) || 0) + 1);
  const result = { exempt: [], subprocess: [], tier1: [], tier2: [], kept: [], incomplete };
  const eligible = [];
  for (const [key, t] of [...tests].sort(([a], [b]) => (a < b ? -1 : 1))) {
    const byClass = EXEMPT_CLASS.find(([re]) => re.test(t.cls));
    const body = classifyBody(t.source || '');
    if (!body.subprocess && t.fileSource) body.subprocess = classifyBody(t.fileSource).subprocess;
    if (byClass || body.contract) result.exempt.push({ key, reason: byClass ? byClass[1] : body.contract });
    else if (body.subprocess) result.subprocess.push({ key, evidence: body.subprocess });
    else eligible.push(key);
  }
  const removed = new Set();
  for (const key of eligible.filter(k => !killsOf.has(k))) {
    removed.add(key);
    result.tier1.push({ key, ranOnMutants: ranOf.get(key) || 0, neverRan: !ranOf.has(key), time: tests.get(key).time || 0 });
  }
  const order = eligible.filter(k => killsOf.has(k)).sort((a, b) => killsOf.get(a).length - killsOf.get(b).length || (a < b ? -1 : 1));
  for (const key of order) {
    const safe = killsOf.get(key).every(r => [...protect.get(r)].some(p => p !== key && !removed.has(p)));
    if (!safe) { result.kept.push(key); continue; }
    removed.add(key);
    result.tier2.push({ key, kills: killsOf.get(key).length, time: tests.get(key).time || 0,
      proof: killsOf.get(key).slice(0, 3).map(r => ({ mutant: `${r.file}:${r.line} ${r.mutator}`, remaining: [...protect.get(r)].filter(p => !removed.has(p)).slice(0, 3) })),
      minRemaining: Math.min(...killsOf.get(key).map(r => [...protect.get(r)].filter(p => !removed.has(p)).length)) });
  }
  // Re-verify against the final removal set: every protected mutant keeps at least one test.
  result.violations = rows.filter(r => protect.get(r).size && ![...protect.get(r)].some(p => !removed.has(p))).map(r => `${r.file}:${r.line} ${r.mutator}`);
  result.removed = [...removed].sort();
  return result;
}

/** Counts per status and per failure kind, kept apart as the issue demands. */
function summarizeMatrix(rows) {
  const by = s => rows.filter(r => r.status === s).length;
  const killed = rows.filter(r => r.status === 'killed');
  const dist = {};
  for (const r of killed) { const n = killers(r).strong.length; dist[n] = (dist[n] || 0) + 1; }
  return {
    total: rows.length, killed: killed.length, escaped: by('escaped'), timeout: by('timeout'), error: by('error'), syntax: by('syntax'),
    uncovered: by('uncovered'), ignored: by('ignored'),
    killedByAssertion: killed.filter(r => r.assertionFailures.length).length,
    killedByErrorOnly: killed.filter(r => !r.assertionFailures.length && r.errors.length).length,
    killedByWarningOnly: killed.filter(r => !r.assertionFailures.length && !r.errors.length && r.warnings.length).length,
    killedWithoutAttribution: killed.filter(r => !r.assertionFailures.length && !r.errors.length && !r.warnings.length).length,
    incompleteData: rows.filter(r => ['killed', 'timeout', 'error'].includes(r.status) && !r.complete).length,
    killersPerMutant: dist,
  };
}

/** Section collector: Infection report + wrapper data dir -> matrix rows. `mutation` is scripts/gate/mutation.js. */
function collect(report, dataDir, mutation) {
  const files = fs.existsSync(dataDir) ? fs.readdirSync(dataDir) : [];
  const byDigest = new Map();
  for (const f of files.filter(n => n.endsWith('.php'))) {
    const id = f.slice(0, -4);
    const digest = sha256(fs.readFileSync(path.join(dataDir, f), 'utf8'));
    byDigest.set(digest, [...(byDigest.get(digest) || []), id]);
  }
  const rows = [];
  const claimed = new Set();
  const groups = [['killed', 'killed'], ['escaped', 'escaped'], ['timeouted', 'timeout'], ['errored', 'error'], ['syntaxErrors', 'syntax'], ['uncovered', 'uncovered'], ['ignored', 'ignored']];
  for (const [group, status] of groups) {
    for (const r of report[group] || []) {
      const identity = mutation.identity(r.mutator);
      const ids = (byDigest.get(sha256(r.mutator.mutatedSourceCode)) || []).filter(i => !claimed.has(i));
      const id = ids[0];
      if (id) claimed.add(id);
      const read = ext => (id && fs.existsSync(path.join(dataDir, `${id}.${ext}`)) ? fs.readFileSync(path.join(dataDir, `${id}.${ext}`), 'utf8') : null);
      const data = id ? { junit: read('junit.xml'), output: read('out') || r.processOutput || '' } : null;
      const crashed = status === 'killed' && /Fatal error|Allowed memory size/.test((data && data.output) || r.processOutput || '');
      rows.push({ ...buildRow({ file: identity.file, line: identity.line, mutator: identity.mutator, mutant: id || null }, status, data), crashed });
    }
  }
  return { rows, unmatchedArtefacts: [...byDigest.values()].flat().filter(i => !claimed.has(i)) };
}

// --- report -----------------------------------------------------------------------------------

const list = (items, f = x => x) => (items.length ? items.map(i => `- ${f(i)}`).join('\n') : '- (keine)');

function renderProposal({ date, stamp, tests, p, summary, sources }) {
  const sum = (items) => Math.round(items.reduce((n, i) => n + i.time, 0));
  const never = p.tier1.filter(t => t.neverRan);
  const ranNoKill = p.tier1.filter(t => !t.neverRan);
  return `# Prune-VORSCHLAG aus der Kill-Matrix (#753, ${date})\n\n`
    + `**Nur ein Vorschlag. Kein Test wurde gelöscht oder geändert.** Löschung braucht eine neue HITL-Freigabe von Matthias für genau eine Liste daraus.\n\n`
    + `Quelle: [Kill-Matrix](753-kill-matrix-${date}.md), Rohbelege dort. Stand: ${stamp}. ${sources}\n\n`
    + `## Methode\n\n`
    + `- Schutzmenge je Mutant = alle Tests, die ihn töten (Assertion-Fehler, Fehler; bei reinem Warnungs-Kill die auslösenden Tests; bei fehlender JUnit alle gelaufenen Tests).\n`
    + `- Tier 1: Test taucht in **keiner** Schutzmenge auf. Tier 2: jeder Mutant, den der Test tötet, wird auch von einem Test getötet, der bleibt (Greedy, aufsteigend nach Zahl der Kills; Gegenprobe gegen die Endliste: ${p.violations.length} Verletzungen).\n`
    + `- Ausgenommen: Vertragstests (CODING_STANDARDS T4, Story 33), Erkennung per Klassenname **und** Testrumpf (nicht nur Methodenname): ${p.exempt.length}. Subprozess-Verdacht (Rumpf nutzt Kindprozess, pcov sieht ihn nicht): ${p.subprocess.length}, bleiben stehen.\n`
    + `- Grenze: \`--only-covering-test-cases\` lässt Tests ohne Line-Coverage-Treffer nicht laufen. Ein Test, der nur außerhalb davon töten würde (Überlebende, ungedeckte Mutanten), ist in der Matrix unsichtbar → Nachlauf siehe unten. Tests, die Verhalten ohne mutierbare Zeile schützen (Integration, Kompatibilität), tötet kein Mutant: fehlender Kill ist kein Beweis für Wertlosigkeit.\n\n`
    + `## Zahlen\n\n| | Anzahl | Laufzeit s (Coverage-JUnit) |\n|---|---:|---:|\n`
    + `| Testmethoden gesamt | ${tests.size} | |\n| Vertragstests (ausgenommen) | ${p.exempt.length} | |\n| Subprozess-Verdacht (bleiben) | ${p.subprocess.length} | |\n`
    + `| Tier 1A: lief auf keinem Mutanten (kein Coverage-Treffer) | ${never.length} | ${sum(never)} |\n| Tier 1B: lief auf Mutanten, tötete keinen | ${ranNoKill.length} | ${sum(ranNoKill)} |\n`
    + `| Tier 2: Kills vollständig durch bleibende Tests geschützt | ${p.tier2.length} | ${sum(p.tier2)} |\n| bleiben (einzige Töter mindestens eines Mutanten) | ${p.kept.length} | |\n\n`
    + `Unvollständige Matrixdaten: ${summary.incompleteData} Mutanten (Timeout/Fehler/ohne JUnit); sie schützen konservativ alle auf ihnen gelaufenen Tests. Gesamtzustand: ${p.incomplete.length === 0 ? 'vollständig' : 'UNVOLLSTÄNDIG, siehe Kill-Matrix'}.\n\n`
    + `## Nutzen und Risiko\n\n- Nutzen: weniger Wartung und Laufzeit (Spalte oben, nur Coverage-Lauf-Zeit; Mutationslauf-Zeit nicht neu gemessen).\n`
    + `- Risiko Tier 1A: höchstes (Coverage-Artefakt möglich: Subprozess, pcov-Lücke). Nur nach Nachlauf (unten) und Einzelprüfung vorschlagbar.\n`
    + `- Risiko Tier 1B: mittel; Test deckt Zeilen, aber keine Mutation macht ihn rot, er kann Verhalten sichern, das keine Mutation trifft.\n`
    + `- Risiko Tier 2: niedrig bis mittel; Mutations-Redundanz, aber nicht Intention (zwei Tests, derselbe Fehler).\n\n`
    + `## Tier 1A (lief auf keinem Mutanten)\n\n${list(never, t => t.key)}\n\n## Tier 1B (lief, tötete keinen)\n\n${list(ranNoKill, t => `${t.key} (lief auf ${t.ranOnMutants} Mutanten)`)}\n\n`
    + `## Tier 2 (nicht empfohlen)\n\n${p.tier2.length} Tests töten nur Mutanten, die auch ein bleibender Test tötet. Das ist Mutations-Redundanz, keine Test-Redundanz (zwei Tests dürfen denselben Fehler aus verschiedenen Gründen prüfen, ein Mutant sieht nur einen Teil davon). Die vollständige Liste mit Schutzbeweis je Test steht in \`docs/checks/evidence/753/prune-proposal-${date}.json\`. **Keine Empfehlung zur Löschung**, die Menge wäre rund die Hälfte des Bestands. Auszug (erste 15):\n\n${list(p.tier2.slice(0, 15), t => `${t.key}: ${t.kills} Kills, mind. ${t.minRemaining} bleibende Töter je Mutant, z. B. ${t.proof[0].mutant} → ${t.proof[0].remaining.join(', ')}`)}\n\n`
    + `## Empfehlung und offener Nachlauf\n\n- Nur Tier 1 (${p.tier1.length} Tests, ${sum(p.tier1)} s) kommt für eine Freigabe in Frage, Tier 2 nicht.\n`
    + `- **Nachlauf noch nicht gelaufen:** Die Tier-1-Tests wurden nicht gegen die ${summary.escaped} Überlebenden und ${summary.uncovered} ungedeckten Mutanten gelaufen (dort läuft wegen \`--only-covering-test-cases\` kein Test außerhalb der Line-Coverage). Bis dahin ist Tier 1A (kein Coverage-Treffer) nicht freigabereif, Tier 1B nur nach Einzelprüfung.\n`
    + `- Einzelprüfung: ${ranNoKill.filter(t => /contract|catalog/.test(t.key)).length} der ${ranNoKill.length} Tier-1B-Tests heißen nach Katalog-/Vertragstests. Der Name entscheidet nichts, aber sie sind vor einer Freigabe auf Vertragscharakter (T4) zu lesen.\n`
    + `- Nutzen insgesamt klein (Tier 1: ${sum(p.tier1)} s von ca. 1.300 s Suite); Hauptnutzen ist weniger Wartung, nicht Laufzeit.\n\n`
    + `## Vertragstests (ausgenommen)\n\n${list(p.exempt, e => `${e.key}: ${e.reason}`)}\n\n## Subprozess-Verdacht (bleiben)\n\n${list(p.subprocess, e => `${e.key}: Muster \`${e.evidence}\``)}\n`;
}

function renderMatrix({ date, stamp, summary, sources, rawNote }) {
  const k = summary.killersPerMutant;
  return `# Vollständige Kill-Matrix (#753, ${date})\n\n${stamp}. ${sources}\n\n`
    + `Je Mutant alle fehlschlagenden Tests (Wrapper \`scripts/gate/mutation-allfail.sh\`, hebt Infections \`stopOnDefect\` auf). Rohdaten: ${rawNote}\n\n`
    + `| Messwert | Wert |\n|---|---:|\n| Mutanten | ${summary.total} |\n| getötet | ${summary.killed} |\n| überlebt | ${summary.escaped} |\n| Timeout | ${summary.timeout} |\n| Infection-Fehler | ${summary.error} (+ ${summary.syntax} Syntax) |\n| ungedeckt (kein Test lief) | ${summary.uncovered} |\n`
    + `| getötet durch Assertion-Fehlschlag | ${summary.killedByAssertion} |\n| getötet nur durch Fehler/Exception | ${summary.killedByErrorOnly} |\n| getötet nur durch Warnung (nur PHPUnit-Text) | ${summary.killedByWarningOnly} |\n| getötet ohne Zuordnung (Absturz u. Ä.) | ${summary.killedWithoutAttribution} |\n| Mutanten mit unvollständigen Daten | ${summary.incompleteData} |\n\n`
    + `Töter je getötetem Mutanten (Assertion + Fehler): ${Object.entries(k).sort((a, b) => a[0] - b[0]).map(([n, c]) => `${n}: ${c}`).join(', ')}.\n`;
}

/** Every test method of the coverage JUnit with class, file, line and summed time (data sets merged). */
function testsFromCoverageJunit(xml) {
  const tests = new Map();
  for (const m of xml.matchAll(/<testcase\b([^>]*?)(?:\/>|>)/g)) {
    const a = Object.fromEntries([...m[1].matchAll(/(\w+)="([^"]*)"/g)].map(x => [x[1], decode(x[2])]));
    if (!a.name || !a.class) continue;
    const key = testKey(a.class, a.name);
    const old = tests.get(key);
    tests.set(key, { cls: a.class, file: a.file, line: Number(a.line), time: (old ? old.time : 0) + Number(a.time || 0) });
  }
  return tests;
}

/** Method body of a test starting at its declaration line (up to the next member declaration). */
function methodBody(fileText, line) {
  const lines = fileText.split('\n');
  let end = line;
  while (end < lines.length && !/^\s{4}(public|protected|private|#\[)/.test(lines[end])) end++;
  return lines.slice(line - 1, end).join('\n');
}

// --- CLI ---------------------------------------------------------------------------------------

function report(date) {
  const REPO = process.env.GATE_REPO || path.resolve(__dirname, '..', '..');
  const OUT = process.env.FULL_MUTATION_DIR || '/var/lib/afk-pipeline/753';
  const GATE_DIR = process.env.GATE_DIR || '/opt/kurspilot-gate';
  const sections = fs.readdirSync(path.join(OUT, 'sections')).filter(f => f.endsWith('.matrix.json')).sort().map(f => JSON.parse(fs.readFileSync(path.join(OUT, 'sections', f), 'utf8')));
  const rows = sections.flatMap(s => s.rows);
  const junitPath = path.join(GATE_DIR, 'reports', 'infection-coverage', 'junit.xml');
  const junit = fs.readFileSync(junitPath, 'utf8');
  const tests = testsFromCoverageJunit(junit);
  const fileCache = new Map();
  const text = f => { if (!fileCache.has(f)) { const rel = f.replace(/^.*\/public\/local\/coursepilot\//, 'Plugin/src/local_coursepilot/'); fileCache.set(f, fs.existsSync(path.join(REPO, rel)) ? fs.readFileSync(path.join(REPO, rel), 'utf8') : ''); } return fileCache.get(f); };
  for (const t of tests.values()) { const full = text(t.file); t.source = methodBody(full, t.line); t.fileSource = full; }
  const sha = require('node:child_process').execFileSync('git', ['-C', REPO, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  const digest = fs.existsSync(path.join(OUT, 'plugin-digest.txt')) ? fs.readFileSync(path.join(OUT, 'plugin-digest.txt'), 'utf8').trim() : 'unbekannt';
  const summary = summarizeMatrix(rows);
  const p = proposal(tests, rows);
  const unmatched = sections.reduce((n, s) => n + s.unmatchedArtefacts.length, 0);
  const stamp = `Quellstand ${sha}, Plugin-Baumdigest ${digest}, Coverage-JUnit sha256 ${sha256(junit).slice(0, 16)} (${tests.size} Testmethoden), Infection 0.29.14, ${sections.length} Abschnitte`;
  const sources = `Nicht zugeordnete Wrapper-Artefakte: ${unmatched}. ${p.incomplete.length ? 'UNVOLLSTÄNDIG: ' + p.incomplete.length + ' getötete/Timeout-Mutanten ohne Failing-Daten.' : 'Alle getöteten Mutanten mit Failing-Daten.'}`;
  const evidence = path.join(REPO, 'docs/checks/evidence/753');
  fs.mkdirSync(evidence, { recursive: true });
  fs.writeFileSync(path.join(evidence, `kill-matrix-${date}.json.gz`), zlib.gzipSync(JSON.stringify({ stamp, summary, rows })));
  fs.writeFileSync(path.join(evidence, `prune-proposal-${date}.json`), JSON.stringify({ stamp, tier1: p.tier1, tier2: p.tier2, exempt: p.exempt, subprocess: p.subprocess, violations: p.violations, incomplete: p.incomplete.length }, null, 1));
  const rawNote = `\`docs/checks/evidence/753/kill-matrix-${date}.json.gz\` (alle Zeilen: Status, gelaufene Tests, Assertion-Fehler, Fehler, Warnungen); je Mutant JUnit, PHPUnit-Text und Mutant-Quelle in \`${OUT}/raw/*-allfail.tar.gz\` (nicht im Repo, Größe).`;
  fs.writeFileSync(path.join(REPO, 'docs/checks', `753-kill-matrix-${date}.md`), renderMatrix({ date, stamp, summary, sources, rawNote }));
  fs.writeFileSync(path.join(REPO, 'docs/checks', `753-prune-vorschlag-${date}.md`), renderProposal({ date, stamp, tests, p, summary, sources }));
  console.log(JSON.stringify({ summary, tier1: p.tier1.length, tier2: p.tier2.length, exempt: p.exempt.length, subprocess: p.subprocess.length, violations: p.violations.length, incomplete: p.incomplete.length }));
}

if (require.main === module) {
  const [cmd, arg] = process.argv.slice(2);
  if (cmd === 'report' && arg) report(arg);
  else { console.error('usage: mutation-matrix.js report <date>'); process.exit(2); }
}

module.exports = { testsFromCoverageJunit, methodBody, testKey, parseJunit, parseTriggers, buildRow, killers, protectors, classifyBody, proposal, summarizeMatrix, collect, renderProposal, renderMatrix, EXEMPT_CLASS, sha256 };
