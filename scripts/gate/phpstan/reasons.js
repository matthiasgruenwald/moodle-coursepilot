/** Checks the rationale attached to each PHPStan suppression, before it can waive a finding. */
'use strict';

const fs = require('node:fs');
const path = require('node:path');

const BASELINE = 'scripts/gate/phpstan/phpstan-baseline.neon';
const PREFIX = '../html/public/local/coursepilot/';
const PLUGIN = 'Plugin/src/local_coursepilot';

function checkBaselineReasons(repo) {
  const text = fs.readFileSync(path.join(repo, BASELINE), 'utf8');
  const found = [];
  if (/^parameters:\n\tignoreErrors: \[\]\n?$/.test(text)) {
    return found;
  }
  const entries = [...text.matchAll(/^\t\t-\n([\s\S]*?)(?=^\t\t-\n|$(?![\s\S]))/gm)];
  if (!entries.length || text.slice(0, entries[0]?.index) !== 'parameters:\n\tignoreErrors:\n'
    || [...text.matchAll(/^\s*-\s*$/gm)].length !== entries.length
    || entries.some(m => !/^\t\t\tmessage: '.+'\n\t\t\tidentifier: \S+\n\t\t\tcount: [1-9][0-9]*\n\t\t\tpath: \S+$/.test(
      m[1].split('\n').filter(l => l.trim() && !/^\t\t\t# /.test(l)).join('\n')))) {
    return [{ file: BASELINE, line: 1, rule: 'phpstan-baseline-reason', text: 'Unsupported PHPStan baseline layout; no suppressions may skip rationale validation' }];
  }
  for (const match of entries) {
    const block = match[1];
    const reason = block.match(/^\t\t\t# reason: (.+)$/m)?.[1];
    const resolution = block.match(/^\t\t\t# resolution: (.+)$/m)?.[1];
    const identifier = block.match(/^\t\t\tidentifier: (.+)$/m)?.[1];
    const file = block.match(/^\t\t\tpath: (.+)$/m)?.[1];
    let error;
    if (!reason || reason.trim().length < 30) {
      error = 'Suppression needs a concrete reason in the same entry';
    } else if (!/^https:\/\/github\.com\/matthiasgruenwald\/moodle-coursepilot\/issues\/[1-9][0-9]*$/.test(resolution || '')
      && (resolution !== 'permanent' || identifier?.startsWith('missingType.'))) {
      error = 'Temporary suppression needs a full resolution issue URL';
    } else {
      try {
        const relative = file?.startsWith(PREFIX) ? file.slice(PREFIX.length) : '';
        if (!relative || relative.split('/').includes('..')) {
          throw new Error('Evidence requires a plugin-relative suppression path');
        }
        const source = fs.readFileSync(path.join(repo, PLUGIN, relative), 'utf8');
        const evidence = JSON.parse(block.match(/^\t\t\t# evidence: (.+)$/m)?.[1] || 'null');
        if (!evidence || Array.isArray(evidence) || typeof evidence !== 'object' || !Object.keys(evidence).length
          || !Object.hasOwn(evidence, relative)) {
          throw new Error('Evidence must include the suppressed file');
        }
        const message = block.match(/^\t\t\tmessage: '(.*)'$/m)[1].replaceAll("''", "'").replace(/\\(.)/g, '$1');
        const declaration = message.match(/(?:Method .*::|Function )(\w+)\(\) (?:has parameter|return type|should return)/)?.[1];
        const parameter = message.match(/has parameter (\$\w+)/)?.[1];
        const snippets = Object.values(evidence).flat();
        if (declaration && !snippets.some(s => typeof s === 'string' && new RegExp(`function\\s+${declaration}\\s*\\(`).test(s))) {
          throw new Error(`Evidence must identify the suppressed declaration ${declaration}()`);
        }
        if (parameter && !snippets.some(s => typeof s === 'string' && s.includes(parameter))) {
          throw new Error(`Evidence must identify the suppressed parameter ${parameter}`);
        }
        for (const [ref, snippets] of Object.entries(evidence)) {
          if (path.isAbsolute(ref) || ref.split('/').includes('..') || !ref.endsWith('.php')) {
            throw new Error('Evidence path must stay inside the plugin');
          }
          const content = fs.readFileSync(path.join(repo, PLUGIN, ref), 'utf8');
          if (ref !== relative) {
            const trait = content.match(/^trait (\w+) /m)?.[1];
            const used = [...source.matchAll(/^ +use ([\w\\]+);/gm)].some(m => m[1].split('\\').at(-1) === trait);
            if (!trait || !used) {
              throw new Error('Evidence from another file must belong to a used trait');
            }
          }
          if (!Array.isArray(snippets) || !snippets.length
            || snippets.some(s => typeof s !== 'string' || s.trim().length < 10 || !content.includes(s))) {
            throw new Error(`Evidence no longer matches ${ref}`);
          }
        }
      } catch (e) {
        error = `Invalid evidence: ${e.message}`;
      }
    }
    if (error) {
      found.push({ file: BASELINE, line: text.slice(0, match.index).split('\n').length,
        rule: 'phpstan-baseline-reason', text: error });
    }
  }
  return found;
}

module.exports = { checkBaselineReasons };
