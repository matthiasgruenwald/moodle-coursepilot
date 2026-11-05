'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const ROOT = path.resolve(__dirname, '..');
const FILE = 'Plugin/src/local_coursepilot/classes/a.php';

function fixture() {
  const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-mutation-'));
  const write = (name, data) => {
    fs.mkdirSync(path.dirname(path.join(repo, name)), { recursive: true });
    fs.writeFileSync(path.join(repo, name), data);
  };
  const git = (...args) => {
    const r = spawnSync('git', ['-c', 'user.name=test', '-c', 'user.email=test@example.org', ...args], { cwd: repo, encoding: 'utf8' });
    assert.equal(r.status, 0, r.stderr);
  };
  write('scripts/gate/baseline.json', JSON.stringify({ coverage: { covered: 1, statements: 1 }, excluded: [] }));
  write('scripts/gate/mutation-ignore.json', '[]');
  write(FILE, '<?php\nreturn 1;\n');
  git('init', '-q');
  git('add', '.');
  git('commit', '-qm', 'base');
  write(FILE, '<?php\nreturn 2;\n');
  const escaped = {
    mutator: { mutatorName: 'IncrementInteger', originalFilePath: '/var/www/html/public/local/coursepilot/classes/a.php', originalStartLine: 2, originalEndLine: 2, originalSourceCode: '2', mutatedSourceCode: '3' },
    diff: '--- Original\n+++ New\n@@ @@\n-return 2;\n+return 3;', processOutput: 'OK (1 test, 1 assertion)',
  };
  const report = { stats: { totalMutantsCount: 1, escapedCount: 1, killedCount: 0, notCoveredCount: 0, errorCount: 0, syntaxErrorCount: 0, skippedCount: 0, ignoredCount: 0, timeOutCount: 0 }, escaped: [escaped], killed: [], timeouted: [], errored: [], syntaxErrors: [], uncovered: [], ignored: [] };
  write('infection.json', JSON.stringify(report));
  const run = () => spawnSync('node', [path.join(ROOT, 'scripts/gate/gate.js'), 'mutation-report', path.join(repo, 'infection.json')], {
    cwd: repo, encoding: 'utf8', env: { ...process.env, GATE_REPO: repo, GATE_BASE_REF: 'HEAD', GATE_FAILURE_LOG: path.join(repo, 'failures.log') },
  });
  return { repo, write, run, report, escaped };
}

