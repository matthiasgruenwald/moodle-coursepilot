#!/usr/bin/env node
/**
 * Open survivors of a full Infection run: escaped mutants without an entry in mutation-ignore.json.
 *
 *   node scripts/gate/mutation-classify.js <infection.json> [open.json]
 *
 * <infection.json> is Infection's JSON log of a run, e.g. `<GATE_DIR>/reports/infection.json`.
 * Prints the count per production file; with a second argument writes the open survivors there as JSON.
 * Reads the ignore list of this checkout. A work list for the reviewer, not a gate: always exits 0 on success.
 */
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const mutation = require('./mutation');

const [reportPath, outPath] = process.argv.slice(2);
if (!reportPath) {
  console.error('usage: node scripts/gate/mutation-classify.js <infection.json> [open.json]');
  process.exit(2);
}

const report = JSON.parse(fs.readFileSync(reportPath, 'utf8'));
const ignores = JSON.parse(fs.readFileSync(path.join(__dirname, 'mutation-ignore.json'), 'utf8'));
const ignored = new Set(ignores.map(e => JSON.stringify([e.file, e.line, e.mutator, e.hash])));

const open = [];
for (const escaped of report.escaped) {
  const id = mutation.identity(escaped.mutator);
  if (!ignored.has(JSON.stringify([id.file, id.line, id.mutator, id.hash]))) open.push(id);
}

if (outPath) fs.writeFileSync(outPath, JSON.stringify(open, null, 1));

const byFile = new Map();
for (const o of open) {
  const name = o.file.replace('Plugin/src/local_coursepilot/', '');
  byFile.set(name, [...(byFile.get(name) || []), `${o.line}:${o.mutator}`]);
}
console.log(`escaped ${report.escaped.length}, open ${open.length}`);
for (const [file, list] of [...byFile].sort(([a], [b]) => (a < b ? -1 : 1))) console.log(`${file} ${list.length} ${list.join(' ')}`);
