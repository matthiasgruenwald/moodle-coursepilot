/**
 * Gate-Pruefung "PHP-Kommentare und Docblocks sind englisch" (ADR 0024).
 * Ja/nein-pruefbar, keine Spracherkennung: Treffer ist ein Umlaut/ß oder ein
 * Wort aus GERMAN_WORDS in einem Kommentar. String-Literale zaehlen nicht,
 * `lang/de/` und `tests/fixtures/` werden uebersprungen.
 *
 * Fehlalarme nur ueber scripts/gate/english-ignore.json:
 * `[{ "file": "repo-relativ", "match": "Treffer", "reason": "Begruendung" }]`.
 * Ein Eintrag ohne Begruendung ist selbst ein Befund.
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');

const PLUGIN_REL = 'Plugin/src/local_coursepilot';
const IGNORE_REL = 'scripts/gate/english-ignore.json';
const SKIP_DIRS = [`${PLUGIN_REL}/lang/de/`, `${PLUGIN_REL}/tests/fixtures/`];
// Nur Woerter, die im Englischen nicht vorkommen (kein "die", "was", "war", "an").
const GERMAN_WORDS = ['und', 'oder', 'nicht', 'wird', 'werden', 'wurde', 'wenn', 'aber', 'eine', 'einer', 'einen', 'dass', 'kann', 'sind', 'ist', 'auch', 'nur', 'der', 'das', 'dem', 'fuer', 'ueber', 'zum', 'zur', 'ohne'];
const GERMAN_REGEX = new RegExp(`[äöüÄÖÜß]|\\b(?:${GERMAN_WORDS.join('|')})\\b`, 'gu');

/** Kommentare einer PHP-Quelle als {line, text}; Strings, Heredocs und Attribute (`#[`) sind keine. */
function extractComments(src) {
  const out = [];
  let i = 0;
  let line = 1;
  const n = src.length;
  while (i < n) {
    const c = src[i];
    const two = src.substr(i, 2);
    if (c === '\'' || c === '"') {
      i++;
      while (i < n && src[i] !== c) {
        if (src[i] === '\\') {
          i++;
        }
        line += src[i] === '\n' ? 1 : 0;
        i++;
      }
      i++;
    } else if (two === '/*') {
      const end = src.indexOf('*/', i + 2);
      const stop = end === -1 ? n : end + 2;
      const body = src.slice(i, stop);
      body.split('\n').forEach((text, k) => out.push({ line: line + k, text }));
      line += body.split('\n').length - 1;
      i = stop;
    } else if (two === '//' || (c === '#' && src[i + 1] !== '[')) {
      const end = src.indexOf('\n', i);
      const stop = end === -1 ? n : end;
      out.push({ line, text: src.slice(i, stop) });
      i = stop;
    } else if (src.substr(i, 3) === '<<<') {
      const m = src.slice(i).match(/^<<<\s*['"]?(\w+)['"]?\r?\n/);
      const end = m ? src.slice(i + m[0].length).search(new RegExp(`^\\s*${m[1]}\\b`, 'm')) : -1;
      const stop = end === -1 ? i + 3 : i + m[0].length + end;
      line += (src.slice(i, stop).match(/\n/g) || []).length;
      i = stop;
    } else {
      line += c === '\n' ? 1 : 0;
      i++;
    }
  }
  return out;
}

/** Treffer je Kommentarzeile: [{line, match}]. */
function germanHits(src) {
  const hits = [];
  for (const { line, text } of extractComments(src)) {
    for (const m of text.matchAll(GERMAN_REGEX)) {
      hits.push({ line, match: m[0] });
    }
  }
  return hits;
}

const finding = (file, line, text) => ({ file, line, rule: 'english-comment', text });

/**
 * @param {{file: string, content: string}[]} files PHP-Dateien (repo-relativ)
 * @param {{file: string, match: string, reason: string}[]} ignores
 */
function findGermanComments(files, ignores = []) {
  const found = [];
  ignores.forEach((e, k) => {
    if (!e || !e.file || !e.match || typeof e.reason !== 'string' || e.reason.trim() === '') {
      found.push({ file: IGNORE_REL, line: k + 1, rule: 'english-ignore-invalid', text: `Eintrag ${k + 1} braucht file, match und reason` });
    }
  });
  const ignored = (file, match) => ignores.some(e => e && e.file === file && e.match === match && e.reason && e.reason.trim() !== '');
  for (const f of files) {
    if (SKIP_DIRS.some(d => f.file.startsWith(d))) {
      continue;
    }
    for (const h of germanHits(f.content)) {
      if (!ignored(f.file, h.match)) {
        found.push(finding(f.file, h.line, `deutscher Kommentartext: "${h.match}"`));
      }
    }
  }
  return found;
}

function readPluginPhp(repo) {
  const out = [];
  const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).forEach(e => {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      walk(p);
    } else if (e.name.endsWith('.php')) {
      out.push({ file: path.relative(repo, p), content: fs.readFileSync(p, 'utf8') });
    }
  });
  walk(path.join(repo, PLUGIN_REL));
  return out;
}

function readIgnores(repo) {
  const p = path.join(repo, IGNORE_REL);
  return fs.existsSync(p) ? JSON.parse(fs.readFileSync(p, 'utf8')) : [];
}

const checkEnglishComments = repo => findGermanComments(readPluginPhp(repo), readIgnores(repo));

module.exports = { GERMAN_WORDS, extractComments, germanHits, findGermanComments, checkEnglishComments };
