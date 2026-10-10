'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const ROOT = path.join(__dirname, '..');
const GATE = path.join(ROOT, 'scripts', 'gate', 'gate.js');
const PLUGIN = 'Plugin/src/local_coursepilot';
const CONTAINER_PLUGIN = '/var/www/html/public/local/coursepilot';

// Methoden an festen Zeilen: easy 8-10, hard 12-17, next 22-24.
const SOURCE = [
  '<?php',
  'namespace local_coursepilot;',
  '',
  'class a {',
  '    /**',
  '     * Easy.',
  '     */',
  '    public function easy(): int {',
  '        return 1;',
  '    }',
  '',
  '    public function hard(int $x): int {',
  '        if ($x) {',
  '            return 2;',
  '        }',
  '        return 3;',
  '    }',
  '',
  '    /**',
  '     * Docblock of next.',
  '     */',
  '    public function next(): int {',
  '        return 4;',
  '    }',
  '}',
  '',
].join('\n');

const BASELINE = {
  armedAt: 'HEAD',
  coverage: { covered: 80, statements: 100 },
  excluded: [{ path: 'lang/', reason: 'Sprachtabelle' }],
};

const PHPSTAN = (count) => `parameters:\n\tignoreErrors:\n\t\t-\n\t\t\tmessage: '#^x$#'\n\t\t\tidentifier: missingType.iterableValue\n\t\t\tcount: ${count}\n\t\t\tpath: ../html/public/local/coursepilot/classes/a.php\n`;
const DEPTRAC = (pairs) => `deptrac:\n  skip_violations:\n    # Grund -> #1\n${pairs.map(([f, t]) => `    ${f}:\n      - ${t}\n`).join('')}`;

function run(cwd, args, extraEnv = {}, input) {
  const r = spawnSync('node', [GATE, ...args], { cwd, encoding: 'utf8', input, env: { ...process.env, GATE_REPO: cwd, GATE_BASE_REF: 'HEAD', GATE_FAILURE_LOG: path.join(cwd, 'failures.log'), ...extraEnv } });
  return { status: r.status, out: `${r.stdout}${r.stderr}` };
}

function git(cwd, ...args) {
  const r = spawnSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', ...args], { cwd, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stderr);
  return r.stdout;
}

function write(repo, rel, content) {
  fs.mkdirSync(path.dirname(path.join(repo, rel)), { recursive: true });
  fs.writeFileSync(path.join(repo, rel), content);
}

/** Repo mit festem Basisstand (ein Commit), in dem Aenderungen gegen HEAD geprueft werden. */
function makeRepo({ baseline = BASELINE, phpstan = 3, pairs = [['a\\x', 'a\\y']] } = {}) {
  const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-ratchet-'));
  git(repo, 'init', '-q');
  write(repo, 'scripts/gate/baseline.json', JSON.stringify(baseline));
  write(repo, 'scripts/gate/phpstan/phpstan-baseline.neon', PHPSTAN(phpstan));
  write(repo, 'scripts/gate/deptrac/deptrac-baseline.yaml', DEPTRAC(pairs));
  write(repo, `${PLUGIN}/classes/a.php`, SOURCE);
  write(repo, `${PLUGIN}/tests/a_test.php`, "<?php\n#[CoversClass(a::class)]\nclass a_test {}\n");
  write(repo, `${PLUGIN}/lang/en/local_coursepilot.php`, "<?php\n$string['a'] = 'b';\n");
  git(repo, 'add', '-A');
  git(repo, 'commit', '-qm', 'base');
  return repo;
}

