'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const m = require('../scripts/gate/mutation-matrix');

const junit = `<testsuites><testsuite name="a"><testsuite name="ns\\foo_test">
<testcase name="test_ok" file="f" line="1" class="ns\\foo_test" classname="ns.foo_test" assertions="1" time="0.1"/>
<testcase name="test_fail" file="f" line="2" class="ns\\foo_test" classname="ns.foo_test" assertions="1" time="0.1"><failure type="X">boom &quot;q&quot;</failure></testcase>
<testcase name="test_err with data set #1" file="f" line="3" class="ns\\bar_test" classname="ns.bar_test" assertions="0" time="0.1"><error type="E">x</error></testcase>
<testcase name="test_err with data set #2" file="f" line="3" class="ns\\bar_test" classname="ns.bar_test" assertions="0" time="0.1"/>
</testsuite></testsuite></testsuites>`;

test('junit: ran, assertion failures and errors apart, data sets merged to the method', () => {
  assert.deepEqual(m.parseJunit(junit), { ran: ['bar_test::test_err', 'foo_test::test_fail', 'foo_test::test_ok'], failures: ['foo_test::test_fail'], errors: ['bar_test::test_err'] });
});

test('triggers: warnings and deprecations come from the text output, not from JUnit', () => {
  const out = '1 test triggered 1 PHP warning:\n\n1) /v/classes/x.php:52\nUndefined array key "a"\n\nTriggered by:\n\n* local_coursepilot\\x_test::test_a\n  /v/tests/x_test.php:10\n\n'
    + '2 tests triggered 2 deprecations:\n\n1) /v/y.php:1\nold\n\nTriggered by:\n\n* ns\\y_test::test_b with data set #0\n  /v/t.php:1\n\n* ns\\y_test::test_c\n  /v/t.php:2\n';
  assert.deepEqual(m.parseTriggers(out), [{ test: 'x_test::test_a', kind: 'php warning' }, { test: 'y_test::test_b', kind: 'deprecation' }, { test: 'y_test::test_c', kind: 'deprecation' }]);
  assert.deepEqual(m.parseTriggers('OK (3 tests, 5 assertions)'), []);
});

test('row: kinds stay separate; a kill by warning alone names the warning tests as killers', () => {
  const warn = m.buildRow({ file: 'f', line: 1, mutator: 'M' }, 'killed', { junit: junit.replace(/<failure[\s\S]*?<\/failure>/, '').replace(/<error[\s\S]*?<\/error>/, ''), output: '1 test triggered 1 PHP warning:\n\nTriggered by:\n\n* ns\\foo_test::test_ok\n  /x\n' });
  assert.deepEqual(m.killers(warn), { strong: [], weak: ['foo_test::test_ok'] });
  const both = m.buildRow({ file: 'f', line: 1, mutator: 'M' }, 'killed', { junit, output: '' });
  assert.deepEqual(m.killers(both).strong, ['bar_test::test_err', 'foo_test::test_fail']);
  assert.equal(both.assertionFailures.length, 1);
  assert.equal(both.errors.length, 1);
});

test('row: missing JUnit or a timeout means the failing set is unknown, not empty', () => {
  assert.equal(m.buildRow({}, 'killed', { junit: null, output: 'Fatal error' }).complete, false);
  assert.equal(m.buildRow({}, 'timeout', { junit, output: '' }).complete, false);
  assert.equal(m.buildRow({}, 'uncovered', null).complete, true);
  assert.equal(m.buildRow({}, 'escaped', null).complete, false);
});

const T = (cls, extra = {}) => ({ cls, time: 1, source: '', fileSource: '', ...extra });
const kill = (id, failing, ran = failing) => ({ file: 'f', line: id, mutator: 'M', status: 'killed', ran, assertionFailures: failing, errors: [], warnings: [], risky: [], complete: true });

test('proposal: tier 1 kills nothing, tier 2 needs a remaining killer for each of its mutants, never removes the last killer', () => {
  const tests = new Map([['a_test::t', T('ns\\a_test')], ['b_test::t', T('ns\\b_test')], ['c_test::t', T('ns\\c_test')], ['z_test::t', T('ns\\z_test')]]);
  const rows = [kill(1, ['a_test::t', 'b_test::t']), kill(2, ['a_test::t', 'b_test::t']), kill(3, ['c_test::t'], ['c_test::t', 'z_test::t'])];
  const p = m.proposal(tests, rows);
  assert.deepEqual(p.tier1.map(t => t.key), ['z_test::t']);
  assert.equal(p.tier1[0].neverRan, false);
  assert.deepEqual(p.tier2.map(t => t.key), ['a_test::t']); // b stays: a was removed first
  assert.deepEqual(p.kept.sort(), ['b_test::t', 'c_test::t']);
  assert.deepEqual(p.violations, []);
});

