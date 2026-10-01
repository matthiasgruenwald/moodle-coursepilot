#!/usr/bin/env node
/**
 * 80%-Line-Coverage-Gate (Issue #268, Akzeptanzkriterien 6/7).
 *
 * Liest einen PHPUnit-Clover-Coverage-Bericht (`--coverage-clover`) und
 * vergleicht das Verhaeltnis gedeckter zu ausfuehrbarer Zeilen (Clover:
 * "statements"/"coveredstatements" auf Projektebene) gegen die
 * Mindestschwelle von 80 Prozent (>=, exakt 80% besteht).
 *
 * Rot bei jedem der folgenden Faelle (kein "continue-on-error", kein
 * gruener Notausgang):
 *   - Datei fehlt, ist leer oder nicht als XML lesbar.
 *   - Kein <metrics>-Element auf Projektebene (ungueltiger Bericht).
 *   - "statements" ist 0 oder fehlt (ungueltiger Nenner - eine leere Zaehlung
 *     ist kein Erfolg).
 *   - Quote unterhalb der Schwelle.
 *
 * Aufruf: node scripts/ci/check-coverage.js <clover.xml> [--threshold=80]
 */

'use strict';

const fs = require('node:fs');

const DEFAULT_THRESHOLD = 80;

/**
 * @param {string} xml
 * @returns {{statements: number, coveredstatements: number} | null}
 */
function extractProjectMetrics(xml) {
  // Clover legt genau ein <project>-Element an; dessen Summen-<metrics> ist
  // das LETZTE Kind, hinter allen <file>-/<package>-Metriken (phpunit-Clover,
  // CI-Lauf 36475282645). Deshalb das <metrics>-Tag direkt vor </project>,
  // nicht das erste im Dokument. Bewusst kein XML-Parser als Abhaengigkeit
  // (CLAUDE.md: keine npm-Laufzeit-Dependencies).
  const project = xml.match(/<metrics\s+([^>]*)\/>\s*<\/project>/);
  const attrs = project ? project[1] : '';
  const match = attrs.match(/\bstatements="(\d+)"[\s\S]*\bcoveredstatements="(\d+)"/);
  if (!match) {
    return null;
  }
  return { statements: Number(match[1]), coveredstatements: Number(match[2]) };
}

/**
 * @param {string} cloverPath
 * @param {number} threshold
 * @returns {{ok: boolean, message: string, percent?: number}}
 */
function checkCoverage(cloverPath, threshold) {
  if (!cloverPath) {
    return { ok: false, message: 'kein Pfad zum Coverage-Bericht angegeben.' };
  }
  if (!fs.existsSync(cloverPath)) {
    return { ok: false, message: `Coverage-Bericht fehlt: ${cloverPath}` };
  }
  const raw = fs.readFileSync(cloverPath, 'utf8');
  if (raw.trim() === '') {
    return { ok: false, message: `Coverage-Bericht ist leer: ${cloverPath}` };
  }
  const metrics = extractProjectMetrics(raw);
  if (!metrics) {
    return { ok: false, message: `Coverage-Bericht ist ungueltig (kein Projekt-<metrics>-Element gefunden): ${cloverPath}` };
  }
  if (!metrics.statements || metrics.statements <= 0) {
    return { ok: false, message: `Ungueltiger Nenner: 0 ausfuehrbare Zeilen im Coverage-Bericht ${cloverPath}.` };
  }
  const percent = (metrics.coveredstatements / metrics.statements) * 100;
  if (percent < threshold) {
    return {
      ok: false,
      percent,
      message: `Line Coverage ${percent.toFixed(2)}% liegt unter der Schwelle von ${threshold}% ` +
        `(${metrics.coveredstatements}/${metrics.statements} Zeilen).`,
    };
  }
  return {
    ok: true,
    percent,
    message: `Line Coverage ${percent.toFixed(2)}% erreicht die Schwelle von ${threshold}% ` +
      `(${metrics.coveredstatements}/${metrics.statements} Zeilen).`,
  };
}

function main() {
  const args = process.argv.slice(2);
  const cloverPath = args.find(a => !a.startsWith('--'));
  const thresholdArg = args.find(a => a.startsWith('--threshold='));
  const threshold = thresholdArg ? Number(thresholdArg.split('=')[1]) : DEFAULT_THRESHOLD;

  const result = checkCoverage(cloverPath, threshold);
  process.stdout.write(`${result.message}\n`);
  process.exit(result.ok ? 0 : 1);
}

if (require.main === module) {
  main();
}

module.exports = { checkCoverage, extractProjectMetrics };
