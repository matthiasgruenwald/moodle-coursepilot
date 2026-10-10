'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const s = require('../scripts/gate/static');

const FIX = path.join(__dirname, 'fixtures', 'gate', 'static');
const read = name => fs.readFileSync(path.join(FIX, name), 'utf8');
const P = 'Plugin/src/local_coursepilot';

test('moodle-cs: errors and warnings with file, line, sniff; multi-line messages joined', () => {
  const found = s.parsePhpcs(read('phpcs.txt'));
  assert.equal(found.length, 13);
  assert.equal(found.filter(f => f.rule === 'moodle-cs-error').length, 10);
  assert.equal(found.filter(f => f.rule === 'moodle-cs-warning').length, 3);
  assert.deepEqual(found[4], {
    file: `${P}/classes/storage_anchor.php`,
    line: 120,
    rule: 'moodle-cs-warning',
    text: 'Line exceeds 132 characters; contains 141 characters (moodle.Files.LineLength.TooLong)',
  });
  assert.match(found[0].text, /published by"\./);
});

test('phpdoc: findings per function with line', () => {
  const found = s.parsePhpdoc(read('phpdoc.txt'));
  assert.equal(found.length, 3);
  assert.equal(`${found[0].file}:${found[0].line}:${found[0].rule}`, `${P}/classes/storage_anchor.php:207:phpdoc`);
});

test('savepoints: exit code decides', () => {
  assert.deepEqual(s.parseSavepoints('ok', 0), []);
  const found = s.parseSavepoints('versions in savepoint calls are wrong', 1);
  assert.equal(found.length, 1);
  assert.equal(found[0].file, `${P}/db/upgrade.php`);
  assert.equal(found[0].rule, 'savepoints');
});

test('mustache: ERROR and WARNING are findings, INFO and OK are not', () => {
  const found = s.parseMustache(read('mustache.txt'));
  assert.deepEqual(found.map(f => `${f.file}:${f.line}`), [`${P}/templates/a.mustache:12`, `${P}/templates/b.mustache:0`]);
});

test('eslint: stylish findings and stale build files', () => {
  const found = s.parseGrunt(read('grunt.txt'));
  assert.deepEqual(found.map(f => `${f.file}:${f.line}:${f.rule}`), [
    `${P}/amd/src/location_selection.js:12:eslint`,
    `${P}/amd/src/location_selection.js:30:eslint`,
    `${P}/amd/build/location_selection.min.js:0:grunt-stale`,
  ]);
  assert.match(found[0].text, /no-unused-vars/);
});

test('phpstan: json findings and internal tool errors', () => {
  const { found, toolErrors } = s.parsePhpstan(read('phpstan.json'));
  assert.equal(found.length, 2);
  assert.match(found[0].text, /\(variable\.undefined\)$/);
  assert.deepEqual(toolErrors, []);
  assert.equal(s.parsePhpstan('{"totals":{},"files":{},"errors":["Internal error"]}').toolErrors.length, 1);
});

test('covers: lists test classes without a Covers attribute, skips abstract and covered ones', () => {
  const header = '<?php\nnamespace x;\n';
  const found = s.findMissingCovers([
    { file: 'a_test.php', content: `${header}#[CoversClass(a::class)]\nfinal class a_test extends \\advanced_testcase {\n}\n` },
    { file: 'b_test.php', content: `${header}/**\n * Doc.\n */\nclass b_test extends \\advanced_testcase {\n}\n` },
    { file: 'c_test.php', content: `${header}#[\\PHPUnit\\Framework\\Attributes\\CoversNothing]\nfinal class c_test extends \\advanced_testcase {\n}\n` },
    { file: 'd_test.php', content: `${header}abstract class d_test extends \\advanced_testcase {\n}\n` },
    { file: 'e_test.php', content: `${header}#[CoversClass(e::class)]\nclass e_test {\n}\n\nclass f_test {\n}\n` },
  ]);
  assert.deepEqual(found.map(f => `${f.file}:${f.line}`), ['b_test.php:6', 'e_test.php:7']);
  assert.ok(found.every(f => f.rule === 'covers-missing'));
});

test('real plugin tests: every test class declares its coverage', () => {
  const found = s.findMissingCovers(s.readPluginTests(path.join(__dirname, '..')));
  assert.deepEqual(found.map(f => path.basename(f.file)), []);
});

const fakeExec = (map, codeFor = () => 0) => (container, args) => {
  if (args.some(a => a.endsWith('/bin/deptrac'))) {
    // Leerer deptrac-Lauf: keine Verstoesse, alles zugeordnet; die Baseline-Eintraege waeren veraltet.
    const out = args.includes('analyse') ? '{"files":{}}' : '';
    return { out, stdout: out, code: 0 };
  }
  const key = args.includes('analyse') ? 'phpstan' : args[args.indexOf('/opt/plugin-ci/vendor/bin/moodle-plugin-ci') + 1];
  const out = map[key] ?? '';
  return { out, stdout: out, code: codeFor(key) };
};

test('runStatic: every check appears in report lines and summary; findings do not fail', () => {
  const exec = fakeExec({
    phpcs: read('phpcs.txt'), phpdoc: read('phpdoc.txt'), savepoints: 'ok', mustache: read('mustache.txt'),
    grunt: read('grunt.txt'), phpstan: read('phpstan.json'),
  }, () => 1);
  const r = s.runStatic(s.ALL_CHECKS, { container: 'x', repo: path.join(__dirname, '..'), exec });
  const rules = new Set(r.lines.map(l => l.split(': ')[1]));
  for (const rule of ['moodle-cs-error', 'moodle-cs-warning', 'phpdoc', 'mustache', 'eslint', 'phpstan']) {
    assert.ok(rules.has(rule), rule);
  }
  assert.deepEqual(r.errors.map(e => e.split(':')[0]), ['deptrac']);
  assert.match(r.summary, /^summary: static moodle_cs_errors=10 moodle_cs_warnings=3 phpdoc=3 /);
  assert.match(r.summary, /mustache=2 eslint=3 phpstan=2 covers=0/);
});

test('runStatic: tool that fails without parsable findings is a tool error, never clean', () => {
  const exec = fakeExec({ phpcs: 'PHP Fatal error: boom', phpstan: 'not json' }, () => 255);
  const r = s.runStatic(['moodle-cs', 'phpstan', 'savepoints'], { container: 'x', repo: path.join(__dirname, '..'), exec });
  assert.deepEqual(r.errors.map(e => e.split(':')[0]), ['moodle-cs', 'phpstan']);
  assert.equal(r.results.savepoints.length, 1);
});

for (const code of [2, 255]) {
  for (const report of [{}, { totals: {}, files: {}, errors: [] }, { totals: { errors: 0, file_errors: 0 } }]) {
    test(`runStatic: PHPStan exit ${code} with incomplete report ${JSON.stringify(report)} is a tool error`, () => {
      const exec = fakeExec({ phpstan: JSON.stringify(report) }, () => code);
      const r = s.runStatic(['phpstan'], { container: 'x', repo: path.join(__dirname, '..'), exec });
      assert.equal(r.errors.length, 1);
      assert.match(r.errors[0], new RegExp(`Exitcode ${code}`));
    });
  }
}

test('runStatic: a real complete clean PHPStan report is green only with exit 0', () => {
  for (const code of [0, 2, 255]) {
    const exec = fakeExec({ phpstan: read('phpstan-clean.json') }, () => code);
    const r = s.runStatic(['phpstan'], { container: 'x', repo: path.join(__dirname, '..'), exec });
    assert.equal(r.blocking, 0);
    assert.equal(r.errors.length, code === 0 ? 0 : 1);
  }
});

test('runStatic: incomplete PHPStan reports retain findings and internal errors', () => {
  const report = JSON.parse(read('phpstan.json'));
  report.errors = ['Internal error: analysis crashed'];
  report.files['/var/www/html/public/local/coursepilot/classes/broken.php'] = { errors: 1 };
  const exec = fakeExec({ phpstan: JSON.stringify(report) }, () => 255);
  const r = s.runStatic(['phpstan'], { container: 'x', repo: path.join(__dirname, '..'), exec });
  assert.equal(r.blocking, 2);
  assert.match(r.lines[0], /connections.php:39: phpstan: Variable/);
  assert.match(r.errors.join(' '), /Internal error: analysis crashed/);
  assert.match(r.errors.join(' '), /Exitcode 255/);
});

test('runStatic: malformed or contradictory PHPStan reports are red even with exit 0', () => {
  const clean = JSON.parse(read('phpstan-clean.json'));
  const reports = [
    null, [], {}, { ...clean, totals: {} }, { totals: clean.totals },
    { ...clean, totals: { errors: 1, file_errors: 0 } },
    { ...clean, totals: { errors: 0, file_errors: 1 } },
    { ...clean, errors: [null] }, { ...clean, files: [] },
  ];
  for (const report of reports) {
    const exec = fakeExec({ phpstan: JSON.stringify(report) });
    const r = s.runStatic(['phpstan'], { container: 'x', repo: path.join(__dirname, '..'), exec });
    assert.equal(r.errors.length, 1, JSON.stringify(report));
  }
});

test('runStatic: malformed PHPStan messages do not hide later findings or internal errors', () => {
  const report = JSON.parse(read('phpstan.json'));
  Object.values(report.files)[0].messages.unshift(null, { message: 42 });
  report.errors = ['Internal error: incomplete analysis', null];
  const exec = fakeExec({ phpstan: JSON.stringify(report) }, () => 2);
  const r = s.runStatic(['phpstan'], { container: 'x', repo: path.join(__dirname, '..'), exec });
  assert.equal(r.blocking, 2);
  assert.match(r.lines[1], /connections.php:40: phpstan: Variable/);
  assert.match(r.errors.join(' '), /Internal error: incomplete analysis/);
  assert.match(r.errors.join(' '), /Exitcode 2/);
});

for (const field of ['line', 'identifier']) {
  test(`runStatic: malformed PHPStan ${field} preserves messages and internal errors`, () => {
    const report = JSON.parse(read('phpstan.json'));
    Object.values(report.files)[0].messages[0][field] = { toString: null };
    report.errors = ['Internal error: analysis crashed'];
    const exec = fakeExec({ phpstan: JSON.stringify(report) }, () => 255);
    const r = s.runStatic(['phpstan'], { container: 'x', repo: path.join(__dirname, '..'), exec });
    assert.equal(r.blocking, 2);
    assert.match(r.lines[0], /phpstan: Variable \$PAGE might not be defined\./);
    assert.match(r.lines[1], /connections.php:40: phpstan: Variable/);
    assert.equal(r.results.phpstan[0].line, field === 'line' ? 0 : 39);
    assert.match(r.errors.join(' '), /Internal error: analysis crashed/);
    assert.match(r.errors.join(' '), /Exitcode 255/);
  });
}

test('gate static: red with gate-error when the container is not running', () => {
  const r = spawnSync('node', [path.join(__dirname, '..', 'scripts', 'gate', 'gate.js'), 'static'], {
    encoding: 'utf8', env: { ...process.env, GATE_CONTAINER: 'does-not-exist-gate' },
  });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /gate-error/);
});

