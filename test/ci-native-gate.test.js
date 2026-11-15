'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');

test('CI cross-DB: push runs every Moodle line on PostgreSQL, a pull request two corners, without coverage', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/native-ci.yml'), 'utf8');
  const phpunit = workflow.split('  phpunit:\n')[1].split('\n  js-native:')[0];
  const include = phpunit.match(/include: \$\{\{ fromJSON\(github\.event_name == 'pull_request' && '(\[.*?\])' \|\| '(\[.*?\])'\) \}\}/);
  assert.ok(include, 'the matrix is chosen by event');
  const legs = list => JSON.parse(list).map(l => `${l['moodle-branch']} ${l.database} ${l['php-version']}`);
  const [pullRequest, everything] = [legs(include[1]), legs(include[2])];
  for (const branch of ['MOODLE_501_STABLE', 'MOODLE_502_STABLE', 'MOODLE_503_STABLE']) {
    assert.ok(everything.includes(`${branch} pgsql 8.4`), `${branch} on PostgreSQL`);
  }
  assert.equal(everything.length, 5);
  // ADR 0033: with quality (5.1, MariaDB, PHP 8.4) a pull request sees each Moodle line, database and PHP version once.
  assert.deepEqual(pullRequest, ['MOODLE_502_STABLE mariadb 8.3', 'MOODLE_503_STABLE pgsql 8.4']);
  assert.ok(pullRequest.every(leg => everything.includes(leg)));
  assert.match(phpunit, /image: postgres:17/);
  assert.match(phpunit, /DB: \$\{\{ matrix.database \}\}/);
  assert.match(phpunit, /MOODLE_BRANCH: \$\{\{ matrix.moodle-branch \}\}/);
  assert.match(phpunit, /coverage: none/);
  assert.match(phpunit, /run: moodle-plugin-ci phpunit\s/);
  assert.doesNotMatch(phpunit, /--filter|--testsuite|--coverage|continue-on-error/);
});

test('CI wiring: full on the minimum version is mandatory with target history and diagnostics', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/native-ci.yml'), 'utf8');
  const quality = workflow.split('  quality:\n')[1].split('\n  phpunit:')[0];
  assert.match(quality, /GATE_MOODLE_REF: origin\/MOODLE_501_STABLE/);
  assert.match(quality, /fetch-depth: 0/);
  assert.match(quality, /format\('origin\/\{0\}', github\.base_ref\)/);
  assert.match(quality, /github\.event\.before/);
  assert.match(quality, /npm run gate -- full/);
  // #715: the main rollout is done; CI never sets a bootstrap base again.
  assert.doesNotMatch(workflow, /GATE_BOOTSTRAP_BASE/);
  // ADR 0033: one part without mutation on a pull request; four advisory mutation parts otherwise.
  assert.match(quality, /part: \$\{\{ fromJSON\(github\.event_name == 'pull_request' && '\[0\]' \|\| '\[0, 1, 2, 3\]'\) \}\}/);
  assert.match(quality, /GATE_MUTATION: \$\{\{ github\.event_name != 'pull_request' && '1' \|\| '0' \}\}/);
  assert.match(quality, /GATE_MUTATION_ADVISORY: '1'/);
  assert.match(quality, /git diff --quiet "\$\(git merge-base HEAD "\$GATE_BASE_REF"\)" HEAD -- scripts\/gate composer\.json composer\.lock/);
  assert.match(quality, /GATE_MUTATION_PART: \$\{\{ matrix\.part \}\}\/4/);
  assert.match(quality, /if: always\(\)[\s\S]*actions\/upload-artifact@v4/);
  assert.match(quality, /name: gate-trend-MOODLE_501_STABLE-/);
  assert.match(quality, /if-no-files-found: error/);
  assert.match(workflow, /needs: \[quality, phpunit, js-native, artifact\]/);
  assert.match(workflow, /NEEDS_JSON: \$\{\{ toJSON\(needs\) \}\}/);
  assert.match(workflow, /run: node scripts\/ci\/required-check\.js/);
  assert.doesNotMatch(workflow, /check-coverage|threshold=80|continue-on-error/);
  assert.equal(fs.existsSync(path.join(__dirname, '../scripts/ci/check-coverage.js')), false);
});

test('CI runtime paths: runner exports keep Gate, core and upload outside the checkout', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/native-ci.yml'), 'utf8');
  const quality = workflow.split('  quality:\n')[1].split('\n  phpunit:')[0];
  const firstStep = quality.split('    steps:\n')[1].split('      - name: ')[1];
  const run = firstStep.split('        run: |\n')[1];
  assert.ok(run, 'the initial step must export runner paths at runtime');
  const script = run.split('\n').map(line => line.replace(/^          /, '')).join('\n');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-ci-paths-'));
  try {
    const envFile = path.join(dir, 'github-env');
    const runnerTemp = '/home/runner/work/_temp';
    const result = spawnSync('/bin/bash', ['-e', '-c', script], {
      encoding: 'utf8', env: { ...process.env, RUNNER_TEMP: runnerTemp, GITHUB_ENV: envFile },
    });
    assert.equal(result.status, 0, result.stdout + result.stderr);
    const exported = Object.fromEntries(fs.readFileSync(envFile, 'utf8').trim().split('\n')
      .map(line => line.split('=')));
    assert.equal(exported.GATE_DIR, `${runnerTemp}/coursepilot-gate`);
    assert.equal(exported.GATE_MOODLE_REPO, `${runnerTemp}/moodle-source`);
    const uploadPath = quality.match(/path: (.+)/)[1].replace('${{ env.GATE_DIR }}', exported.GATE_DIR);
    for (const value of [exported.GATE_DIR, exported.GATE_MOODLE_REPO, uploadPath]) {
      assert.equal(path.posix.isAbsolute(value), true);
      assert.equal(value.split('/').some(part => part === '.' || part === '..'), false,
        `runner and upload paths must contain no relative segments: ${value}`);
      assert.equal(value.startsWith('/home/runner/work/moodle-coursepilot/moodle-coursepilot/'), false,
        'Gate data must stay outside the Git comparison checkout');
    }
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('CI setup shell: a failed setup remains red through tee and retains its diagnostic', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/native-ci.yml'), 'utf8');
  const step = workflow.split('      - name: Isoliertes Moodle-5.1-Gate mit versionierten Werkzeugen einrichten\n')[1];
  const script = step.split('\n      - name: ')[0].split('        run: |\n')[1]
    .split('\n').map(line => line.replace(/^          /, '')).join('\n');
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'gate-ci-setup-'));
  try {
    fs.mkdirSync(path.join(dir, 'reports'));
    fs.mkdirSync(path.join(dir, 'bin'));
    for (const [name, body] of Object.entries({ git: 'exit 0', bash: 'echo "setup denied before Compose"; exit 78' })) {
      fs.writeFileSync(path.join(dir, 'bin', name), `#!/bin/sh\n${body}\n`, { mode: 0o755 });
    }
    const result = spawnSync('/bin/bash', ['-e', '-c', script], {
      encoding: 'utf8', env: { ...process.env, PATH: `${dir}/bin:${process.env.PATH}`, GATE_DIR: dir, GATE_MOODLE_REPO: `${dir}/moodle` },
    });
    assert.equal(result.status, 78, result.stdout + result.stderr);
    assert.match(fs.readFileSync(path.join(dir, 'reports/setup.log'), 'utf8'), /setup denied before Compose/);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
