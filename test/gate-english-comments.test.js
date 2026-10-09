'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const { findGermanComments, checkEnglishComments } = require('../scripts/gate/english');
const { FAST_CHECKS } = require('../scripts/gate/static');

const P = 'Plugin/src/local_coursepilot';
const file = (name, content) => ({ file: `${P}/${name}`, content });
const reason = 'Testfixture';

test('german comment is red with file, line and match', () => {
  const found = findGermanComments([file('classes/a.php', '<?php\n// Prüfe, ob der Kurs leer ist\n')]);
  assert.deepEqual(found.map(f => `${f.file}:${f.line}:${f.rule}`), found.map(() => `${P}/classes/a.php:2:english-comment`));
  assert.ok(found.length > 0);
  assert.match(found[0].text, /ü/);
});

test('german function word in a docblock is red, on the right line', () => {
  const found = findGermanComments([file('classes/a.php', '<?php\n/**\n * Returns the id\n * oder null.\n */\n')]);
  assert.deepEqual(found.map(f => `${f.line}:${f.text}`), ['4:deutscher Kommentartext: "oder"']);
});

test('english comments, docblocks and german string literals are green', () => {
  const src = "<?php\n// Returns the course id.\n/** Whether it is empty. */\n$a = 'nicht gefunden für ü'; // fine\n$b = \"wenn \\\" und\"; #[Attr]\n$c = <<<EOT\nund oder\nEOT;\n";
  assert.deepEqual(findGermanComments([file('classes/a.php', src)]), []);
});

test('lang/de and tests/fixtures are ignored', () => {
  const src = '<?php\n// Prüfe und oder\n';
  assert.deepEqual(findGermanComments([file('lang/de/local_coursepilot.php', src), file('tests/fixtures/x.php', src)]), []);
});

test('ignore entry with reason silences the hit; without reason it is red', () => {
  const src = '<?php\n// Prüfe\n';
  const f = file('classes/a.php', src);
  assert.deepEqual(findGermanComments([f], [{ file: f.file, match: 'ü', reason }]), []);
  const bad = findGermanComments([f], [{ file: f.file, match: 'ü', reason: ' ' }]);
  assert.deepEqual(bad.map(x => x.rule).sort(), ['english-comment', 'english-ignore-invalid']);
});

test('heredoc ends only at its terminator line; sentence-initial german word is a hit', () => {
  const src = "<?php\n$a = <<<EOT\nEOTX und\nEOT;\n// Und dann\n";
  const found = findGermanComments([file('classes/a.php', src)]);
  assert.deepEqual(found.map(f => `${f.line}:${f.text}`), ['5:deutscher Kommentartext: "Und"']);
});

test('english check runs in fast mode', () => {
  assert.ok(FAST_CHECKS.includes('english'));
});

test('contract: shipped plugin has no unexplained german comments', () => {
  const found = checkEnglishComments(path.join(__dirname, '..'));
  assert.deepEqual(found.map(f => `${f.file}:${f.line}: ${f.text}`), []);
});
