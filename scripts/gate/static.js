/**
 * Statische Pruefungen des Gates (Spec 0029): moodle-cs, phpdoc, savepoints,
 * Mustache und ESLint ueber moodle-plugin-ci, PHPStan Level 6 mit Baseline und
 * die Covers-Pflicht fuer Testklassen und englische Kommentare (english.js). Dieses Modul enthaelt die Parser und
 * Runner; gate.js bindet es ein. Befunde werden berichtet; nur deptrac-Schichtbefunde blockieren.
 *
 * Befundzeile: `datei:zeile: regel: text` (wie die Messwerte in gate.js).
 */

'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const { checkEnglishComments } = require('./english');
const { checkExternalCapabilities } = require('./capability');
const deptrac = require('./deptrac');

const PLUGIN_REL = 'Plugin/src/local_coursepilot';
const PLUGIN_IN_CONTAINER = '/var/www/html/public/local/coursepilot';
const PHPSTAN_CONFIG = '/var/www/phpstan-config/phpstan.neon';
const PHPSTAN_BASELINE_REL = 'scripts/gate/phpstan/phpstan-baseline.neon';
// TODO-Kommentare muessen auf ein Issue dieses Repos verlinken.
const TODO_COMMENT_REGEX = 'https://github\\.com/matthiasgruenwald/moodle-coursepilot/issues/[0-9]+';
const CONTAINER_PATH = '/opt/node/bin:/opt/java/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';

/** Pfad im Container -> Pfad im Repo (fuer Datei:Zeile-Ausgabe). */
function repoPath(file) {
  return file.startsWith(PLUGIN_IN_CONTAINER) ? PLUGIN_REL + file.slice(PLUGIN_IN_CONTAINER.length) : file;
}

const finding = (file, line, rule, text) => ({ file: repoPath(file), line, rule, text: text.replace(/\s+/g, ' ').trim() });
const formatFinding = f => `${f.file}:${f.line}: ${f.rule}: ${f.text}`;

/** moodle-cs: `FILE:`-Block, Zeilen ` 12 | ERROR | [x] text (sniff)`, Folgezeilen `   |       |   text`. */
function parsePhpcs(out) {
  const found = [];
  let file = null;
  let cur = null;
  const flush = () => {
    if (cur) {
      found.push(finding(file, cur.line, cur.rule, cur.text));
      cur = null;
    }
  };
  for (const row of out.split('\n')) {
    const head = row.match(/^FILE: (.+)$/);
    const hit = row.match(/^\s*(\d+) \| (ERROR|WARNING)\s*\| (?:\[.\] )?(.*)$/);
    const more = row.match(/^\s*\|\s+\|\s+(.*)$/);
    if (head) {
      flush();
      file = head[1].trim();
    } else if (hit && file) {
      flush();
      cur = { line: Number(hit[1]), rule: hit[2] === 'ERROR' ? 'moodle-cs-error' : 'moodle-cs-warning', text: hit[3] };
    } else if (more && cur) {
      cur.text += ` ${more[1]}`;
    } else {
      flush();
    }
  }
  flush();
  return found;
}

/** phpdoc: Dateizeile (absoluter Pfad), darunter `    Line 12: text (error|warning)`. */
function parsePhpdoc(out) {
  const found = [];
  let file = null;
  for (const row of out.split('\n')) {
    const line = row.match(/^\s+Line (\d+): (.*)$/);
    if (line && file) {
      found.push(finding(file, Number(line[1]), 'phpdoc', line[2]));
    } else if (row.startsWith('/')) {
      file = row.trim();
    }
  }
  return found;
}

/** Mustache-Lint: `datei.mustache - ERROR|WARNING: text` (INFO ist kein Befund). */
function parseMustache(out) {
  const found = [];
  for (const row of out.split('\n')) {
    const m = row.match(/^(\S+\.mustache) - (ERROR|WARNING): (.*)$/);
    if (m) {
      const at = m[3].match(/line (\d+)/);
      found.push(finding(m[1], at ? Number(at[1]) : 0, 'mustache', `${m[2]}: ${m[3]}`));
    }
  }
  return found;
}

/**
 * grunt: ESLint im Stylish-Format (`/pfad.js`, darunter `  12:3  error  text  regel`)
 * und `File is stale and needs to be rebuilt: amd/build/x.js`.
 */
