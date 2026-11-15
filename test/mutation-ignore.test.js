const { test } = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');

const root = path.join(__dirname, '..');
const entries = JSON.parse(fs.readFileSync(path.join(root, 'scripts/gate/mutation-ignore.json'), 'utf8'));
const grandfather = new Set(JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/mutation-ignore-grandfather.json'), 'utf8')));

const fingerprint = (e) => crypto
  .createHash('sha256')
  .update([e.file, e.line, e.mutator, e.hash, e.reason].join('|'))
  .digest('hex');

// Neue Eintraege muessen ihre Stelle belegen: Datei:Zeile oder Zeilenbezug
// oder eine ausdruecklich benannte Aequivalenz-Begruendung.
const referenceRe = /\.php(:\d+)?|line \d+|Zeile \d+|Not an equivalence|Probabilistic equivalence/;

test('mutation-ignore: neue Eintraege nennen Datei/Zeile oder Aequivalenz-Begruendung', () => {
  const offenders = entries
    .filter((e) => !grandfather.has(fingerprint(e)))
    .filter((e) => !referenceRe.test(e.reason))
    .map((e) => `${e.file}:${e.line} ${e.mutator}`);
  assert.deepEqual(offenders, []);
});
