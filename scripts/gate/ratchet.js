/**
 * Ratsche des Gates (ADR 0029, ADR 0030 Nachtrag). Reine Auswertung; gate.js liefert
 * Clover-Bericht und Git-Zugriff. Befunde (alle blockierend), Format `datei:zeile: regel: text`:
 *
 *   ratchet-coverage          Gesamt-Coverage unter der Baseline
 *   ratchet-baseline-lowered  Baseline-Datei niedriger als im Ziel-Branch
 *   ratchet-file-coverage     geaenderte Datei unter 90 % Line-Coverage
 *   ratchet-method-crap       geaenderte Methode mit CRAP ueber 8
 *   ratchet-phpstan-grown     PHPStan-Baseline waechst (je Datei und Befundart)
 *   ratchet-deptrac-grown     deptrac-Baseline waechst (neues Paar Quelle -> Ziel)
 */

'use strict';

const FILE_MIN = 90;
const CRAP_MAX = 8;

const finding = (file, line, rule, text) => ({ file, line, rule, text });
const formatFinding = f => `${f.file}:${f.line}: ${f.rule}: ${f.text}`;

/** `true`, wenn der Repo-Pfad (relativ zum Plugin) unter eine Ausnahme der Baseline faellt. */
function isExcluded(inner, exclusions) {
  return exclusions.some(e => (e.path.endsWith('/') ? inner.startsWith(e.path) : inner === e.path));
}

/**
 * Fasst den Messwert zusammen: Dateien der Ausschlussliste zaehlen nicht in den Nenner.
 * @param {{name: string, statements: number, covered: number}[]} files Clover-Dateien (repo-relativ benannt)
 * @param {string} pluginPrefix `Plugin/src/local_coursepilot/`
 */
function totals(files, exclusions, pluginPrefix) {
  let statements = 0;
  let covered = 0;
  for (const f of files) {
    const inner = f.name.startsWith(pluginPrefix) ? f.name.slice(pluginPrefix.length) : f.name;
    if (!isExcluded(inner, exclusions)) {
      statements += f.statements;
      covered += f.covered;
    }
  }
  return { statements, covered };
}

/** a < b, exakt ueber Kreuzprodukt (kein Gleitkomma-Rauschen). */
const ratioBelow = (a, b) => a.covered * b.statements < b.covered * a.statements;

const pct = t => `${((t.covered / t.statements) * 100).toFixed(2)}% (${t.covered}/${t.statements})`;

/** Gesamt-Coverage darf nie sinken. */
function coverageFindings(measured, baseline) {
  return ratioBelow(measured, baseline)
    ? [finding('scripts/gate/baseline.json', 0, 'ratchet-coverage', `Gesamt-Coverage ${pct(measured)} unter Baseline ${pct(baseline)}`)]
    : [];
}

/** Die Baseline-Datei selbst wird nur besser fortgeschrieben, nie gegenueber dem Ziel-Branch gesenkt. */
function loweredFindings(baseline, base) {
  return base && ratioBelow(baseline, base)
    ? [finding('scripts/gate/baseline.json', 0, 'ratchet-baseline-lowered', `Baseline ${pct(baseline)} niedriger als im Ziel-Branch ${pct(base)}`)]
    : [];
}

/**
 * PHPStan-Baseline (neon) -> Zaehler je `pfad | identifier`. Die Struktur ist
 * tab-eingerueckt: je Eintrag `identifier:`, `count:` und `path:`.
 */
function parsePhpstanBaseline(text) {
  const counts = new Map();
  let entry = {};
  const flush = () => {
    if (entry.path && entry.identifier) {
      const key = `${entry.path} | ${entry.identifier}`;
      counts.set(key, (counts.get(key) || 0) + (entry.count || 1));
    }
    entry = {};
  };
  for (const row of text.split('\n')) {
    const m = row.match(/^\s+(identifier|count|path):\s*(.+?)\s*$/);
    if (m) {
      entry[m[1]] = m[1] === 'count' ? Number(m[2]) : m[2];
    } else if (/^\s+-\s*$/.test(row)) {
      flush();
    }
  }
  flush();
  return counts;
}

/** Eintraege, die gegenueber `base` neu oder groesser sind. */
function grown(current, base) {
  return [...current].filter(([key, n]) => n > (base.get(key) || 0)).map(([key, n]) => ({ key, now: n, before: base.get(key) || 0 }));
}

