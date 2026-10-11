'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { spawnSync } = require('node:child_process');

test('CI wiring: full on the minimum version is mandatory with target history and diagnostics', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/native-ci.yml'), 'utf8');
  const quality = workflow.split('  quality:\n')[1].split('\n  phpunit:')[0];
  assert.match(quality, /GATE_MOODLE_REF: origin\/MOODLE_501_STABLE/);
  assert.match(quality, /fetch-depth: 0/);
  assert.match(quality, /format\('origin\/\{0\}', github\.base_ref\)/);
  assert.match(quality, /github\.event\.before/);
  assert.match(quality, /npm run gate -- full/);
  assert.match(quality, /if: always\(\)[\s\S]*actions\/upload-artifact@v4/);
  assert.match(quality, /name: gate-trend-MOODLE_501_STABLE-/);
  assert.match(quality, /if-no-files-found: error/);
  assert.match(workflow, /needs: \[quality, phpunit, js-native, artifact\]/);
  assert.match(workflow, /NEEDS_JSON: \$\{\{ toJSON\(needs\) \}\}/);
  assert.match(workflow, /run: node scripts\/ci\/required-check\.js/);
  assert.doesNotMatch(workflow, /check-coverage|threshold=80|continue-on-error/);
  assert.equal(fs.existsSync(path.join(__dirname, '../scripts/ci/check-coverage.js')), false);
});

test('CI trend upload: the expanded reports path contains no relative path segments', () => {
  const workflow = fs.readFileSync(path.join(__dirname, '../.github/workflows/native-ci.yml'), 'utf8');
  const quality = workflow.split('  quality:\n')[1].split('\n  phpunit:')[0];
  const gateDir = quality.match(/GATE_DIR: (.+)/)[1]
    .replace('${{ github.workspace }}', '/home/runner/work/moodle-coursepilot/moodle-coursepilot')
    .replace('${{ runner.temp }}', '/home/runner/work/_temp');
  const uploadPath = quality.match(/path: (.+)/)[1].replace('${{ env.GATE_DIR }}', gateDir);
  assert.equal(path.posix.isAbsolute(uploadPath), true);
  assert.equal(uploadPath.split('/').some(part => part === '.' || part === '..'), false,
    `upload-artifact rejects relative path segments: ${uploadPath}`);
  assert.equal(uploadPath.startsWith('/home/runner/work/moodle-coursepilot/moodle-coursepilot/'), false,
    'Gate data must stay outside the Git comparison checkout');
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
