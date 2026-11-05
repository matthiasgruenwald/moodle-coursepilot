#!/usr/bin/env node
/** Real Infection acceptance at the Gate CLI, using only the isolated QA mount. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const gateDir = process.env.GATE_DIR || '/opt/kurspilot-gate';
const reports = path.join(gateDir, 'reports');
const evidence = path.join(reports, 'mutation-proof');
const repo = fs.mkdtempSync(path.join(os.tmpdir(), 'coursepilot-mutation-proof-'));
const plugin = 'Plugin/src/local_coursepilot';
// Read the existing history against a disposable source copy; never create a branch or commit.
const command = (cmd, args, env = {}) => spawnSync(cmd, args, { cwd: repo, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024,
  env: { ...process.env, GIT_DIR: path.join(root, '.git'), GIT_WORK_TREE: repo, ...env } });
const write = (file, text) => {
  fs.mkdirSync(path.dirname(path.join(repo, file)), { recursive: true });
  fs.writeFileSync(path.join(repo, file), text);
};
const testSource = assertion => `<?php
namespace local_coursepilot;
use PHPUnit\\Framework\\Attributes\\CoversClass;
#[CoversClass(mutation_gate_fixture::class)]
final class mutation_gate_fixture_test extends \\basic_testcase {
    public function test_value(): void {
        ${assertion}
    }
}
`;

function run(name) {
  const r = command('node', [path.join(root, 'scripts/gate/gate.js'), 'mutation'], {
    GATE_REPO: repo, GATE_BASE_REF: 'HEAD', GATE_FAILURE_LOG: path.join(evidence, 'failures.log'),
  });
  fs.writeFileSync(path.join(evidence, `${name}.log`), r.stdout + r.stderr);
  fs.writeFileSync(path.join(evidence, `${name}.exit`), `${r.status}\n`);
  const out = path.join(evidence, name);
  fs.mkdirSync(out, { recursive: true });
  fs.copyFileSync(path.join(repo, 'scripts/gate/mutation-ignore.json'), path.join(out, 'mutation-ignore.json'));
  if (name !== 'unreasoned-ignore') {
    for (const file of ['infection.json', 'infection.log', 'infection.exit', 'infection-config.json',
      'infection-timer.json', 'infection-sources.json', 'infection.diff', 'infection-files.txt', 'infection-tmp']) {
      fs.cpSync(path.join(reports, file), path.join(out, file), { recursive: true });
    }
    const environment = path.join(reports, 'infection-environment.json');
    if (fs.existsSync(environment)) fs.copyFileSync(environment, path.join(out, 'infection-environment.json'));
  }
  return r;
}

try {
  fs.mkdirSync(evidence, { recursive: true });
  fs.cpSync(path.join(root, plugin), path.join(repo, plugin), { recursive: true });
  fs.cpSync(path.join(root, 'scripts/gate'), path.join(repo, 'scripts/gate'), { recursive: true });
  fs.copyFileSync(path.join(root, 'composer.lock'), path.join(repo, 'composer.lock'));
  write('.gitignore', fs.readFileSync(path.join(root, '.gitignore'), 'utf8') + '\nPlugin/src/local_coursepilot/phpunit.xml\n');
  const source = `${plugin}/classes/mutation_gate_fixture.php`;
  const tests = `${plugin}/tests/mutation_gate_fixture_test.php`;
  write(source, `<?php
namespace local_coursepilot;
final class mutation_gate_fixture {
    public static function value(int $input): int {
        return $input + 1;
    }
}
`);
  write(tests, testSource('$this->assertGreaterThan(3, mutation_gate_fixture::value(3));'));
  const red = run('survivor');
  assert.equal(red.status, 1, red.stdout + red.stderr);
  assert.match(red.stdout, /mutation_gate_fixture.php:\d+: mutation-survived:/);
  const real = JSON.parse(fs.readFileSync(path.join(evidence, 'survivor/infection.json'), 'utf8'));
  assert.ok(real.stats.escapedCount > 0, 'A real covered mutant must escape');
  assert.equal(real.stats.errorCount, 0);
  assert.equal(real.stats.notCoveredCount, 0);
  // This is a filesystem-fixture check of source discovery, not another Infection run.
  const discovery = spawnSync('docker', ['exec', '-i', process.env.GATE_CONTAINER || 'kurspilot-gate-webserver-1', 'php', '/dev/stdin'], {
    encoding: 'utf8', input: fs.readFileSync(path.join(root, 'scripts/gate/infection-environment-proof.php'), 'utf8'),
  });
  fs.writeFileSync(path.join(evidence, 'environment-discovery.log'), discovery.stdout + discovery.stderr);
  fs.writeFileSync(path.join(evidence, 'environment-discovery.exit'), `${discovery.status}\n`);
  assert.equal(discovery.status, 0, discovery.stdout + discovery.stderr);
  const { identity } = require('./mutation');
  write('scripts/gate/mutation-ignore.json', JSON.stringify([identity(real.escaped[0].mutator)]));
  const invalid = run('unreasoned-ignore');
  assert.equal(invalid.status, 1, invalid.stdout + invalid.stderr);
  assert.match(invalid.stdout, /mutation-ignore:.*reason/);
  write('scripts/gate/mutation-ignore.json', '[]');
  write(tests, testSource('$this->assertSame(4, mutation_gate_fixture::value(3));'));
  const green = run('strong-assertion');
  assert.equal(green.status, 0, green.stdout + green.stderr);
  const killed = JSON.parse(fs.readFileSync(path.join(evidence, 'strong-assertion/infection.json'), 'utf8'));
  assert.ok(killed.stats.killedCount > 0, 'Stronger assertion must kill real mutants');
  assert.equal(killed.stats.escapedCount, 0);

  // Exercise the actual category-suffix mutation without changing production semantics.
  fs.rmSync(path.join(repo, source));
  fs.rmSync(path.join(repo, tests));
  const importFile = `${plugin}/classes/question_import.php`;
  const original = fs.readFileSync(path.join(root, importFile), 'utf8');
  const assignment = "$form->category = $category->id . ',' . $context->id;";
  assert.ok(original.includes(assignment), 'The real category assignment must be present');
  write(importFile, original.replace(assignment, assignment.replace('$context', ' $context')));
  const conditional = JSON.parse(fs.readFileSync(path.join(root, 'scripts/gate/mutation-ignore.json'), 'utf8'));
  const scoped = conditional.find(e => e.qtypeSourceHash !== undefined);
  assert.ok(scoped, 'A source-bound production equivalence must be present');
  write('scripts/gate/mutation-ignore.json', JSON.stringify(conditional));
  const matched = run('qtype-matching');
  assert.equal(matched.status, 0, matched.stdout + matched.stderr);
  const actual = JSON.parse(fs.readFileSync(path.join(evidence, 'qtype-matching/infection.json'), 'utf8'));
  assert.ok(actual.escaped.some(row => identity(row.mutator).hash === scoped.hash), 'The real suffix mutant must survive');
  scoped.qtypeSourceHash = '0'.repeat(64);
  write('scripts/gate/mutation-ignore.json', JSON.stringify(conditional));
  const mismatched = run('qtype-mismatch');
  assert.equal(mismatched.status, 1, mismatched.stdout + mismatched.stderr);
  assert.ok(mismatched.stdout.includes(`mutation-survived: ${scoped.mutator} hash=${scoped.hash}`));
  fs.writeFileSync(path.join(evidence, 'proof.json'), JSON.stringify({ survivorExit: red.status, unreasonedIgnoreExit: invalid.status, strongAssertionExit: green.status, qtypeMatchingExit: matched.status, qtypeMismatchExit: mismatched.status, escaped: real.stats.escapedCount, killed: killed.stats.killedCount }, null, 2));
  console.log('summary: real mutation proof survivor=red unreasoned-ignore=red strong-assertion=green');
} catch (error) {
  console.error(error);
  process.exitCode = 1;
} finally {
  const restore = spawnSync('bash', [path.join(root, 'scripts/gate/sync-plugin.sh')], { encoding: 'utf8', env: { ...process.env, GATE_REPO: root } });
  fs.writeFileSync(path.join(evidence, 'restore.log'), restore.stdout + restore.stderr);
  fs.writeFileSync(path.join(evidence, 'restore.exit'), `${restore.status}\n`);
  if (restore.status !== 0) process.exitCode = 1;
  fs.rmSync(repo, { recursive: true, force: true });
}