/** Clover mit Datei a.php (Methoden easy/hard/next) und optionalen weiteren Dateien. */
function clover({ aCovered = 9, aStatements = 10, crapHard = 12, crapNext = 1, extraFiles = [], withA = true } = {}) {
  const file = (name, st, cov, methods = '') => `<file name="${CONTAINER_PLUGIN}/${name}">${methods}<metrics statements="${st}" coveredstatements="${cov}"/></file>`;
  const m = (line, name, crap) => `<line num="${line}" type="method" name="${name}" complexity="2" crap="${crap}" count="1"/>`;
  const files = [
    ...(withA ? [file('classes/a.php', aStatements, aCovered, m(8, 'easy', 1) + m(12, 'hard', crapHard) + m(22, 'next', crapNext))] : []),
    ...extraFiles.map(e => file(e.name, e.st, e.cov, e.methods || '')),
  ];
  const st = (withA ? aStatements : 0) + extraFiles.reduce((a, e) => a + e.st, 0);
  const cov = (withA ? aCovered : 0) + extraFiles.reduce((a, e) => a + e.cov, 0);
  return `<?xml version="1.0"?><coverage><project>${files.join('')}<metrics statements="${st}" coveredstatements="${cov}"/></project></coverage>`;
}

function ratchet(repo, xml) {
  const file = path.join(repo, 'clover.xml');
  fs.writeFileSync(file, xml);
  return run(repo, ['ratchet', file]);
}

// Der Bestand misst 90/100 = 90 % gesamt; Baseline 80 %. a.php: 9/10.
const goodExtra = [{ name: 'classes/b.php', st: 90, cov: 81 }];

test('ratchet: unveraendertes Repo mit Coverage ueber der Baseline ist gruen', () => {
  const repo = makeRepo();
  const r = ratchet(repo, clover({ extraFiles: goodExtra, crapHard: 40 }));
  assert.equal(r.status, 0, r.out);
  assert.match(r.out, /summary: ratchet .*coverage=90.00% baseline=80.00% violations=0/);
});

test('ratchet: a large integration diff still checks changed production files', () => {
  const repo = makeRepo();
  write(repo, 'large.txt', 'old line\n'.repeat(70000));
  git(repo, 'add', '-A');
  git(repo, 'commit', '-qm', 'large base');
  write(repo, 'large.txt', 'new line\n'.repeat(70000));
  write(repo, `${PLUGIN}/classes/a.php`, SOURCE.replace('return 1;', 'return 11;'));
  const r = ratchet(repo, clover({ aCovered: 5, extraFiles: goodExtra }));
  assert.equal(r.status, 1);
  assert.match(r.out, /ratchet-file-coverage: .*50\.0%/);
  assert.doesNotMatch(r.out, /gate-error/);
});

test('ratchet: Gesamt-Coverage unter der Baseline ist rot', () => {
  const repo = makeRepo();
  const r = ratchet(repo, clover({ aCovered: 5, extraFiles: [{ name: 'classes/b.php', st: 90, cov: 60 }] }));
  assert.equal(r.status, 1);
  assert.match(r.out, /scripts\/gate\/baseline\.json:0: ratchet-coverage: Gesamt-Coverage 65\.00%/);
});

test('ratchet: Bestand unter 90 % mit hohem CRAP blockiert nur, wenn die Datei geaendert wird', () => {
  const repo = makeRepo();
  const xml = clover({ aCovered: 5, crapHard: 40, extraFiles: goodExtra });
  assert.equal(ratchet(repo, xml).status, 0);
  write(repo, `${PLUGIN}/classes/a.php`, SOURCE.replace('return 1;', 'return 11;'));
  const r = ratchet(repo, xml);
  assert.equal(r.status, 1);
  assert.match(r.out, /classes\/a\.php:0: ratchet-file-coverage: .*50\.0%/);
});

test('ratchet: geaenderte Methode mit CRAP ueber 8 ist rot, ungeaenderte Methode derselben Datei nicht', () => {
  const repo = makeRepo();
  const xml = clover({ crapHard: 40, extraFiles: goodExtra });
  write(repo, `${PLUGIN}/classes/a.php`, SOURCE.replace('return 3;', 'return 33;'));
  const r = ratchet(repo, xml);
  assert.equal(r.status, 1);
  assert.match(r.out, /classes\/a\.php:12: ratchet-method-crap: geaenderte Methode hard hat CRAP 40/);
  assert.doesNotMatch(r.out, /ratchet-method-crap: .*easy/);
});

