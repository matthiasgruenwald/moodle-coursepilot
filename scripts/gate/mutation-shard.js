/**
 * Sharding of the local mutation run over private gate instances (own container, database and
 * PHPUnit data each). Files are the unit: Infection results per file do not depend on other files,
 * so the merged report equals the serial one.
 */
'use strict';

const crypto = require('node:crypto');

// Container limits of one extra instance (mutation-full-instance.sh): web 3 GB + database 2 GB.
const INSTANCE_MEMORY_MB = 5120;
const INSTANCE_DISK_MB = 2500;
const DISK_RESERVE_MB = 1500;
const COUNTS = ['totalMutantsCount', 'killedCount', 'escapedCount', 'errorCount', 'syntaxErrorCount', 'notCoveredCount', 'skippedCount', 'ignoredCount', 'timeOutCount'];
const GROUPS = ['escaped', 'killed', 'timeouted', 'errored', 'syntaxErrors', 'uncovered', 'ignored'];

/** Greedy longest-first split of weighted files into at most `n` balanced, non-empty lists. */
function partition(weights, n) {
  const shards = Array.from({ length: Math.max(1, n) }, () => ({ files: [], weight: 0 }));
  for (const [file, weight] of [...weights].sort((a, b) => b[1] - a[1] || (a[0] < b[0] ? -1 : 1))) {
    const lightest = shards.reduce((a, b) => (b.weight < a.weight ? b : a));
    lightest.files.push(file);
    lightest.weight += weight;
  }
  return shards.filter(s => s.files.length > 0).map(s => s.files.sort());
}

/**
 * How many instances this run may use: the requested number, reduced by too little work, memory or disk.
 * @returns {{count: number, note: string}}
 */
function shardCount({ requested, files, weight, minWeight, availableMb, minMemMb, freeDiskMb, existing = 0 }) {
  if (requested <= 1) return { count: 1, note: 'single instance requested' };
  if (weight < minWeight || files < 2) return { count: 1, note: `too little work to split (${weight} changed lines in ${files} files, minimum ${minWeight})` };
  const byFiles = Math.min(requested, files);
  const byMemory = availableMb === null ? byFiles : 1 + Math.max(0, Math.floor((availableMb - minMemMb) / INSTANCE_MEMORY_MB));
  const spare = Math.max(0, freeDiskMb - DISK_RESERVE_MB);
  const byDisk = 1 + existing + Math.floor(spare / INSTANCE_DISK_MB);
  const count = Math.max(1, Math.min(byFiles, byMemory, byDisk));
  const limits = [];
  if (byMemory < byFiles) limits.push(`memory (${availableMb} MB available)`);
  if (byDisk < byFiles) limits.push(`disk (${freeDiskMb} MB free)`);
  return { count, note: limits.length > 0 ? `reduced to ${count} by ${limits.join(' and ')}` : `${count} instances` };
}

/** One report from the shard reports: counts add up, mutants concatenate. */
function mergeReports(reports) {
  const merged = { stats: Object.fromEntries(COUNTS.map(c => [c, reports.reduce((n, r) => n + (r.stats[c] || 0), 0)])) };
  for (const group of GROUPS) merged[group] = reports.flatMap(r => r[group]);
  return merged;
}

/** Identity of a shard result: reusable only for the very same tree, base, file split and rules. */
function shardKey(parts) {
  return crypto.createHash('sha256').update(JSON.stringify(parts)).digest('hex');
}

module.exports = { partition, shardCount, mergeReports, shardKey, INSTANCE_MEMORY_MB, INSTANCE_DISK_MB };
