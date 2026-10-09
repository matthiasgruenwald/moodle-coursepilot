/**
 * Gate-Pruefung "externe Funktionen validieren Kontext und pruefen eine
 * Capability" (Regel M5, Moodle-Doku "Writing a new service").
 *
 * Jede Klasse in classes/external/ muss in execute() `validate_context()` und
 * `require_capability()`/`has_capability()` aufrufen (auch ueber Methoden derselben
 * Klasse, die execute() per self::/static::/$this-> aufruft), oder einen Resolver aus
 * scripts/gate/external-resolvers.json (feste, versionierte Liste; je Eintrag
 * `call` als `klasse::methode`, `validates` und/oder `requires`, `reason`).
 *
 * Ausnahmen nur ueber scripts/gate/external-ignore.json:
 * `[{ "file": "repo-relativ", "missing": "context"|"capability", "reason": "..." }]`.
 * Ein Eintrag ohne Begruendung ist selbst ein Befund.
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');

const EXTERNAL_REL = 'Plugin/src/local_coursepilot/classes/external/';
const IGNORE_REL = 'scripts/gate/external-ignore.json';
const RESOLVERS_REL = 'scripts/gate/external-resolvers.json';
const KINDS = { context: 'validate_context()', capability: 'require_capability()/has_capability()' };
const DIRECT = { context: /\bvalidate_context\s*\(/, capability: /\b(?:require_capability|has_capability)\s*\(/ };

/** Entfernt Kommentare und String-Literale, damit Treffer nur echter Code sind. */
function stripNonCode(src) {
  return src
    .replace(/\/\*[\s\S]*?\*\//g, ' ')
    .replace(/'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"/g, "''")
    .replace(/(?:\/\/|#(?!\[)).*$/gm, ' ');
}

/** Rumpf von `function name(` im bereinigten Code als {body, line} oder null. */
function methodBody(code, name) {
  const m = new RegExp(`\\bfunction\\s+${name}\\s*\\(`).exec(code);
  if (!m) {
    return null;
  }
  const open = code.indexOf('{', code.indexOf(')', m.index));
  let depth = 0;
  for (let i = open; i < code.length; i++) {
    depth += code[i] === '{' ? 1 : code[i] === '}' ? -1 : 0;
    if (depth === 0) {
      return { body: code.slice(open, i + 1), line: code.slice(0, m.index).split('\n').length };
    }
  }
  return null;
}

/**
 * execute() samt Methoden derselben Klasse, die es per self::/static::/$this-> aufruft
 * (transitiv), als {body, line} oder null.
 */
function executeBody(src) {
  const code = stripNonCode(src);
  const entry = methodBody(code, 'execute');
  if (!entry) {
    return null;
  }
  const seen = new Set(['execute']);
  const queue = [entry.body];
  let body = '';
  while (queue.length) {
    const current = queue.shift();
    body += `\n${current}`;
    for (const [, name] of current.matchAll(/(?:\bself::|\bstatic::|\$this->)(\w+)\s*\(/g)) {
      const callee = seen.has(name) ? null : methodBody(code, name);
      seen.add(name);
      if (callee) {
        queue.push(callee.body);
      }
    }
  }
  return { body, line: entry.line };
}

const finding = (file, line, text) => ({ file, line, rule: 'external-check-missing', text });
const invalid = (line, text) => ({ file: IGNORE_REL, line, rule: 'external-ignore-invalid', text });
const hasReason = e => typeof e.reason === 'string' && e.reason.trim() !== '';

/** Eintraege der Resolver-Liste, die Pflicht `kind` ('context'|'capability') nachweislich erfuellen. */
function resolverCalls(resolvers, kind) {
  const flag = kind === 'context' ? 'validates' : 'requires';
  return resolvers.filter(r => r[flag] === true).map(r => r.call);
}

/**
 * @param {{file: string, content: string}[]} files Dateien aus classes/external/ (repo-relativ)
 * @param {{call: string, validates?: boolean, requires?: boolean, reason: string}[]} resolvers
 * @param {{file: string, missing: string, reason: string}[]} ignores
 */
function findExternalGaps(files, resolvers = [], ignores = []) {
  const found = [];
  resolvers.forEach((r, k) => {
    if (!r || !r.call || !hasReason(r) || (r.validates !== true && r.requires !== true)) {
      found.push(invalid(k + 1, `Resolver ${k + 1} in ${RESOLVERS_REL} braucht call, validates/requires und reason`));
    }
  });
  ignores.forEach((e, k) => {
    if (!e || !e.file || !KINDS[e.missing] || !hasReason(e)) {
      found.push(invalid(k + 1, `Eintrag ${k + 1} braucht file, missing (context|capability) und reason`));
    }
  });
  const ignored = (file, kind) => ignores.some(e => e && e.file === file && e.missing === kind && hasReason(e));
  for (const f of files) {
    const exec = executeBody(f.content);
    const cls = path.basename(f.file, '.php');
    if (!exec) {
      found.push(finding(f.file, 1, `Klasse ${cls}: execute() nicht gefunden`));
      continue;
    }
    for (const kind of Object.keys(KINDS)) {
      const viaResolver = resolverCalls(resolvers, kind).some(c => new RegExp(`\\b${c.replace(/[:\\]/g, m => `\\${m}`)}\\s*\\(`).test(exec.body));
      const satisfied = DIRECT[kind].test(exec.body) || viaResolver;
      if (!satisfied && !ignored(f.file, kind)) {
        found.push(finding(f.file, exec.line, `Klasse ${cls}: ${KINDS[kind]} fehlt in execute()`));
      }
      if (satisfied && ignored(f.file, kind)) {
        found.push(invalid(0, `Ausnahme für ${cls} (${kind}) ist veraltet: Aufruf vorhanden, Eintrag entfernen`));
      }
    }
  }
  return found;
}

const readJsonList = (repo, rel, key) => {
  const p = path.join(repo, rel);
  if (!fs.existsSync(p)) {
    return [];
  }
  const data = JSON.parse(fs.readFileSync(p, 'utf8'));
  const list = key ? data[key] : data;
  if (!Array.isArray(list)) {
    throw new Error(`${rel} hat kein Array${key ? ` "${key}"` : ''}`);
  }
  return list;
};

function checkExternalCapabilities(repo) {
  const dir = path.join(repo, EXTERNAL_REL);
  const files = fs.readdirSync(dir).filter(n => n.endsWith('.php')).sort()
    .map(n => ({ file: EXTERNAL_REL + n, content: fs.readFileSync(path.join(dir, n), 'utf8') }));
  return findExternalGaps(files, readJsonList(repo, RESOLVERS_REL, 'resolvers'), readJsonList(repo, IGNORE_REL));
}

module.exports = { findExternalGaps, checkExternalCapabilities };