test('ratchet: geaenderte Methode mit CRAP bis 8 ist gruen', () => {
  const repo = makeRepo();
  write(repo, `${PLUGIN}/classes/a.php`, SOURCE.replace('return 3;', 'return 33;'));
  assert.equal(ratchet(repo, clover({ crapHard: 8, extraFiles: goodExtra })).status, 0);
});

test('ratchet: Aenderung nur im Docblock der naechsten Methode trifft nicht die vorherige Methode', () => {
  const repo = makeRepo();
  write(repo, `${PLUGIN}/classes/a.php`, SOURCE.replace('Docblock of next.', 'Docblock of next, geaendert.'));
  assert.equal(ratchet(repo, clover({ crapHard: 40, extraFiles: goodExtra })).status, 0);
});

test('ratchet: neue unversionierte Datei zaehlt komplett als geaendert', () => {
  const repo = makeRepo();
  write(repo, `${PLUGIN}/classes/c.php`, SOURCE);
  const r = ratchet(repo, clover({ extraFiles: [...goodExtra, { name: 'classes/c.php', st: 10, cov: 0 }] }));
  assert.equal(r.status, 1);
  assert.match(r.out, /classes\/c\.php:0: ratchet-file-coverage: .*0\.0%/);
});

test('ratchet: ausgeschlossene Datei zaehlt weder im Nenner noch als geaenderte Datei', () => {
  const repo = makeRepo();
  write(repo, `${PLUGIN}/tests/a_test.php`, "<?php\n#[CoversClass(a::class)]\nclass a_test {}\n");
  write(repo, `${PLUGIN}/lang/en/local_coursepilot.php`, "<?php\n$string['a'] = 'c';\n");
  const xml = clover({ extraFiles: [...goodExtra, { name: 'lang/en/local_coursepilot.php', st: 500, cov: 0 }] });
  const r = ratchet(repo, xml);
  assert.equal(r.status, 0, r.out);
  assert.match(r.out, /coverage=90.00%/);
});

test('ratchet: wachsende PHPStan-Baseline ist rot, schrumpfende gruen', () => {
  const repo = makeRepo();
  write(repo, 'scripts/gate/phpstan/phpstan-baseline.neon', PHPSTAN(4));
  const grown = run(repo, ['ratchet']);
  assert.equal(grown.status, 1);
  assert.match(grown.out, /ratchet-phpstan-grown: .*missingType\.iterableValue: 4 statt hoechstens 3/);
  write(repo, 'scripts/gate/phpstan/phpstan-baseline.neon', PHPSTAN(2));
  assert.equal(run(repo, ['ratchet']).status, 0);
});

test('ratchet: neues Paar in der deptrac-Baseline ist rot, Entfernen gruen', () => {
  const repo = makeRepo();
  write(repo, 'scripts/gate/deptrac/deptrac-baseline.yaml', DEPTRAC([['a\\x', 'a\\y'], ['a\\x', 'a\\z']]));
  const grown = run(repo, ['ratchet']);
  assert.equal(grown.status, 1);
  assert.match(grown.out, /ratchet-deptrac-grown: a\\x -> a\\z/);
  write(repo, 'scripts/gate/deptrac/deptrac-baseline.yaml', DEPTRAC([]));
  assert.equal(run(repo, ['ratchet']).status, 0);
});

test('ratchet: gesenkte Coverage-Baseline gegenueber dem Ziel-Stand ist rot', () => {
  const repo = makeRepo();
  write(repo, 'scripts/gate/baseline.json', JSON.stringify({ ...BASELINE, coverage: { covered: 70, statements: 100 } }));
  const r = run(repo, ['ratchet']);
  assert.equal(r.status, 1);
  assert.match(r.out, /ratchet-baseline-lowered: Baseline 70\.00% .* Ziel-Branch 80\.00%/);
});

