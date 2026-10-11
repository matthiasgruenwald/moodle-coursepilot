'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { runStatic } = require('../scripts/gate/static');

const ROOT = path.join(__dirname, '..');
const BASELINE = 'scripts/gate/phpstan/phpstan-baseline.neon';
const POINTER = 'Plugin/src/local_coursepilot/classes/admin/pointer_scan.php';
const ENTRY = String.raw`parameters:
	ignoreErrors:
		-
			message: '#^Method local_coursepilot\\admin\\pointer_scan\:\:has_external_target\(\) has parameter \$decoded with no value type specified in iterable type array\.$#'
			identifier: missingType.iterableValue
			count: 1
			path: ../html/public/local/coursepilot/classes/admin/pointer_scan.php
`;

const check = repo => runStatic(['phpstan-reasons'], { container: 'unused', repo });

function fixture(t, entry = ENTRY) {
  const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-phpstan-reasons-'));
  t.after(() => fs.rmSync(repo, { recursive: true, force: true }));
  fs.mkdirSync(path.dirname(path.join(repo, BASELINE)), { recursive: true });
  fs.mkdirSync(path.dirname(path.join(repo, POINTER)), { recursive: true });
  fs.copyFileSync(path.join(ROOT, POINTER), path.join(repo, POINTER));
  fs.writeFileSync(path.join(repo, BASELINE), entry);
  return repo;
}

test('runStatic: an unexplained PHPStan suppression blocks even with clean analysis', t => {
  const clean = JSON.stringify({ totals: { errors: 0, file_errors: 0 }, files: {}, errors: [] });
  const report = runStatic(['phpstan', 'phpstan-reasons'], { container: 'unused', repo: fixture(t),
    exec: () => ({ out: clean, stdout: clean, code: 0 }) });
  assert.deepEqual(report.results.phpstan, []);
  assert.equal(report.blocking, 1);
  assert.match(report.lines[0], /phpstan-baseline-reason:.*reason/i);
});

const REASON = 'has_external_target($decoded) reads the raw context pointer from raw_pointer_for(); its array|null docblock omits the pointer value types. This is typing debt, not a PHP limitation.';
const annotate = (evidence, resolution = 'https://github.com/matthiasgruenwald/moodle-coursepilot/issues/714') => ENTRY.replace('\t\t-\n', `\t\t-\n\t\t\t# reason: ${REASON}\n\t\t\t# evidence: ${JSON.stringify(evidence)}\n\t\t\t# resolution: ${resolution}\n`);
const EVIDENCE = { 'classes/admin/pointer_scan.php': ['public static function has_external_target(?array $decoded): bool {', '* @param array|null $decoded'] };

test('runStatic: a reason mapped to the wrong source blocks the suppression', t => {
  const report = check(fixture(t, annotate({ 'classes/admin/pointer_scan.php': ['public function unrelated(): void {'] })));
  assert.equal(report.blocking, 1);
  assert.match(report.lines[0], /evidence/i);
});

test('runStatic: a concrete pointer reason with matching source evidence is accepted', t => {
  const report = check(fixture(t, annotate(EVIDENCE)));
  assert.equal(report.blocking, 0, report.lines.join('\n'));
  assert.deepEqual(report.errors, []);
});

test('runStatic: temporary typing debt needs an explicit resolution ticket', t => {
  for (const resolution of ['', 'permanent', '#714', 'https://example.org/issues/714']) {
    const report = check(fixture(t, annotate(EVIDENCE, resolution)));
    assert.equal(report.blocking, 1, resolution);
    assert.match(report.lines[0], /resolution/i);
  }
});

test('runStatic: every real PHPStan suppression has a concrete reason and matching evidence', () => {
  const report = check(ROOT);
  assert.equal(report.blocking, 0, report.lines.join('\n'));
  assert.deepEqual(report.errors, []);
  const baseline = fs.readFileSync(path.join(ROOT, BASELINE), 'utf8');
  const pointer = baseline.split('\t\t-\n').find(b => b.includes('has_external_target'));
  assert.match(pointer, /raw_pointer_for/);
  assert.match(pointer, /typing debt/i);
  assert.match(pointer, /# resolution: https:\/\/github.com\/matthiasgruenwald\/moodle-coursepilot\/issues\/714/);
});

test('runStatic: unsupported baseline layout cannot silently skip the reason check', t => {
  const report = check(fixture(t, ENTRY.replaceAll('\t', '    ')));
  assert.equal(report.blocking, 1);
  assert.match(report.lines[0], /layout/i);
});

test('runStatic: moving rationale to another suppression path is rejected', t => {
  const repo = fixture(t, annotate(EVIDENCE).replace('path: ../html/public/local/coursepilot/classes/admin/pointer_scan.php', 'path: ../html/public/local/coursepilot/classes/other.php'));
  fs.writeFileSync(path.join(repo, 'Plugin/src/local_coursepilot/classes/other.php'), '<?php\nclass other {}\n');
  const report = check(repo);
  assert.equal(report.blocking, 1);
  assert.match(report.lines[0], /evidence/i);
});

test('runStatic: a real sibling pointer method cannot justify this suppression', t => {
  const wrong = { 'classes/admin/pointer_scan.php': ['public static function has_open_previous_location(?array $decoded): bool {', '* @param array|null $decoded'] };
  const report = check(fixture(t, annotate(wrong)));
  assert.equal(report.blocking, 1);
  assert.match(report.lines[0], /has_external_target/);
});

test('runStatic: an additional inline NEON suppression cannot hide inside an annotated entry', t => {
  const inline = "\t\t- {message: '#^Unexplained$#', identifier: missingType.iterableValue, count: 1, path: ../html/public/local/coursepilot/classes/admin/pointer_scan.php}\n";
  const report = check(fixture(t, annotate(EVIDENCE) + inline));
  assert.equal(report.blocking, 1);
  assert.match(report.lines[0], /layout/i);
});

test('baseline evidence identifies the suppressed boundary, not an unrelated valid call', () => {
  const blocks = fs.readFileSync(path.join(ROOT, BASELINE), 'utf8').split('\t\t-\n').slice(1);
  for (const block of blocks) {
    const evidence = JSON.parse(block.match(/# evidence: (.*)/)[1]);
    const snippets = Object.values(evidence).flat().join('\n');
    if (block.includes('identifier: arguments.count')) assert.match(snippets, /\$actionlink/);
    if (block.includes('classes/history/version_history.php') && block.includes('identifier: argument.type')) {
      assert.doesNotMatch(snippets, /historygapnotice/);
      assert.match(snippets, /\$userid|count\(\$fields\) - 4|\$added|\$removed/);
    }
    if (block.includes('classes/storage_anchor.php') && block.includes('identifier: argument.type')) {
      assert.match(snippets, /return get_file_storage\(\)->get_directory_files\(/);
      assert.match(block, /directory_files\(\)/);
    }
    if (block.includes('# reason: create_question')) {
      assert.doesNotMatch(block.split('\n')[0], /mod_quiz|mod_page/);
      assert.match(snippets, /get_plugin_generator\('core_question'\)/);
    }
  }
});