test('proposal: contract tests (class and body evidence) and subprocess suspects are never proposed', () => {
  const tests = new Map([
    ['privacy_surface_test::t', T('ns\\privacy_surface_test')],
    ['reg_test::t', T('ns\\reg_test', { source: '$x = file_get_contents($CFG->dirroot . "/local/coursepilot/db/services.php");' })],
    ['proc_test::t', T('ns\\proc_test', { fileSource: 'require "tests/fixtures/phpunit_process_bootstrap.php";' })],
    ['plain_test::t', T('ns\\plain_test')],
  ]);
  const p = m.proposal(tests, []);
  assert.deepEqual(p.exempt.map(e => e.key), ['privacy_surface_test::t', 'reg_test::t']);
  assert.deepEqual(p.subprocess.map(e => e.key), ['proc_test::t']);
  assert.deepEqual(p.tier1.map(t => t.key), ['plain_test::t']);
  assert.equal(p.tier1[0].neverRan, true);
});

test('proposal: a mutant killed without attribution protects every test that ran on it and marks the result incomplete', () => {
  const tests = new Map([['a_test::t', T('ns\\a_test')], ['b_test::t', T('ns\\b_test')]]);
  const p = m.proposal(tests, [kill(1, [], ['a_test::t'])]);
  assert.deepEqual(p.tier1.map(t => t.key), ['b_test::t']);
  assert.equal(p.incomplete.length, 1);
});

test('summary: kill kinds and killers per mutant counted separately', () => {
  const rows = [kill(1, ['a::t', 'b::t']), kill(2, ['a::t']), { ...kill(3, []), warnings: ['php warning: a::t'] }, { ...kill(4, []), assertionFailures: [], errors: ['a::t'] }, { status: 'uncovered', ran: [], assertionFailures: [], errors: [], warnings: [], complete: true }];
  const s = m.summarizeMatrix(rows);
  assert.equal(s.killedByAssertion, 2);
  assert.equal(s.killedByWarningOnly, 1);
  assert.equal(s.killedByErrorOnly, 1);
  assert.equal(s.uncovered, 1);
  assert.deepEqual(s.killersPerMutant, { 0: 1, 1: 2, 2: 1 });
});

test('collect: maps wrapper artefacts to report mutants by the mutated source', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'matrix-'));
  fs.writeFileSync(path.join(dir, 'h1.php'), '<?php // mutated');
  fs.writeFileSync(path.join(dir, 'h1.junit.xml'), junit);
  fs.writeFileSync(path.join(dir, 'h1.out'), '');
  fs.writeFileSync(path.join(dir, 'h9.php'), '<?php // orphan');
  const mutation = { identity: r => ({ file: r.file, line: r.line, mutator: r.name }) };
  const mutant = (code, file) => ({ mutator: { mutatedSourceCode: code, file, line: 3, name: 'N' }, processOutput: '' });
  const { rows, unmatchedArtefacts } = m.collect({ killed: [mutant('<?php // mutated', 'a.php')], uncovered: [mutant('<?php // none', 'b.php')] }, dir, mutation);
  assert.equal(rows.length, 2);
  assert.deepEqual(rows[0].assertionFailures, ['foo_test::test_fail']);
  assert.equal(rows[0].mutant, 'h1');
  assert.equal(rows[1].status, 'uncovered');
  assert.deepEqual(unmatchedArtefacts, ['h9']);
  fs.rmSync(dir, { recursive: true });
});

test('wrapper: stop flags off, JUnit and mutant kept, PHPUnit exit code passed through', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'wrap-'));
  const script = fs.readFileSync(path.join(__dirname, '..', 'scripts/gate/mutation-allfail.sh'), 'utf8');
  const fake = path.join(dir, 'phpunit');
  fs.writeFileSync(fake, '#!/bin/bash\necho "ran $*"\nexit 3\n', { mode: 0o755 });
  fs.writeFileSync(path.join(dir, 'wrapper.sh'), script.replace('/var/www/html/vendor/bin/phpunit', fake), { mode: 0o755 });
  fs.writeFileSync(path.join(dir, 'phpunitConfiguration.abc.infection.xml'), '<phpunit stopOnDefect="true" stopOnFailure="true" colors="true"/>');
  fs.writeFileSync(path.join(dir, 'mutant.abc.infection.php'), '<?php // m');
  const r = spawnSync('bash', [path.join(dir, 'wrapper.sh'), '--configuration', path.join(dir, 'phpunitConfiguration.abc.infection.xml')], { encoding: 'utf8' });
  assert.equal(r.status, 3);
  assert.match(r.stdout, /--log-junit .*data\/abc\.junit\.xml/);
  assert.match(fs.readFileSync(path.join(dir, 'phpunitConfiguration.abc.infection.xml'), 'utf8'), /stopOnDefect="false" stopOnFailure="false" colors="true"/);
  assert.equal(fs.readFileSync(path.join(dir, 'data', 'abc.php'), 'utf8'), '<?php // m');
  assert.match(fs.readFileSync(path.join(dir, 'data', 'abc.out'), 'utf8'), /^ran /);
  fs.rmSync(dir, { recursive: true });
});
