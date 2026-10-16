/** Diff-based Infection report evaluation; ignores identify one exact mutation. */
'use strict';

const crypto = require('node:crypto');
const { PLUGIN_IN_CONTAINER, repoPath } = require('./static');
const PREFIX = 'Plugin/src/local_coursepilot/';
const GROUPS = { escaped: 'escapedCount', killed: 'killedCount', timeouted: 'timeOutCount', errored: 'errorCount', syntaxErrors: 'syntaxErrorCount', uncovered: 'notCoveredCount', ignored: 'ignoredCount' };

function validSourceHashes(sources, allowLock = false) {
  return sources && typeof sources === 'object' && !Array.isArray(sources) && Object.keys(sources).length > 0
    && Object.entries(sources).every(([file, hash]) =>
      ((allowLock && file === 'composer.lock') || (file.startsWith(PREFIX) && !file.startsWith(`${PREFIX}tests/`) && file.endsWith('.php') && !file.includes('..')))
      && typeof hash === 'string' && /^[a-f0-9]{64}$/.test(hash));
}

function parseSourceHashes(text) {
  const sources = JSON.parse(text);
  if (!validSourceHashes(sources, true)) throw new Error('Infection sources: invalid source hash manifest');
  return sources;
}

function parseIgnores(text) {
  const entries = JSON.parse(text);
  if (!Array.isArray(entries)) throw new Error('mutation-ignore: expected an array');
  const seen = new Set();
  for (const e of entries) {
    if (!e || !e.file?.startsWith(PREFIX) || !e.file.endsWith('.php') || e.file.includes('..')
        || !Number.isInteger(e.line) || e.line < 1 || typeof e.mutator !== 'string' || !e.mutator.trim()
        || !/^[a-f0-9]{64}$/.test(e.hash) || typeof e.reason !== 'string' || !e.reason.trim()) {
      throw new Error('mutation-ignore: every exact mutant needs file, line, mutator, SHA-256 hash and reason');
    }
    if (e.qtypeSourceHash !== undefined && !/^[a-f0-9]{64}$/.test(e.qtypeSourceHash)) {
      throw new Error('mutation-ignore: qtypeSourceHash must be an exact SHA-256 source digest');
    }
    if (e.sourceHashes !== undefined && !validSourceHashes(e.sourceHashes)) {
      throw new Error('mutation-ignore: sourceHashes must bind exact production PHP paths to SHA-256 digests');
    }
    const key = JSON.stringify([e.file, e.line, e.mutator, e.hash]);
    if (seen.has(key)) throw new Error('mutation-ignore: duplicate entry');
    seen.add(key);
  }
  return entries;
}

function parseReport(text) {
  const report = JSON.parse(text);
  if (!report || !report.stats || !Number.isInteger(report.stats.totalMutantsCount) || report.stats.totalMutantsCount < 0) {
    throw new Error('Infection report: missing or invalid stats');
  }
  let count = 0;
  for (const [group, metric] of Object.entries(GROUPS)) {
    if (!Array.isArray(report[group]) || report.stats[metric] !== report[group].length) {
      throw new Error(`Infection report: invalid ${group} count`);
    }
    count += report[group].length;
    for (const row of report[group]) {
      const m = row?.mutator;
      if (!m || typeof m.originalFilePath !== 'string' || !m.originalFilePath.startsWith(`${PLUGIN_IN_CONTAINER}/`)
          || m.originalFilePath.includes('/../') || !m.originalFilePath.endsWith('.php')
          || !Number.isInteger(m.originalStartLine) || m.originalStartLine < 1
          || (m.originalEndLine !== undefined && (!Number.isInteger(m.originalEndLine) || m.originalEndLine < m.originalStartLine))
          || typeof m.mutatorName !== 'string' || !m.mutatorName
          || typeof m.originalSourceCode !== 'string' || typeof m.mutatedSourceCode !== 'string'
          || typeof row.diff !== 'string' || typeof row.processOutput !== 'string') {
        throw new Error(`Infection report: invalid ${group} mutant`);
      }
    }
  }
  if (report.stats.skippedCount > 0) {
    throw new Error(`Infection report: ${report.stats.skippedCount} mutants were not executed; timeout must exceed the measured covering-test time`);
  }
  if (report.stats.skippedCount !== 0 || count !== report.stats.totalMutantsCount) {
    throw new Error('Infection report: incomplete mutation results');
  }
  return report;
}