test('ratchet: fehlende Baseline, fehlender oder leerer Clover sind rot, nie gruen', () => {
  const repo = makeRepo();
  assert.equal(run(repo, ['ratchet', path.join(repo, 'gibt-es-nicht.xml')]).status, 1);
  fs.writeFileSync(path.join(repo, 'leer.xml'), '');
  const empty = run(repo, ['ratchet', path.join(repo, 'leer.xml')]);
  assert.equal(empty.status, 1);
  assert.match(empty.out, /gate-error/);
  fs.rmSync(path.join(repo, 'scripts/gate/baseline.json'));
  const missing = run(repo, ['ratchet']);
  assert.equal(missing.status, 1);
  assert.match(missing.out, /gate-error: Baseline fehlt/);
});

test('ratchet: reine Umbenennung (versioniert oder unversioniert) ist weder geaendert noch waechst die PHPStan-Baseline', () => {
  const renamedBaseline = PHPSTAN(3).replace('classes/a.php', 'classes/z.php');
  for (const stage of [true, false]) {
    const repo = makeRepo();
    fs.renameSync(path.join(repo, PLUGIN, 'classes/a.php'), path.join(repo, PLUGIN, 'classes/z.php'));
    write(repo, 'scripts/gate/phpstan/phpstan-baseline.neon', renamedBaseline);
    if (stage) {
      git(repo, 'add', '-A');
    }
    const methods = '<line num="8" type="method" name="easy" complexity="1" crap="30" count="0"/>';
    const xml = clover({ withA: false, extraFiles: [{ name: 'classes/z.php', st: 10, cov: 1, methods }, ...goodExtra] });
    const r = ratchet(repo, xml);
    assert.equal(r.status, 0, `stage=${stage}: ${r.out}`);
  }
});

test('ratchet: umbenannte Datei mit Aenderung prueft nur die geaenderten Methoden', () => {
  const repo = makeRepo();
  fs.renameSync(path.join(repo, PLUGIN, 'classes/a.php'), path.join(repo, PLUGIN, 'classes/z.php'));
  write(repo, `${PLUGIN}/classes/z.php`, SOURCE.replace('return 3;', 'return 33;'));
  git(repo, 'add', '-A');
  write(repo, 'scripts/gate/phpstan/phpstan-baseline.neon', PHPSTAN(3).replace('classes/a.php', 'classes/z.php'));
  const z = `<file name="${CONTAINER_PLUGIN}/classes/z.php"><line num="8" type="method" name="easy" complexity="1" crap="30" count="0"/><line num="12" type="method" name="hard" complexity="2" crap="30" count="0"/><metrics statements="10" coveredstatements="10"/></file>`;
  const full = `<?xml version="1.0"?><coverage><project>${z}<file name="${CONTAINER_PLUGIN}/classes/b.php"><metrics statements="90" coveredstatements="81"/></file><metrics statements="100" coveredstatements="91"/></project></coverage>`;
  const r = ratchet(repo, full);
  assert.equal(r.status, 1);
  assert.match(r.out, /classes\/z\.php:12: ratchet-method-crap: geaenderte Methode hard/);
  assert.doesNotMatch(r.out, /ratchet-method-crap: .*easy/);
  assert.doesNotMatch(r.out, /ratchet-phpstan-grown/);
});

test('ratchet: geloeschte Datei erzeugt keinen Verstoss, auch nicht mit altem Eintrag im Clover', () => {
  const repo = makeRepo();
  fs.rmSync(path.join(repo, PLUGIN, 'classes/a.php'));
  const other = [{ name: 'classes/b.php', st: 90, cov: 81, methods: '<line num="3" type="method" name="m" complexity="1" crap="1" count="1"/>' }];
  assert.equal(ratchet(repo, clover({ withA: false, extraFiles: other })).status, 0);
  assert.equal(ratchet(repo, clover({ aCovered: 0, crapHard: 99, extraFiles: goodExtra })).status, 0);
});

test('baseline: hebt an, verweigert Senken', () => {
  const repo = makeRepo();
  const file = path.join(repo, 'clover.xml');
  fs.writeFileSync(file, clover({ extraFiles: goodExtra }));
  assert.equal(run(repo, ['baseline', file]).status, 0);
  assert.deepEqual(JSON.parse(fs.readFileSync(path.join(repo, 'scripts/gate/baseline.json'), 'utf8')).coverage, { covered: 90, statements: 100 });
  fs.writeFileSync(file, clover({ aCovered: 1, extraFiles: goodExtra }));
  const lower = run(repo, ['baseline', file]);
  assert.equal(lower.status, 1);
  assert.match(lower.out, /Baseline wird nie gesenkt/);
});

