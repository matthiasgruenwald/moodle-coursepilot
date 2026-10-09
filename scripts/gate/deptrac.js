/**
 * Gate-Pruefung "Schichtregeln" (ADR 0030) ueber deptrac.
 *
 * Konfiguration und Klassenliste: scripts/gate/deptrac/deptrac.yaml, bekannte Verstoesse:
 * scripts/gate/deptrac/deptrac-baseline.yaml (darf nur schrumpfen, jeder Eintrag mit Ticket).
 * Befunde (Regel im Bericht):
 *   deptrac-violation       Verstoss ausserhalb der Baseline (blockiert)
 *   deptrac-unassigned      Klasse ohne Schichtzuordnung (blockiert)
 *   deptrac-baseline-stale  Baseline-Eintrag ohne Verstoss im Code (blockiert, Eintrag entfernen)
 *   deptrac-baseline-ticket Baseline-Eintrag ohne Ticketnummer (blockiert)
 *   deptrac-baselined       bekannter Verstoss aus der Baseline (nur Bericht)
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');

const DIR_REL = 'scripts/gate/deptrac';
const BASELINE_REL = `${DIR_REL}/deptrac-baseline.yaml`;
const CONFIG_IN_CONTAINER = '/var/www/deptrac-config/deptrac.yaml';
const BLOCKING = new Set(['deptrac-violation', 'deptrac-unassigned', 'deptrac-baseline-stale', 'deptrac-baseline-ticket']);

const finding = (file, line, rule, text) => ({ file, line, rule, text });

/**
 * deptrac-JSON: Fehler ("must not depend") sind Verstoesse, Warnungen
 * ("should not depend") sind durch die Baseline uebersprungene Verstoesse.
 */
function parseDeptracJson(json) {
  const data = JSON.parse(json);
  const violations = [];
  const skipped = [];
  for (const [file, entry] of Object.entries(data.files || {})) {
    for (const m of entry.messages) {
      const hit = m.message.match(/^(\S+) (must|should) not depend on (\S+) \((.+)\)$/);
      if (!hit) {
        throw new Error(`deptrac-Meldung nicht lesbar: ${m.message}`);
      }
      (hit[2] === 'must' ? violations : skipped).push({ file, line: m.line || 0, from: hit[1], to: hit[3], layers: hit[4] });
    }
  }
  return { violations, skipped };
}

/** `debug:unassigned`: eine Klasse je Zeile. */
function parseUnassigned(out) {
  return out.split('\n').map(l => l.trim()).filter(l => /^[\w\\]+$/.test(l));
}

/**
 * Baseline-Datei: Schluessel `    Klasse:` mit Listeneintraegen `      - Ziel`. Das Ticket
 * (`#nr`) steht in dem Kommentar, der den Eintrag einleitet und fuer die folgenden Schluessel gilt.
 */
function parseBaseline(text) {
  const entries = [];
  let from = null;
  let ticket = null;
  text.split('\n').forEach((row, i) => {
    const comment = row.match(/^\s*#(.*)$/);
    const key = row.match(/^ {4}(\S+):\s*$/);
    const item = row.match(/^ {6}- (\S+)\s*$/);
    if (/^\s*skip_violations:/.test(row)) {
      ticket = null;
    } else if (comment) {
      ticket = (comment[1].match(/#\d+/) || [null])[0];
    } else if (key) {
      from = key[1];
    } else if (item && from) {
      entries.push({ from, to: item[1], ticket, line: i + 1 });
    }
  });
  return entries;
}

const pair = (from, to) => `${from} -> ${to}`;

/**
 * Wertet deptrac-Ergebnis, Unzugeordnete und Baseline gegeneinander aus.
 * @param {{violations: object[], skipped: object[]}} report
 * @param {string[]} unassigned
 * @param {{from: string, to: string, ticket: string|null, line: number}[]} baseline
 * @param {(file: string) => string} toRepo Pfad im Container -> Repo-Pfad
 */
function evaluate(report, unassigned, baseline, toRepo = f => f) {
  const found = [];
  for (const v of report.violations) {
    found.push(finding(toRepo(v.file), v.line, 'deptrac-violation', `${pair(v.from, v.to)} (${v.layers}) nicht in der Baseline`));
  }
  const seen = new Set();
  for (const v of report.skipped) {
    seen.add(pair(v.from, v.to));
    found.push(finding(toRepo(v.file), v.line, 'deptrac-baselined', `${pair(v.from, v.to)} (${v.layers})`));
  }
  for (const cls of unassigned) {
    found.push(finding(`${DIR_REL}/deptrac.yaml`, 0, 'deptrac-unassigned', `Klasse ${cls} ohne Schichtzuordnung, in die Klassenliste eintragen`));
  }
  for (const e of baseline) {
    if (!seen.has(pair(e.from, e.to))) {
      found.push(finding(BASELINE_REL, e.line, 'deptrac-baseline-stale', `${pair(e.from, e.to)} wird nicht mehr verletzt, Eintrag entfernen`));
    }
    if (!e.ticket) {
      found.push(finding(BASELINE_REL, e.line, 'deptrac-baseline-ticket', `${pair(e.from, e.to)} ohne Ticketnummer`));
    }
  }
  return found;
}

const isBlocking = f => BLOCKING.has(f.rule);

/**
 * Fuehrt deptrac im Container aus -> {found, toolError|null}. `exec(container, args)`
 * liefert {stdout, out, code}; ein Lauf ohne auswertbares JSON ist ein Werkzeugfehler.
 */
function runDeptrac({ container, repo, exec, toRepo }) {
  const bin = ['php', '-d', 'memory_limit=-1', '/opt/dev-tools/vendor/bin/deptrac'];
  const common = ['-c', CONFIG_IN_CONTAINER, '--no-cache'];
  const analyse = exec(container, [...bin, 'analyse', ...common, '--no-progress', '--report-skipped', '--formatter=json']);
  const unassigned = exec(container, [...bin, 'debug:unassigned', ...common]);
  let report;
  try {
    report = parseDeptracJson(analyse.stdout);
  } catch (e) {
    return { found: [], toolError: `deptrac-Ausgabe ungueltig (Exitcode ${analyse.code}): ${analyse.out.slice(0, 300)}` };
  }
  if (unassigned.code !== 0 && unassigned.code !== 2) {
    return { found: [], toolError: `deptrac debug:unassigned Exitcode ${unassigned.code}: ${unassigned.out.slice(0, 300)}` };
  }
  const baseline = parseBaseline(fs.readFileSync(path.join(repo, BASELINE_REL), 'utf8'));
  return { found: evaluate(report, parseUnassigned(unassigned.stdout), baseline, toRepo), toolError: null };
}

module.exports = { DIR_REL, BLOCKING, parseDeptracJson, parseUnassigned, parseBaseline, evaluate, isBlocking, runDeptrac };