function parseGrunt(out) {
  const found = [];
  let file = null;
  for (const row of out.split('\n')) {
    const stale = row.match(/^File is stale and needs to be rebuilt: (.+)$/);
    const lint = row.match(/^\s+(\d+):\d+\s+(error|warning)\s+(.*?)(?:\s{2,}(\S+))?$/);
    if (stale) {
      found.push(finding(`${PLUGIN_IN_CONTAINER}/${stale[1].trim()}`, 0, 'grunt-stale', 'Build-Datei veraltet, grunt amd ausfuehren'));
    } else if (lint && file) {
      found.push(finding(file, Number(lint[1]), 'eslint', `${lint[2]}: ${lint[3]}${lint[4] ? ` (${lint[4]})` : ''}`));
    } else if (/^\/.*\.js$/.test(row.trim())) {
      file = row.trim();
    }
  }
  return found;
}

/** savepoints: Befund nur ueber den Exitcode, Text aus den Fehlerzeilen. */
function parseSavepoints(out, code) {
  if (code === 0) {
    return [];
  }
  const detail = out.split('\n').filter(l => /error|missing|wrong|not|!/i.test(l)).join(' ');
  return [finding(`${PLUGIN_IN_CONTAINER}/db/upgrade.php`, 0, 'savepoints', detail || 'Upgrade-Savepoints inkonsistent')];
}

/** PHPStan `--error-format=json`. Interne Fehler (`errors`) sind Werkzeugfehler. */
function parsePhpstan(json) {
  const data = JSON.parse(json);
  const found = [];
  for (const [file, entry] of Object.entries(data.files || {})) {
    for (const m of entry.messages) {
      found.push(finding(file, m.line || 0, 'phpstan', `${m.message}${m.identifier ? ` (${m.identifier})` : ''}`));
    }
  }
  return { found, toolErrors: data.errors || [] };
}

/**
 * Covers-Pflicht: jede nicht abstrakte Testklasse braucht #[CoversClass],
 * #[CoversFunction], #[CoversNothing] o. ae. oder ein @covers.
 * @param {{file: string, content: string}[]} tests Testdateien (absolute oder repo-relative Pfade)
 */
function findMissingCovers(tests) {
  const found = [];
  for (const t of tests) {
    const decl = /^(abstract\s+)?(?:final\s+)?class\s+(\w+)/gm;
    let m;
    while ((m = decl.exec(t.content)) !== null) {
      if (m[1] || !/_test$/.test(m[2])) {
        continue;
      }
      const before = t.content.slice(0, m.index);
      const block = before.slice(before.lastIndexOf('\n}\n') + 1);
      if (!/#\[[\w\\]*Covers\w+|@covers\b/.test(block)) {
        const line = before.split('\n').length;
        found.push({ file: t.file, line, rule: 'covers-missing', text: `Testklasse ${m[2]} ohne Covers-Angabe` });
      }
    }
  }
  return found;
}

/** Testdateien unter dem Plugin als {file (repo-relativ), content}. */
function readPluginTests(repo) {
  const root = path.join(repo, PLUGIN_REL, 'tests');
  const out = [];
  const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).forEach(e => {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      walk(p);
    } else if (e.name.endsWith('_test.php')) {
      out.push({ file: path.relative(repo, p), content: fs.readFileSync(p, 'utf8') });
    }
  });
  walk(root);
  return out;
}

/**
 * Pruefungen. `run` liefert {out, code}; `parse` die Befunde. Ein Werkzeug, das
 * mit Fehlercode endet, aber nichts Auswertbares meldet, ist ein Werkzeugfehler
 * (rot), kein "sauber".
 */
const CHECKS = {
  'moodle-cs': { args: ['phpcs', '--max-warnings=-1', `--todo-comment-regex=${TODO_COMMENT_REGEX}`, '.'], parse: parsePhpcs },
  phpdoc: { args: ['phpdoc', '.'], parse: parsePhpdoc },
  savepoints: { args: ['savepoints', '.'], parse: parseSavepoints },
  mustache: { args: ['mustache', '.'], parse: parseMustache },
  eslint: { args: ['grunt', '.'], parse: parseGrunt },
  phpstan: { parse: null },
};

const FAST_CHECKS = ['moodle-cs', 'phpdoc', 'phpstan', 'covers', 'english', 'external', 'deptrac'];
const ALL_CHECKS = ['moodle-cs', 'phpdoc', 'savepoints', 'mustache', 'eslint', 'phpstan', 'covers', 'english', 'external', 'deptrac'];