test('report: Ausschlussliste der Baseline nimmt Sprachdateien aus Nenner und Befunden', () => {
  const repo = makeRepo();
  const file = path.join(repo, 'clover.xml');
  fs.writeFileSync(file, clover({ extraFiles: [{ name: 'lang/en/local_coursepilot.php', st: 500, cov: 0 }] }));
  const r = run(repo, ['report', file]);
  assert.equal(r.status, 0);
  assert.match(r.out, /coverage=90% lines=9\/10 files=1/);
  assert.doesNotMatch(r.out, /lang\//);
});

test('edit: Datei ausserhalb von Plugin, Tests und Gate loest nichts aus', () => {
  const repo = makeRepo();
  const r = run(repo, ['edit'], {}, JSON.stringify({ tool_input: { file_path: path.join(repo, 'README.md') } }));
  assert.equal(r.status, 0);
  assert.equal(r.out, '');
});

test('edit: unlesbare Hook-Eingabe bricht nicht ab', () => {
  assert.equal(run(makeRepo(), ['edit'], {}, 'kein json').status, 0);
});

test('edit: Plugin-Datei startet dasselbe Gate wie pre-commit und meldet Rot mit Exitcode 2 auf stderr', () => {
  const repo = makeRepo();
  // Ohne Container und ohne npm-Skripte im Testrepo ist fast rot; es zaehlt, dass der Hook es ausloest.
  const r = spawnSync('node', [GATE, 'edit'], {
    cwd: repo,
    encoding: 'utf8',
    input: JSON.stringify({ tool_input: { file_path: `${PLUGIN}/classes/a.php` } }),
    env: { ...process.env, GATE_REPO: repo, GATE_BASE_REF: 'HEAD', GATE_CONTAINER: 'gibt-es-nicht', GATE_FAILURE_LOG: path.join(repo, 'f.log') },
  });
  assert.equal(r.status, 2);
  assert.match(r.stderr, /gate-error: Gate-Container gibt-es-nicht laeuft nicht/);
  assert.equal(r.stdout, '');
});

test('edit: Codex-apply_patch mit Plugin-Datei loest das Gate aus', () => {
  const repo = makeRepo();
  const r = spawnSync('node', [GATE, 'edit'], {
    cwd: repo,
    encoding: 'utf8',
    input: JSON.stringify({ tool_input: { command: `*** Begin Patch\n*** Update File: ${PLUGIN}/classes/a.php\n*** End Patch` } }),
    env: { ...process.env, GATE_REPO: repo, GATE_BASE_REF: 'HEAD', GATE_CONTAINER: 'gibt-es-nicht', GATE_FAILURE_LOG: path.join(repo, 'f.log') },
  });
  assert.equal(r.status, 2);
});

/** Repo mit den echten Hook-Skripten und einem Gate-Stub, der mit `exitcode` endet. */
function hookRepo(exitcode) {
  const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-hooks-'));
  git(repo, 'init', '-q');
  fs.cpSync(path.join(ROOT, 'scripts', 'githooks'), path.join(repo, 'scripts', 'githooks'), { recursive: true });
  write(repo, 'scripts/gate/gate.js', `process.stdout.write('stub ' + process.argv[2] + '\\n'); process.exit(${exitcode});\n`);
  git(repo, 'config', 'core.hooksPath', 'scripts/githooks');
  write(repo, 'a.txt', 'a');
  git(repo, 'add', '-A');
  return repo;
}

test('pre-commit: Gate rot blockiert den Commit, gruen laesst ihn durch', () => {
  const red = hookRepo(1);
  const blocked = spawnSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'x'], { cwd: red, encoding: 'utf8' });
  assert.notEqual(blocked.status, 0);
  assert.match(`${blocked.stdout}${blocked.stderr}`, /stub fast/);
  const green = hookRepo(0);
  const ok = spawnSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-qm', 'x'], { cwd: green, encoding: 'utf8' });
  assert.equal(ok.status, 0, ok.stderr);
});