test('Gate CLI blocks an escaped mutant on a changed line', () => {
  const f = fixture();
  try {
    const r = f.run();
    assert.equal(r.status, 1);
    assert.match(r.stdout, /classes\/a.php:2: mutation-survived:/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI accepts only an exact equivalent mutant with a reason', () => {
  const f = fixture();
  try {
    const { createHash } = require('node:crypto');
    const ignore = { file: FILE, line: 2, mutator: 'IncrementInteger', hash: createHash('sha256').update('["2","3"]').digest('hex'), reason: 'Fixture: this value is intentionally not part of the public result.' };
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    assert.equal(f.run().status, 0);
    delete ignore.reason;
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    const r = f.run();
    assert.equal(r.status, 1);
    assert.match(r.stdout, /mutation-ignore:.*reason/);
    ignore.reason = '   ';
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    assert.equal(f.run().status, 1);
    ignore.reason = 'Same location, different mutation does not apply.';
    ignore.hash = 'a'.repeat(64);
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    assert.match(f.run().stdout, /mutation-survived/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI binds finite repository proofs to the original dependency sources', () => {
  const f = fixture();
  try {
    const { createHash } = require('node:crypto');
    const dependency = 'Plugin/src/local_coursepilot/classes/catalog/page.php';
    const ignore = { file: FILE, line: 2, mutator: 'IncrementInteger',
      hash: createHash('sha256').update('["2","3"]').digest('hex'),
      reason: 'Fixture proof requires this exact repository dependency.',
      sourceHashes: { [dependency]: 'a'.repeat(64) } };
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    f.write('infection-sources.json', JSON.stringify({ [dependency]: 'a'.repeat(64) }));
    assert.equal(f.run().status, 0);
    f.write('infection-sources.json', JSON.stringify({ [dependency]: 'b'.repeat(64) }));
    assert.match(f.run().stdout, /mutation-survived/);
    fs.unlinkSync(path.join(f.repo, 'infection-sources.json'));
    assert.equal(f.run().status, 1, 'Missing dependency provenance must keep the survivor blocking');
    f.write('infection-sources.json', JSON.stringify({ [dependency]: 'invalid' }));
    assert.match(f.run().stdout, /Infection sources: invalid/);
    ignore.sourceHashes = { '../outside.php': 'a'.repeat(64) };
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    assert.match(f.run().stdout, /mutation-ignore:.*sourceHashes/);
    ignore.sourceHashes = { 'Plugin/src/local_coursepilot/tests/example_test.php': 'a'.repeat(64) };
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    assert.match(f.run().stdout, /mutation-ignore:.*sourceHashes/);
    ignore.sourceHashes = {};
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    assert.match(f.run().stdout, /mutation-ignore:.*sourceHashes/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI reports legacy survivors as trend and rejects incomplete reports', () => {
  const f = fixture();
  try {
    f.report.escaped[0].mutator.originalStartLine = 1;
    f.report.escaped[0].mutator.originalEndLine = 1;
    f.write('infection.json', JSON.stringify(f.report));
    assert.equal(f.run().status, 0);
    f.write('infection.json', JSON.stringify({ stats: { totalMutantsCount: 0 } }));
    assert.equal(f.run().status, 1);
    f.write('infection.json', '{');
    assert.equal(f.run().status, 1);
    fs.unlinkSync(path.join(f.repo, 'infection.json'));
    assert.equal(f.run().status, 1);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI does not treat the whole original source file as a mutant span', () => {
  const f = fixture();
  try {
    f.escaped.mutator.originalStartLine = 1;
    f.escaped.mutator.originalEndLine = 1;
    f.escaped.mutator.originalSourceCode = '<?php\nreturn 2;\n';
    f.escaped.mutator.mutatedSourceCode = '<?php /* changed */\nreturn 2;\n';
    f.write('infection.json', JSON.stringify(f.report));
    assert.equal(f.run().status, 0);
    f.escaped.mutator.originalSourceCode = 'return some(\n    2\n);';
    f.escaped.mutator.mutatedSourceCode = 'return none();';
    f.escaped.mutator.originalEndLine = 3;
    f.write('infection.json', JSON.stringify(f.report));
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stdout, /mutation-survived/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI blocks a surviving node that starts before the changed line', () => {
  const f = fixture();
  try {
    f.escaped.mutator.originalStartLine = 1;
    f.escaped.mutator.originalEndLine = 2;
    f.escaped.mutator.originalSourceCode = '<?php\nreturn match ($x) {\n    1 => 2,\n    default => 3\n};\n';
    f.escaped.mutator.mutatedSourceCode = '<?php\nreturn match ($x) {\n    default => 3\n};\n';
    f.write('infection.json', JSON.stringify(f.report));
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stdout, /mutation-survived/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI blocks errored mutant processes and retains their cause', () => {
  const f = fixture();
  try {
    f.report.escaped = [];
    f.report.errored = [{ ...f.escaped, processOutput: 'PHP worker terminated unexpectedly: exit 143' }];
    f.report.stats.escapedCount = 0;
    f.report.stats.errorCount = 1;
    f.write('infection.json', JSON.stringify(f.report));
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stdout, /mutation-error:.*exit 143/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI rejects a mutant killed only by a Moodle bootstrap failure', () => {
  const f = fixture();
  try {
    f.report.escaped = [];
    f.report.killed = [{ ...f.escaped, processOutput: 'Fatal error: $CFG->dataroot is not writable, admin has to fix directory permissions! Exiting.' }];
    f.report.stats.escapedCount = 0;
    f.report.stats.killedCount = 1;
    f.write('infection.json', JSON.stringify(f.report));
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stdout, /mutation-error:.*dataroot is not writable/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI keeps a mutant killed by a real failing assertion', () => {
  const f = fixture();
  try {
    f.report.escaped = [];
    f.report.killed = [{ ...f.escaped, processOutput: 'FAILURES!\nTests: 1, Assertions: 1, Failures: 1.' }];
    f.report.stats.escapedCount = 0;
    f.report.stats.killedCount = 1;
    f.write('infection.json', JSON.stringify(f.report));
    assert.equal(f.run().status, 0);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI fails closed when a raw report has no original AST end point', () => {
  const f = fixture();
  try {
    delete f.escaped.mutator.originalEndLine;
    f.write('infection.json', JSON.stringify(f.report));
    const result = f.run();
    assert.equal(result.status, 1);
    assert.match(result.stdout, /mutation-range-unknown:.*AST end line/);
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});

test('Gate CLI applies a qtype-scoped equivalent ignore only to the exact recorded qtype sources', () => {
  const f = fixture();
  try {
    const { createHash } = require('node:crypto');
    const sources = {
      'lib/classes/xml_parser.php': 'b'.repeat(64),
      'lib/db/install.xml': 'c'.repeat(64),
      'lib/dml/moodle_database.php': '1'.repeat(64),
      'lib/dml/mysqli_native_moodle_database.php': '2'.repeat(64),
      'lib/dml/pgsql_native_moodle_database.php': '3'.repeat(64),
      'lib/filelib.php': '4'.repeat(64),
      'lib/setup.php': 'f'.repeat(64),
      'question/type/example/questiontype.php': 'a'.repeat(64),
    };
    const sourceHash = createHash('sha256').update(JSON.stringify(sources)).digest('hex');
    const ignore = {
      file: FILE, line: 2, mutator: 'IncrementInteger',
      hash: createHash('sha256').update('["2","3"]').digest('hex'),
      reason: 'Fixture equivalence is proven only for this exact qtype implementation set.',
      qtypeSourceHash: sourceHash,
    };
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    f.write('infection-environment.json', JSON.stringify({ qtypeSourceHash: sourceHash, sources }));
    assert.equal(f.run().status, 0);
    for (const support of ['lib/classes/xml_parser.php', 'lib/db/install.xml', 'lib/dml/moodle_database.php',
      'lib/dml/mysqli_native_moodle_database.php', 'lib/dml/pgsql_native_moodle_database.php', 'lib/filelib.php', 'lib/setup.php']) {
      const changedSupport = { ...sources, [support]: 'd'.repeat(64) };
      const supportHash = createHash('sha256').update(JSON.stringify(changedSupport)).digest('hex');
      f.write('infection-environment.json', JSON.stringify({ qtypeSourceHash: supportHash, sources: changedSupport }));
      const changed = f.run();
      assert.equal(changed.status, 1, 'Changed parser, question schema or numeric setup must disable the source condition');
      assert.match(changed.stdout, /mutation-survived/);
    }
    const unknownSource = { ...sources, 'lib/arbitrary.php': 'e'.repeat(64) };
    const unknownHash = createHash('sha256').update(JSON.stringify(unknownSource)).digest('hex');
    f.write('infection-environment.json', JSON.stringify({ qtypeSourceHash: unknownHash, sources: unknownSource }));
    assert.match(f.run().stdout, /invalid question-type source manifest/);
    const changedSources = { ...sources, 'question/type/new/questiontype.php': 'b'.repeat(64) };
    f.write('infection-environment.json', JSON.stringify({ qtypeSourceHash: sourceHash, sources: changedSources }));
    assert.match(f.run().stdout, /source manifest digest mismatch/);
    const changedHash = createHash('sha256').update(JSON.stringify(changedSources)).digest('hex');
    f.write('infection-environment.json', JSON.stringify({ qtypeSourceHash: changedHash, sources: changedSources }));
    const changed = f.run();
    assert.equal(changed.status, 1, 'A changed qtype implementation must leave the reported survivor blocking');
    assert.match(changed.stdout, /mutation-survived/);
    fs.unlinkSync(path.join(f.repo, 'infection-environment.json'));
    assert.equal(f.run().status, 1, 'Missing qtype provenance must never grant a conditional ignore');
    ignore.qtypeSourceHash = 'unversioned';
    f.write('scripts/gate/mutation-ignore.json', JSON.stringify([ignore]));
    assert.equal(f.run().status, 1, 'Invalid qtype source conditions must be rejected');
  } finally {
    fs.rmSync(f.repo, { recursive: true, force: true });
  }
});
