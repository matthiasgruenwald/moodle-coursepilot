'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { partition, shardCount, mergeReports, shardKey } = require('../scripts/gate/mutation-shard');
const mutation = require('../scripts/gate/mutation');

const ROOT = path.resolve(__dirname, '..');
const base = { requested: 2, files: 6, weight: 600, minWeight: 200, availableMb: 12000, minMemMb: 4096, freeDiskMb: 9000, existing: 0 };

test('shards: files are split by changed lines into balanced lists without losing or duplicating one', () => {
  const weights = [['a', 100], ['b', 90], ['c', 60], ['d', 50], ['e', 10]];
  const two = partition(weights, 2);
  assert.deepEqual(two.flat().sort(), ['a', 'b', 'c', 'd', 'e']);
  const sums = two.map(l => l.reduce((n, f) => n + weights.find(w => w[0] === f)[1], 0));
  assert.ok(Math.abs(sums[0] - sums[1]) <= 20, sums.join('/'));
  assert.deepEqual(partition(weights, 1), [['a', 'b', 'c', 'd', 'e']]);
  assert.deepEqual(partition([['only', 5]], 3), [['only']], 'never an empty shard');
  assert.deepEqual(partition(weights, 2), partition([...weights].reverse(), 2), 'deterministic for any input order');
});

test('shards: one instance when one is requested, work is small or there is a single file', () => {
  assert.equal(shardCount({ ...base, requested: 1 }).count, 1);
  assert.match(shardCount({ ...base, weight: 199 }).note, /too little work/);
  assert.equal(shardCount({ ...base, weight: 200 }).count, 2);
  assert.equal(shardCount({ ...base, files: 1 }).count, 1);
  assert.equal(shardCount({ ...base, requested: 3, files: 2 }).count, 2, 'never more instances than files');
});

test('shards: memory and disk reduce the instance count with the reason', () => {
  assert.equal(shardCount({ ...base, requested: 3, availableMb: 20000 }).count, 3);
  const memory = shardCount({ ...base, requested: 3, availableMb: 4096 + 5120 });
  assert.equal(memory.count, 2);
  assert.match(memory.note, /memory \(9216 MB available\)/);
  assert.equal(shardCount({ ...base, availableMb: 4096 + 5119 }).count, 1);
  assert.equal(shardCount({ ...base, availableMb: null }).count, 2, 'unreadable meminfo does not block');
  const disk = shardCount({ ...base, requested: 3, availableMb: 20000, freeDiskMb: 1500 + 2500 });
  assert.equal(disk.count, 2);
  assert.match(disk.note, /disk/);
  assert.equal(shardCount({ ...base, freeDiskMb: 1000 }).count, 1);
  assert.equal(shardCount({ ...base, freeDiskMb: 1000, existing: 1 }).count, 2, 'an instance that already exists needs no new disk');
});

function report(rows) {
  const group = { escaped: [], killed: [], timeouted: [], errored: [], syntaxErrors: [], uncovered: [], ignored: [] };
  rows.forEach(([g, file, line]) => group[g].push({
    mutator: { mutatorName: 'IncrementInteger', originalFilePath: `/var/www/html/public/local/coursepilot/${file}`, originalStartLine: line, originalEndLine: line, originalSourceCode: '1', mutatedSourceCode: '2' },
    diff: 'd', processOutput: 'out',
  }));
  const stats = { totalMutantsCount: rows.length, killedCount: group.killed.length, escapedCount: group.escaped.length, errorCount: 0, syntaxErrorCount: 0,
    notCoveredCount: group.uncovered.length, skippedCount: 0, ignoredCount: 0, timeOutCount: 0 };
  return { stats, ...group };
}

test('shards: merged shard reports equal the serial report and pass the same validation and evaluation', () => {
  const rows = [['killed', 'classes/a.php', 2], ['escaped', 'classes/a.php', 3], ['uncovered', 'classes/b.php', 4], ['killed', 'classes/c.php', 5], ['escaped', 'classes/c.php', 6]];
  const serial = report(rows);
  const merged = mergeReports([report(rows.filter(r => r[1] !== 'classes/c.php')), report(rows.filter(r => r[1] === 'classes/c.php'))]);
  assert.deepEqual(merged.stats, serial.stats);
  for (const g of ['escaped', 'killed', 'uncovered']) {
    assert.deepEqual(merged[g].map(m => m.mutator.originalStartLine).sort(), serial[g].map(m => m.mutator.originalStartLine).sort());
  }
  const changed = new Map([['Plugin/src/local_coursepilot/classes/a.php', 'all'], ['Plugin/src/local_coursepilot/classes/c.php', 'all']]);
  const evaluate = r => mutation.evaluate(mutation.parseReport(JSON.stringify(r)), changed, [], true).map(f => `${f.file}:${f.line}:${f.rule}`).sort();
  assert.deepEqual(evaluate(merged), evaluate(serial));
  assert.equal(evaluate(merged).length, 2);
});

test('shards: a shard with skipped mutants keeps the merged report invalid', () => {
  const bad = report([['killed', 'classes/a.php', 1]]);
  bad.stats.skippedCount = 1;
  assert.throws(() => mutation.parseReport(JSON.stringify(mergeReports([report([]), bad]))), /not executed/);
});

test('shards: a result is reusable only for the same tree, base, files and coverage mode', () => {
  const key = { base: 'b', treeDigest: 't', reuseCoverage: true, files: ['a'] };
  assert.equal(shardKey(key), shardKey({ ...key }));
  for (const change of [{ base: 'c' }, { treeDigest: 'u' }, { reuseCoverage: false }, { files: ['a', 'b'] }]) {
    assert.notEqual(shardKey({ ...key, ...change }), shardKey(key), JSON.stringify(change));
  }
});

test('shard runner: jobs run concurrently, only a successful job leaves its done marker, codes come back in order', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-shard-run-'));
  try {
    const job = (name, script, key) => ({ argv: ['sh', '-c', script], log: path.join(dir, `${name}.log`), done: path.join(dir, `${name}.done`), key });
    // Each overlapping job waits for the other one's start file; run one after the other, it times out with exit 9.
    const meet = (me, other, text) => `touch ${dir}/${me}.up; i=0; until [ -f ${dir}/${other}.up ]; do i=$((i+1)); [ $i -gt 200 ] && exit 9; sleep 0.05; done; echo ${text}`;
    const r = spawnSync('node', [path.join(ROOT, 'scripts/gate/mutation-shard-run.js')], {
      input: JSON.stringify([job('a', meet('a', 'b', 'A'), 'ka'), job('b', `${meet('b', 'a', 'B')}; exit 3`, 'kb'), job('c', 'echo C', 'kc')]), encoding: 'utf8',
    });
    assert.equal(r.status, 0, r.stderr);
    assert.deepEqual(JSON.parse(r.stdout), [0, 3, 0], 'a and b ran at the same time');
    assert.equal(fs.readFileSync(path.join(dir, 'a.done'), 'utf8'), 'ka');
    assert.equal(fs.existsSync(path.join(dir, 'b.done')), false);
    assert.equal(fs.readFileSync(path.join(dir, 'b.log'), 'utf8'), 'B\n');
    assert.deepEqual(fs.readdirSync(dir).filter(f => f.endsWith('.tmp')), []);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
