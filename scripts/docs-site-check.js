#!/usr/bin/env node
/**
 * Prüfung der Dokumentationsseite (Spec 0027, #618): jeder interne Link und
 * jeder Bild-/Skript-/Stilpfad löst auf eine vorhandene Datei auf, und jede
 * deutsche Seite hat ihr englisches Gegenstück (und umgekehrt).
 *
 * Ohne Abhängigkeiten; aufgerufen aus test/docs-site.test.js und direkt:
 *   node scripts/docs-site-check.js [seitenordner]
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');

const SITE_ROOT = path.join(__dirname, '..', 'docs', 'site');
const LANGUAGES = ['de', 'en'];
const REFERENCE_PATTERN = /\b(?:href|src)\s*=\s*(["'])(.*?)\1/gi;
const EXTERNAL_PATTERN = /^(?:[a-z][a-z0-9+.-]*:|\/\/)/i;

function listHtmlFiles(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      return listHtmlFiles(full);
    }
    return entry.isFile() && entry.name.endsWith('.html') ? [full] : [];
  });
}

function internalTarget(reference) {
  if (reference === '' || reference.startsWith('#') || EXTERNAL_PATTERN.test(reference)) {
    return null;
  }
  return decodeURI(reference.split(/[?#]/)[0]);
}

function resolvesToFile(target) {
  if (!fs.existsSync(target)) {
    return false;
  }
  return fs.statSync(target).isFile() || fs.existsSync(path.join(target, 'index.html'));
}

/**
 * @param {string} root Seitenordner
 * @returns {{file: string, reference: string}[]} unauflösbare Verweise
 */
function findBrokenReferences(root) {
  const broken = [];
  for (const file of listHtmlFiles(root)) {
    const html = fs.readFileSync(file, 'utf8');
    for (const match of html.matchAll(REFERENCE_PATTERN)) {
      const target = internalTarget(match[2]);
      if (target === null) {
        continue;
      }
      const resolved = target.startsWith('/')
        ? path.join(root, target)
        : path.resolve(path.dirname(file), target);
      if (!resolved.startsWith(path.resolve(root)) || !resolvesToFile(resolved)) {
        broken.push({ file: path.relative(root, file), reference: match[2] });
      }
    }
  }
  return broken;
}

/**
 * @param {string} root Seitenordner
 * @returns {string[]} Seiten ohne Gegenstück, z. B. "en/teachers.html"
 */
function findMissingCounterparts(root) {
  const pages = Object.fromEntries(LANGUAGES.map((lang) => {
    const dir = path.join(root, lang);
    const files = fs.existsSync(dir) ? listHtmlFiles(dir).map((f) => path.relative(dir, f)) : [];
    return [lang, new Set(files)];
  }));
  const missing = [];
  for (const lang of LANGUAGES) {
    for (const other of LANGUAGES.filter((l) => l !== lang)) {
      for (const page of pages[lang]) {
        if (!pages[other].has(page)) {
          missing.push(path.join(other, page));
        }
      }
    }
  }
  return missing;
}

module.exports = { SITE_ROOT, findBrokenReferences, findMissingCounterparts };

if (require.main === module) {
  const root = path.resolve(process.argv[2] || SITE_ROOT);
  const broken = findBrokenReferences(root);
  const missing = findMissingCounterparts(root);
  for (const { file, reference } of broken) {
    console.error(`Kaputter Verweis in ${file}: ${reference}`);
  }
  for (const page of missing) {
    console.error(`Fehlendes Gegenstück: ${page}`);
  }
  if (broken.length || missing.length) {
    process.exit(1);
  }
  console.log('Dokumentationsseite: alle Verweise lösen auf, alle Gegenstücke vorhanden.');
}