/** hookRepo mit einem Commit und lokalem Bare-Remote. */
function pushRepo(exitcode) {
  const repo = hookRepo(exitcode);
  const remote = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-remote-'));
  git(remote, 'init', '-q', '--bare');
  git(repo, 'commit', '--no-verify', '-qm', 'x');
  git(repo, 'remote', 'add', 'origin', remote);
  return repo;
}

const push = (repo, ...args) => {
  const r = spawnSync('git', ['push', 'origin', ...args], { cwd: repo, encoding: 'utf8' });
  return { status: r.status, out: `${r.stdout}${r.stderr}` };
};

test('pre-push: Gate full rot blockiert den Push, gruen laesst ihn durch', () => {
  const red = pushRepo(1);
  const blocked = push(red, 'HEAD:refs/heads/work');
  assert.notEqual(blocked.status, 0);
  assert.match(blocked.out, /stub full/);
  const green = pushRepo(0);
  assert.equal(push(green, 'HEAD:refs/heads/work').status, 0);
});

test('pre-push: Loeschen eines Branches und Tags brauchen kein Gate', () => {
  const repo = pushRepo(1);
  git(repo, 'push', '--no-verify', 'origin', 'HEAD:refs/heads/work');
  assert.equal(push(repo, '--delete', 'work').status, 0);
  git(repo, 'tag', 'v1');
  assert.equal(push(repo, 'v1').status, 0);
});

test('pre-push: mehrere Refs, auch Loeschen plus Neuanlage, laufen ueber ein Gate', () => {
  const repo = pushRepo(1);
  git(repo, 'push', '--no-verify', 'origin', 'HEAD:refs/heads/old');
  const two = push(repo, 'HEAD:refs/heads/a', 'HEAD:refs/heads/b');
  assert.notEqual(two.status, 0);
  assert.equal(two.out.match(/stub full/g).length, 1);
  const mixed = push(repo, ':refs/heads/old', 'HEAD:refs/heads/new');
  assert.notEqual(mixed.status, 0);
  assert.match(mixed.out, /stub full/);
});

test('pre-push: gepushter Stand muss der geprueften sein (Ref nicht HEAD, geaenderter Arbeitsbaum)', () => {
  const repo = pushRepo(0);
  git(repo, 'branch', 'other');
  write(repo, 'b.txt', 'b');
  git(repo, 'add', '-A');
  git(repo, 'commit', '--no-verify', '-qm', 'y');
  const other = push(repo, 'other:refs/heads/other');
  assert.notEqual(other.status, 0);
  assert.match(other.out, /zeigt nicht auf HEAD/);
  write(repo, 'a.txt', 'geaendert');
  const dirty = push(repo, 'HEAD:refs/heads/work');
  assert.notEqual(dirty.status, 0);
  assert.match(dirty.out, /versionierte Dateien sind geaendert/);
  assert.doesNotMatch(dirty.out, /stub full/);
});

test('Aktivierung und Edit-Hooks: ein npm-Skript, Claude und Codex rufen dasselbe Gate, kein php -l', () => {
  const pkg = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
  assert.equal(pkg.scripts['hooks:install'], 'git config core.hooksPath scripts/githooks');
  assert.equal(Object.keys(pkg.devDependencies || {}).some(d => /husky/i.test(d)), false);
  const claude = fs.readFileSync(path.join(ROOT, '.claude', 'settings.json'), 'utf8');
  const codex = fs.readFileSync(path.join(ROOT, '.codex', 'hooks.json'), 'utf8');
  assert.equal(claude, codex);
  assert.match(claude, /scripts\/gate\/gate\.js\\?" edit/);
  assert.doesNotMatch(claude, /php -l/);
  for (const hook of ['pre-commit', 'pre-push']) {
    assert.ok(fs.statSync(path.join(ROOT, 'scripts', 'githooks', hook)).mode & 0o100, `${hook} ausfuehrbar`);
  }
});