function identity(m) {
  return {
    file: repoPath(m.originalFilePath), line: m.originalStartLine, mutator: m.mutatorName,
    hash: crypto.createHash('sha256').update(JSON.stringify([m.originalSourceCode, m.mutatedSourceCode])).digest('hex'),
  };
}

/** Verify the recorded source manifest rather than trusting its claimed digest. */
function parseEnvironment(text) {
  const environment = JSON.parse(text);
  const sources = environment?.sources;
  const coreSupport = new Set(['lib/classes/xml_parser.php', 'lib/db/install.xml', 'lib/setup.php', 'lib/filelib.php',
    'lib/dml/moodle_database.php', 'lib/dml/mysqli_native_moodle_database.php', 'lib/dml/pgsql_native_moodle_database.php']);
  if (!sources || typeof sources !== 'object' || Array.isArray(sources) || Object.keys(sources).length === 0
      || Object.entries(sources).some(([file, hash]) => file.includes('..')
        || (!(file.startsWith('question/') && file.endsWith('.php')) && !coreSupport.has(file))
        || !/^[a-f0-9]{64}$/.test(hash))) {
    throw new Error('Infection environment: invalid question-type source manifest');
  }
  const sorted = Object.fromEntries(Object.entries(sources).sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0));
  const digest = crypto.createHash('sha256').update(JSON.stringify(sorted)).digest('hex');
  if (environment.qtypeSourceHash !== digest) throw new Error('Infection environment: source manifest digest mismatch');
  return environment;
}

function evaluate(report, changed, ignores, diffSelected = false, environment = null, sourceHashes = {}) {
  // A Moodle bootstrap fatal means PHPUnit never ran the mutant: that is no kill.
  const bootstrapKills = report.killed.filter(row => /^\s*Fatal error: \$CFG->/m.test(row.processOutput));
  const findings = [...report.errored, ...report.syntaxErrors, ...bootstrapKills].map(row => ({
    file: repoPath(row.mutator.originalFilePath), line: row.mutator.originalStartLine,
    rule: 'mutation-error', text: `${row.mutator.mutatorName}: ${row.processOutput.slice(-1000).replace(/\s+/g, ' ')}`,
  }));
  for (const row of report.escaped) {
    const id = identity(row.mutator);
    const ranges = changed.get(id.file);
    if (!ranges) continue;
    if (ignores.some(e => Object.keys(id).every(key => e[key] === id[key])
        && (e.qtypeSourceHash === undefined || e.qtypeSourceHash === environment?.qtypeSourceHash)
        && (e.sourceHashes === undefined || Object.entries(e.sourceHashes).every(([file, hash]) => sourceHashes[file] === hash)))) continue;
    // Native --git-diff-lines already checked the real AST start and end: never filter it twice.
    if (!diffSelected && ranges !== 'all') {
      const end = row.mutator.originalEndLine;
      if (end === undefined) {
        findings.push({ file: id.file, line: id.line, rule: 'mutation-range-unknown', text: 'Report lacks original AST end line; run native diff mutation instead of inferring a span from formatted source' });
        continue;
      }
      if (!ranges.some(([a, b]) => a <= end && b >= id.line)) continue;
    }
    findings.push({ file: id.file, line: id.line, rule: 'mutation-survived', text: `${id.mutator} hash=${id.hash}` });
  }
  return findings;
}

module.exports = { parseIgnores, parseReport, parseEnvironment, parseSourceHashes, identity, evaluate };
