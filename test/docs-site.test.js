'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

const {
  SITE_ROOT,
  findBrokenReferences,
  findMissingCounterparts,
} = require('../scripts/docs-site-check');

function fixtureSite(files) {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'docs-site-'));
  for (const [name, content] of Object.entries(files)) {
    const full = path.join(root, name);
    fs.mkdirSync(path.dirname(full), { recursive: true });
    fs.writeFileSync(full, content);
  }
  return root;
}

test('published documentation site has no broken internal references', () => {
  assert.deepEqual(findBrokenReferences(SITE_ROOT), []);
});

test('every German page has its English counterpart and vice versa', () => {
  assert.deepEqual(findMissingCounterparts(SITE_ROOT), []);
});

test('reports a link to a missing page and a missing image', () => {
  const root = fixtureSite({
    'de/index.html': '<a href="missing.html">x</a><img src="../assets/none.png">',
  });
  assert.deepEqual(findBrokenReferences(root), [
    { file: path.join('de', 'index.html'), reference: 'missing.html' },
    { file: path.join('de', 'index.html'), reference: '../assets/none.png' },
  ]);
});

test('accepts existing targets, fragments, directories and external links', () => {
  const root = fixtureSite({
    'de/index.html': [
      '<a href="teachers.html#setup">a</a>',
      '<a href="../en/">b</a>',
      '<a href="#top">c</a>',
      '<a href="https://example.org/x.html">d</a>',
      '<a href="mailto:a@example.org">e</a>',
      '<link rel="stylesheet" href="../assets/style.css">',
    ].join(''),
    'de/teachers.html': '',
    'en/index.html': '',
    'assets/style.css': '',
  });
  assert.deepEqual(findBrokenReferences(root), []);
});

test('rejects references that leave the site folder', () => {
  const root = fixtureSite({
    'site/de/index.html': '<a href="../../outside.html">x</a>',
    'outside.html': '',
  });
  assert.equal(findBrokenReferences(path.join(root, 'site')).length, 1);
});

test('reports a page that exists in only one language', () => {
  const root = fixtureSite({
    'de/index.html': '',
    'de/teachers.html': '',
    'en/index.html': '',
  });
  assert.deepEqual(findMissingCounterparts(root), [path.join('en', 'teachers.html')]);
});

const TOOL_HEADINGS = { de: 'Werkzeuge nach Gruppen', en: 'Tools by group' };

for (const [lang, heading] of Object.entries(TOOL_HEADINGS)) {
  test(`${lang} developer page lists exactly the registered tools and their count`, () => {
    const registry = fs.readFileSync(
      path.join(__dirname, '..', 'Plugin', 'src', 'local_coursepilot', 'classes', 'tool_registry.php'), 'utf8');
    const registered = [...registry.matchAll(/'(coursepilot_\w+)' => \[\s*'classname'/g)].map((m) => m[1]).sort();
    const page = fs.readFileSync(path.join(SITE_ROOT, lang, 'developers.html'), 'utf8');
    const table = page.match(/<table class="tools">[\s\S]*?<\/table>/);
    assert.ok(table, `${lang}/developers.html has no tools table`);
    const listed = [...table[0].matchAll(/<code>(coursepilot_\w+)<\/code>/g)].map((m) => m[1]).sort();
    assert.deepEqual(listed, registered);
    assert.match(page, new RegExp(`${heading} \\(${registered.length}\\)`));
  });
}
