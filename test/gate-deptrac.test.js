'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const deptrac = require('../scripts/gate/deptrac');
const { FAST_CHECKS, ALL_CHECKS, buildStaticReport } = require('../scripts/gate/static');

const ROOT = path.join(__dirname, '..');
const CONTAINER = process.env.GATE_CONTAINER || 'kurspilot-gate-webserver-1';
const json = messages => JSON.stringify({ files: { '/c/a.php': { messages } } });
const msg = (verb, from, to, layers = 'A on B', line = 7) => ({ message: `${from} ${verb} not depend on ${to} (${layers})`, line, type: verb === 'must' ? 'error' : 'warning' });
const BASELINE = [
  '# Kopf ohne Ticket',
  'deptrac:',
  '  skip_violations:',
  '    # Adapter nutzt Fachlogik -> #693',
  '    x\\a:',
  '      - x\\b',
  '    x\\c:',
  '      - x\\d',
  '    # ohne Nummer',
  '    x\\e:',
  '      - x\\f',
].join('\n');

test('deptrac runs in fast and full gate', () => {
  assert.ok(FAST_CHECKS.includes('deptrac'));
  assert.ok(ALL_CHECKS.includes('deptrac'));
});

test('parseDeptracJson splits errors from baseline-skipped warnings', () => {
  const r = deptrac.parseDeptracJson(json([msg('must', 'x\\a', 'x\\b'), msg('should', 'x\\c', 'x\\d')]));
  assert.equal(r.violations.length, 1);
  assert.deepEqual([r.skipped[0].from, r.skipped[0].to, r.skipped[0].layers], ['x\\c', 'x\\d', 'A on B']);
  assert.throws(() => deptrac.parseDeptracJson(json([{ message: 'unlesbar', line: 1 }])));
});

test('parseUnassigned keeps class names only', () => {
  assert.deepEqual(deptrac.parseUnassigned('local_coursepilot\\neu\n\nThere are no unassigned tokens.\n'), ['local_coursepilot\\neu']);
});

test('parseBaseline attaches the ticket of the introducing comment', () => {
  const e = deptrac.parseBaseline(BASELINE);
  assert.deepEqual(e.map(x => [x.from, x.to, x.ticket]), [['x\\a', 'x\\b', '#693'], ['x\\c', 'x\\d', '#693'], ['x\\e', 'x\\f', null]]);
});

test('evaluate blocks new violations and unassigned classes, reports baselined ones', () => {
  const report = { violations: [{ file: '/c/a.php', line: 3, from: 'x\\n', to: 'x\\m', layers: 'A on B' }], skipped: [{ file: '/c/b.php', line: 4, from: 'x\\a', to: 'x\\b', layers: 'A on B' }] };
  const found = deptrac.evaluate(report, ['x\\unlisted'], deptrac.parseBaseline(BASELINE).slice(0, 1));
  assert.deepEqual(found.map(f => f.rule), ['deptrac-violation', 'deptrac-baselined', 'deptrac-unassigned']);
  assert.deepEqual(found.filter(deptrac.isBlocking).map(f => f.rule), ['deptrac-violation', 'deptrac-unassigned']);
});

test('evaluate flags stale and ticketless baseline entries', () => {
  const found = deptrac.evaluate({ violations: [], skipped: [] }, [], deptrac.parseBaseline(BASELINE));
  assert.deepEqual(found.map(f => f.rule), ['deptrac-baseline-stale', 'deptrac-baseline-stale', 'deptrac-baseline-stale', 'deptrac-baseline-ticket']);
  assert.ok(found.every(deptrac.isBlocking));
});

test('the committed baseline names a ticket for every entry', () => {
  const entries = deptrac.parseBaseline(fs.readFileSync(path.join(ROOT, 'scripts/gate/deptrac/deptrac-baseline.yaml'), 'utf8'));
  assert.ok(entries.length > 0);
  entries.forEach(e => assert.match(e.ticket || '', /^#\d+$/, `${e.from} -> ${e.to}`));
});

test('every external class has its own layer and the ruleset forbids tool to tool', () => {
  const yaml = fs.readFileSync(path.join(ROOT, 'scripts/gate/deptrac/deptrac.yaml'), 'utf8');
  const tools = fs.readdirSync(path.join(ROOT, 'Plugin/src/local_coursepilot/classes/external')).map(f => f.replace(/\.php$/, ''));
  tools.forEach(t => assert.ok(yaml.includes(`- name: Werkzeug_${t}\n`), `Schicht fehlt: ${t}`));
  assert.equal((yaml.match(/- name: Werkzeug_/g) || []).length, tools.length);
  assert.doesNotMatch(yaml, /^ {4}Werkzeug_\w+: \[[^\]]*Werkzeug_/m);
});

// Integration gegen den echten deptrac im Gate-Container; ohne Container uebersprungen.
const running = spawnSync('docker', ['inspect', '-f', '{{.State.Running}}', CONTAINER], { encoding: 'utf8' }).stdout.trim() === 'true';
test('deptrac in the gate container: tool to tool is a violation, an unlisted root class is unassigned', { skip: !running }, () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'deptrac-'));
  const remote = `/tmp/${path.basename(dir)}`;
  const cfg = fs.readFileSync(path.join(ROOT, 'scripts/gate/deptrac/deptrac.yaml'), 'utf8')
    .replace('/var/www/html/public/local/coursepilot/classes', `${remote}/classes`);
  fs.writeFileSync(path.join(dir, 'deptrac.yaml'), cfg);
  fs.writeFileSync(path.join(dir, 'deptrac-baseline.yaml'), 'deptrac:\n  skip_violations: {}\n');
  fs.cpSync(path.join(__dirname, 'fixtures/gate/deptrac/classes'), path.join(dir, 'classes'), { recursive: true });
  try {
    assert.equal(spawnSync('docker', ['cp', dir, `${CONTAINER}:${remote}`]).status, 0);
    const run = args => spawnSync('docker', ['exec', CONTAINER, 'php', '-d', 'memory_limit=-1', '/opt/dev-tools/vendor/bin/deptrac', args[0], '-c', `${remote}/deptrac.yaml`, '--no-cache', ...args.slice(1)], { encoding: 'utf8' });
    const { violations } = deptrac.parseDeptracJson(run(['analyse', '--no-progress', '--formatter=json']).stdout);
    assert.deepEqual(violations.map(v => [v.from, v.to]), [['local_coursepilot\\external\\get_sections', 'local_coursepilot\\external\\get_skill']]);
    assert.deepEqual(deptrac.parseUnassigned(run(['debug:unassigned']).stdout), ['local_coursepilot\\brand_new_class']);
  } finally {
    spawnSync('docker', ['exec', CONTAINER, 'rm', '-rf', remote]);
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('blocking deptrac findings turn the static report red via errors', () => {
  const report = buildStaticReport({ deptrac: [{ file: 'f', line: 1, rule: 'deptrac-violation', text: 't' }] }, ['deptrac: 1 blockierende Schichtbefunde']);
  assert.equal(report.errors.length, 1);
});