test('gate phpstan-baseline: red with gate-error when the container is not running', () => {
  const r = spawnSync('node', [path.join(__dirname, '..', 'scripts', 'gate', 'gate.js'), 'phpstan-baseline'], {
    encoding: 'utf8', env: { ...process.env, GATE_CONTAINER: 'does-not-exist-gate' },
  });
  assert.equal(r.status, 1);
  assert.match(r.stdout, /gate-error/);
});

test('phpstan baseline is versioned and configured for level 6', () => {
  const base = path.join(__dirname, '..', 'scripts', 'gate', 'phpstan');
  assert.match(fs.readFileSync(path.join(base, 'phpstan-baseline.neon'), 'utf8'), /^parameters:\n\tignoreErrors:/);
  assert.match(fs.readFileSync(path.join(base, 'phpstan.neon'), 'utf8'), /level: 6\b/);
});

test('moodle-cs runs with the repo-only TODO issue regex', () => {
  const exec = (container, args) => {
    exec.args = args;
    return { out: '', stdout: '', code: 0 };
  };
  s.runStatic(['moodle-cs'], { container: 'x', repo: path.join(__dirname, '..'), exec });
  const arg = exec.args.find(a => a.startsWith('--todo-comment-regex='));
  assert.equal(arg, '--todo-comment-regex=https://github\\.com/matthiasgruenwald/moodle-coursepilot/issues/[0-9]+');
});