function phpstanFindings(currentText, baseText, rel) {
  if (baseText === null) {
    return [];
  }
  return grown(parsePhpstanBaseline(currentText), parsePhpstanBaseline(baseText))
    .map(g => finding(rel, 0, 'ratchet-phpstan-grown', `${g.key}: ${g.now} statt hoechstens ${g.before}, Baseline darf nur schrumpfen`));
}

function deptracFindings(currentPairs, basePairs, rel) {
  if (basePairs === null) {
    return [];
  }
  const known = new Set(basePairs);
  return currentPairs.filter(p => !known.has(p)).map(p => finding(rel, 0, 'ratchet-deptrac-grown', `${p}: neuer Eintrag, Baseline darf nur schrumpfen`));
}

/**
 * Geaenderte Zeilen aus `git diff -U0`: Map Datei -> [[von, bis], ...] (neue Zeilennummern).
 * Reine Loeschungen zaehlen als die Zeile davor.
 */
function parseChangedLines(diff) {
  const out = new Map();
  let file = null;
  for (const row of diff.split('\n')) {
    const f = row.match(/^\+\+\+ b\/(.+)$/);
    const h = row.match(/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/);
    if (f) {
      file = f[1];
      out.set(file, []);
    } else if (/^\+\+\+ /.test(row)) {
      file = null;
    } else if (h && file) {
      const start = Number(h[1]);
      const count = h[2] === undefined ? 1 : Number(h[2]);
      out.get(file).push([start, start + Math.max(count, 1) - 1]);
    }
  }
  return out;
}

const COMMENT_OR_BLANK = /^\s*(\*|\/\*|\/\/|#\[|$)/;

/**
 * Zeilenbereiche der Methoden einer Datei. Clover liefert nur die Startzeile; das Ende
 * ist die letzte Code-Zeile vor der naechsten Methode (Docblock, Attribute und Leerzeilen
 * der naechsten Methode gehoeren nicht dazu).
 * @param {number[]} starts aufsteigende Startzeilen
 * @param {string[]} source Quellzeilen
 */
function methodRanges(starts, source) {
  return starts.map((start, i) => {
    let end = i + 1 < starts.length ? starts[i + 1] - 1 : source.length;
    while (end > start && COMMENT_OR_BLANK.test(source[end - 1] || '')) {
      end -= 1;
    }
    return { start, end };
  });
}

const touches = (ranges, start, end) => ranges === 'all' || ranges.some(([a, b]) => a <= end && b >= start);

/**
 * Prueft geaenderte Dateien und Methoden.
 * @param {{name: string, statements: number, covered: number}[]} files repo-relativ benannt
 * @param {{file: string, line: number, name: string, crap: number}[]} methods repo-relativ benannt
 * @param {Map<string, [number, number][]|'all'>} changed geaenderte Zeilen je Datei
 * @param {(file: string) => string[]} readSource Quellzeilen einer Datei
 */
function changedFindings({ files, methods, changed, readSource, exclusions, pluginPrefix }) {
  const found = [];
  for (const f of files) {
    const ranges = changed.get(f.name);
    const inner = f.name.slice(pluginPrefix.length);
    if (!ranges || !f.name.startsWith(pluginPrefix) || inner.startsWith('tests/') || isExcluded(inner, exclusions) || f.statements === 0) {
      continue;
    }
    const p = (f.covered / f.statements) * 100;
    if (p < FILE_MIN) {
      found.push(finding(f.name, 0, 'ratchet-file-coverage', `geaenderte Datei hat ${p.toFixed(1)}% (${f.covered}/${f.statements}) Line-Coverage, verlangt ${FILE_MIN}%`));
    }
    const own = methods.filter(m => m.file === f.name).sort((a, b) => a.line - b.line);
    const spans = methodRanges(own.map(m => m.line), readSource(f.name));
    own.forEach((m, i) => {
      if (m.crap > CRAP_MAX && touches(ranges, spans[i].start, spans[i].end)) {
        found.push(finding(f.name, m.line, 'ratchet-method-crap', `geaenderte Methode ${m.name} hat CRAP ${m.crap}, erlaubt ${CRAP_MAX}`));
      }
    });
  }
  return found;
}

module.exports = {
  isExcluded,
  totals,
  coverageFindings,
  loweredFindings,
  parsePhpstanBaseline,
  phpstanFindings,
  deptracFindings,
  parseChangedLines,
  methodRanges,
  changedFindings,
  formatFinding,
};