function containerExec(container, args) {
  const env = ['-e', 'MOODLE_DIR=/var/www/html', '-e', `PATH=${CONTAINER_PATH}`];
  const r = spawnSync('docker', ['exec', ...env, '-w', PLUGIN_IN_CONTAINER, container, ...args], { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
  return { out: `${r.stdout || ''}${r.stderr || ''}`, stdout: r.stdout || '', code: r.status === null ? 1 : r.status };
}

const ciArgs = args => ['php', '-d', 'memory_limit=-1', '/opt/plugin-ci/vendor/bin/moodle-plugin-ci', ...args];
const phpstanArgs = extra => ['php', '-d', 'memory_limit=-1', '/opt/dev-tools/vendor/bin/phpstan', 'analyse', '-c', PHPSTAN_CONFIG, '--no-progress', ...extra];

/** Fuehrt eine Containerpruefung aus -> {found, toolError|null}. */
function runCheck(name, container, exec = containerExec) {
  const check = CHECKS[name];
  if (name === 'phpstan') {
    const r = exec(container, phpstanArgs(['--error-format=json']));
    try {
      const { found, toolErrors } = parsePhpstan(r.stdout);
      return { found, toolError: toolErrors.length > 0 ? toolErrors.join(' | ') : null };
    } catch (e) {
      return { found: [], toolError: `PHPStan-Ausgabe ungueltig (Exitcode ${r.code}): ${r.out.slice(0, 300)}` };
    }
  }
  const r = exec(container, ciArgs(check.args));
  const found = check.parse(r.out, r.code);
  // Mustache und grunt melden manche Befunde nur im Text; Exitcode != 0 ohne Befund = Werkzeugfehler.
  const toolError = r.code !== 0 && found.length === 0 ? `Exitcode ${r.code} ohne auswertbare Befunde: ${r.out.slice(-300)}` : null;
  return { found, toolError };
}

/**
 * Alle gewaehlten Pruefungen. Gibt Befunde je Pruefung, Werkzeugfehler und
 * Zaehler zurueck. `exec` ist fuer Tests austauschbar.
 * @returns {{lines: string[], results: object, errors: string[], summary: string}}
 */
function runStatic(names, { container, repo, exec = containerExec }) {
  const results = {};
  const errors = [];
  for (const name of names) {
    if (name === 'covers') {
      results[name] = findMissingCovers(readPluginTests(repo));
      continue;
    }
    if (name === 'english') {
      results[name] = checkEnglishComments(repo);
      continue;
    }
    if (name === 'external') {
      results[name] = checkExternalCapabilities(repo);
      continue;
    }
    const isLayers = name === 'deptrac';
    const { found, toolError } = isLayers ? deptrac.runDeptrac({ container, repo, exec, toRepo: repoPath }) : runCheck(name, container, exec);
    results[name] = found;
    const blocking = isLayers ? found.filter(deptrac.isBlocking).length : 0;
    if (blocking > 0) {
      errors.push(`deptrac: ${blocking} blockierende Schichtbefunde`);
    }
    if (toolError) {
      errors.push(`${name}: ${toolError}`);
    }
  }
  return buildStaticReport(results, errors);
}

function buildStaticReport(results, errors) {
  const all = Object.values(results).flat();
  const lines = all.map(formatFinding);
  const count = rule => all.filter(f => f.rule === rule).length;
  const parts = [];
  for (const [name, found] of Object.entries(results)) {
    if (name === 'moodle-cs') {
      parts.push(`moodle_cs_errors=${count('moodle-cs-error')}`, `moodle_cs_warnings=${count('moodle-cs-warning')}`);
    } else {
      parts.push(`${name.replace('-', '_')}=${found.length}`);
    }
  }
  return { lines, results, errors, summary: `summary: static ${parts.join(' ')}${errors.length ? ` tool_errors=${errors.length}` : ''}` };
}

/** PHPStan-Baseline neu erzeugen und ins Repo kopieren. */
function generatePhpstanBaseline({ container, repo, gateDir, exec = containerExec }) {
  const inContainer = '/var/www/reports/phpstan-baseline.neon';
  const r = exec(container, phpstanArgs([`--generate-baseline=${inContainer}`, '--allow-empty-baseline']));
  const generated = path.join(gateDir, 'reports', 'phpstan-baseline.neon');
  if (r.code !== 0 || !fs.existsSync(generated)) {
    throw new Error(`PHPStan-Baseline nicht erzeugt (Exitcode ${r.code}): ${r.out.slice(-300)}`);
  }
  fs.copyFileSync(generated, path.join(repo, PHPSTAN_BASELINE_REL));
}

module.exports = {
  PLUGIN_REL,
  PLUGIN_IN_CONTAINER,
  FAST_CHECKS,
  ALL_CHECKS,
  repoPath,
  parsePhpcs,
  parsePhpdoc,
  parseMustache,
  parseGrunt,
  parseSavepoints,
  parsePhpstan,
  findMissingCovers,
  readPluginTests,
  runStatic,
  buildStaticReport,
  generatePhpstanBaseline,
};
