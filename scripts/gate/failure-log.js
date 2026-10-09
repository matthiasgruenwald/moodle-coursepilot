'use strict';

/**
 * Gate-Fehlschlagslog (ADR 0029, Ereignis-Trigger). Eine Zeile pro Ergebnis,
 * Tab-getrennt: Datum, Pruefung, Datei, Ergebnis (ok | fail | abort).
 * Die Rangliste zeigt Trend, keine Schwelle.
 */

const fs = require('node:fs');
const path = require('node:path');

const DEFAULT_LOG = path.resolve(__dirname, '..', '..', '.gate-failures.log');
const logPath = () => process.env.GATE_FAILURE_LOG || DEFAULT_LOG;
const clean = s => String(s).replace(/[\t\r\n]+/g, ' ');

/** Zeilen fuer eine Pruefung: ein `fail` je Befund, sonst ein `ok`. */
function entriesFor(check, found) {
  const date = new Date().toISOString();
  if (found.length === 0) {
    return [[date, check, '-', 'ok']];
  }
  return found.map(f => [date, f.rule || check, f.file || '-', 'fail']);
}

/** Haengt Zeilen an; ein Logfehler darf das Gate nie kippen. */
function append(entries, file = logPath()) {
  if (entries.length === 0) {
    return;
  }
  try {
    fs.appendFileSync(file, entries.map(e => e.map(clean).join('\t')).join('\n') + '\n');
  } catch (e) {
    console.error(`gate: Fehlschlagslog nicht schreibbar: ${e.message}`);
  }
}

const logCheck = (check, found) => append(entriesFor(check, found));
const logAbort = check => append([[new Date().toISOString(), check, '-', 'abort']]);

function parse(text) {
  return text.split('\n').map(l => l.split('\t')).filter(c => c.length === 4)
    .map(([date, check, file, result]) => ({ date, check, file, result }));
}

const tally = (rows, key) => {
  const m = new Map();
  rows.forEach(r => m.set(r[key], (m.get(r[key]) || 0) + 1));
  return [...m].sort((a, b) => b[1] - a[1] || a[0].localeCompare(b[0]));
};

/** Rangliste der Fehlschlaege (fail und abort) nach Datei und nach Pruefung. */
function ranking(rows, top = 20) {
  const bad = rows.filter(r => r.result !== 'ok');
  const files = tally(bad.filter(r => r.file !== '-'), 'file').slice(0, top);
  const checks = tally(bad, 'check').slice(0, top);
  const fmt = (title, list) => [title, ...(list.length ? list.map(([k, n], i) => `${i + 1}. ${n}x ${k}`) : ['(keine)'])];
  return [...fmt('Dateien:', files), '', ...fmt('Pruefungen:', checks), '', `summary: ranking rows=${rows.length} failures=${bad.length}`].join('\n');
}

function report(top, file = logPath()) {
  // Frischer Checkout ohne Log: leere Rangliste, kein Fehler.
  return ranking(fs.existsSync(file) ? parse(fs.readFileSync(file, 'utf8')) : [], top);
}

module.exports = { entriesFor, append, logCheck, logAbort, parse, ranking, report, logPath };
