'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const { treeDigest, changeOf, planSections, killedByFromOutput, coveredTestsFromLog, testsFromJunit, summarize, pruneCandidates, testKey } = require('../scripts/gate/mutation-full');

test('sections: one per directory, large directories chunked, root files together', () => {
  const files = ['lib.php', 'db/a.php', 'classes/x.php', 'classes/catalog/a.php',
    ...Array.from({ length: 12 }, (_, i) => `classes/external/e${String(i).padStart(2, '0')}.php`)];
  const sections = planSections(files);
  assert.deepEqual(sections.map(s => s.name), ['classes-catalog', 'classes-external-01', 'classes-external-02', 'classes', 'db', 'root']);
  assert.equal(sections[2].files.length, 2);
  assert.equal(sections.flatMap(s => s.files).length, files.length);
});

test('killedBy: names the failing test methods without namespace and data set', () => {
  const out = 'There were 2 failures:\n\n1) local_coursepilot\\a\\foo_test::test_one with data set "x"\nFailed.\n\n2) local_coursepilot\\foo_test::test_two\nFailed.\n';
  assert.deepEqual(killedByFromOutput(out), ['foo_test::test_one', 'foo_test::test_two']);
  assert.deepEqual(killedByFromOutput('Fatal error: boom'), []);
});

test('coveredBy: union of the --filter regexes in the debug log', () => {
  const log = "$ 'phpunit' '--filter' '/foo_test\\:\\:test_a|foo_test\\:\\:test_b with data set \"q\"|bar_test\\:\\:test_c' '--x'\n"
    + "$ 'phpunit' '--filter' '/foo_test\\:\\:test_a' '--x'\n";
  assert.deepEqual(coveredTestsFromLog(log), ['bar_test::test_c', 'foo_test::test_a', 'foo_test::test_b']);
});

test('junit: test methods keyed like killedBy, data sets merged', () => {
  const xml = '<testcase name="test_a" file="f" line="1" class="ns\\foo_test" classname="ns.foo_test"/>'
    + '<testcase name="test_b with data set &quot;q&quot;" file="f" line="2" class="ns\\foo_test" classname="ns.foo_test"/>';
  assert.deepEqual([...testsFromJunit(xml)], [['foo_test::test_a', 'ns\\foo_test'], ['foo_test::test_b', 'ns\\foo_test']]);
  assert.equal(testKey('a\\b_test', 'm with data set #1'), 'b_test::m');
});

test('summary: timeouts count as killed, MSI trend over all and over covered mutants', () => {
  const rows = [{ status: 'killed', killedBy: ['a::b'] }, { status: 'timeout' }, { status: 'escaped', ignored: true }, { status: 'escaped', ignored: false }, { status: 'uncovered' }];
  assert.deepEqual(summarize(rows), { total: 5, killed: 2, timeout: 1, escaped: 2, escapedIgnored: 1, uncovered: 1, unattributed: 0, error: 0, skipped: 0, msi: 40, coveredMsi: 50 });
});

test('prune: only a list; killers kept, contract tests exempt with a reason, never-covering split off', () => {
  const all = new Map([['k_test::t', 'ns\\k_test'], ['a_test::t', 'ns\\a_test'], ['b_test::t', 'ns\\b_test'], ['p_test::t', 'ns\\privacy\\p_test'], ['install_smoke_test::t', 'ns\\install_smoke_test']]);
  const rows = [{ status: 'killed', killedBy: ['k_test::t'] }];
  const result = pruneCandidates(all, rows, new Set(['b_test::t']));
  assert.deepEqual(result.neverCovers, ['a_test::t']);
  assert.deepEqual(result.coversNoKill, ['b_test::t']);
  assert.deepEqual(result.exempt.map(e => e.key), ['install_smoke_test::t', 'p_test::t']);
});

test('change: shows only the differing region with short context', () => {
  const m = { originalSourceCode: '<?php\nfoo(1);\n$x = [\'a\' => 1, \'b\' => 2];\nbar();\n', mutatedSourceCode: '<?php\nfoo(1);\n$x = [\'a\' => 1];\nbar();\n' };
  assert.equal(changeOf(m), "$x = ['a' => 1, 'b' => 2]; => $x = ['a' => 1];");
});

test('tree digest: content and paths count, caches and generated phpunit.xml do not', () => {
  const fs = require('node:fs');
  const os = require('node:os');
  const path = require('node:path');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'digest-'));
  fs.mkdirSync(path.join(dir, 'classes'));
  fs.writeFileSync(path.join(dir, 'classes', 'a.php'), '<?php // a');
  const base = treeDigest(dir);
  fs.mkdirSync(path.join(dir, '.phpunit.cache'));
  fs.writeFileSync(path.join(dir, '.phpunit.cache', 'x'), 'cache');
  fs.writeFileSync(path.join(dir, 'phpunit.xml'), '<generated/>');
  assert.equal(treeDigest(dir), base);
  fs.writeFileSync(path.join(dir, 'classes', 'a.php'), '<?php // changed');
  assert.notEqual(treeDigest(dir), base);
  fs.writeFileSync(path.join(dir, 'classes', 'a.php'), '<?php // a');
  fs.writeFileSync(path.join(dir, 'classes', 'b.php'), '');
  assert.notEqual(treeDigest(dir), base);
  fs.rmSync(dir, { recursive: true });
});

test('coveredBy: real log format ends the filter with slash and quote, the last test keeps its name', () => {
  const log = "$ '/phpunit' '--configuration' 'c.xml' '--filter' '/foo_test\\:\\:test_a|bar_test\\:\\:test_last/' '--stop-on-failure'\n";
  assert.deepEqual(coveredTestsFromLog(log), ['bar_test::test_last', 'foo_test::test_a']);
});
