'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');

test('setup CLI: Docker tooling preserves host ownership and reaches the named gate stack', () => {
  const repo = path.resolve(__dirname, '..');
  // The real setup rejects /tmp mounts; keep this disposable fixture in the checkout.
  const dir = fs.mkdtempSync(path.join(repo, '.gate-setup-contract-'));
  try {
    fs.mkdirSync(path.join(dir, 'moodle/.git'), { recursive: true });
    fs.mkdirSync(path.join(dir, 'phpunitdata/phpunit'), { recursive: true });
    fs.mkdirSync(path.join(dir, 'bin'));
    fs.writeFileSync(path.join(dir, 'bin/docker'), `#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const args = process.argv.slice(2);
fs.appendFileSync(process.env.GATE_DIR + '/docker-calls.jsonl', JSON.stringify(args) + '\\n');
const write = (file, body = '') => {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, body, { mode: 0o755 });
};
if (args[0] === 'run') {
  const user = args[args.indexOf('--user') + 1];
  if (user !== process.getuid() + ':' + process.getgid() || !args.includes('HOME=/tmp')) {
    console.error('Tooling would leave root-owned bind files inaccessible to the host runner');
    process.exit(78);
  }
  const mount = args[args.indexOf('-v') + 1];
  const host = mount.slice(0, mount.lastIndexOf(':'));
  if (args.includes('composer:2')) {
    fs.mkdirSync(host + '/vendor/moodlehq/moodle-plugin-ci', { recursive: true });
    write(host + '/vendor/bin/phpunit');
    write(host + '/vendor/bin/phpstan');
    write(host + '/vendor/bin/infection');
  } else if (args.includes('npm')) {
    fs.mkdirSync(host + '/node_modules/.bin', { recursive: true });
    write(host + '/lib/node_modules/vnu-jar/build/dist/vnu.jar');
  } else if (args.some(arg => arg.includes('temurin'))) {
    write(host + '/bin/java', '#!/bin/sh\\nexit 0\\n');
  } else {
    write(host + '/bin/node');
  }
}
`);
    fs.chmodSync(path.join(dir, 'bin/docker'), 0o755);
    const result = spawnSync('bash', ['scripts/gate/setup-container.sh'], {
      cwd: repo, encoding: 'utf8', timeout: 30000,
      env: { ...process.env, GATE_DIR: dir, PATH: `${dir}/bin:${process.env.PATH}` },
    });
    assert.equal(result.status, 0, result.stdout + result.stderr);
    assert.equal(fs.readlinkSync(path.join(dir, 'ci/vendor/moodlehq/moodle-plugin-ci/vendor')), '../..');
    const calls = fs.readFileSync(path.join(dir, 'docker-calls.jsonl'), 'utf8').trim().split('\n').map(JSON.parse);
    assert.equal(calls.filter(args => args[0] === 'run').length, 7);
    const start = calls.find(args => args[0] === 'compose' && args.includes('up'));
    assert.ok(start, 'Successful setup must start the gate stack');
    assert.match(fs.readFileSync(start[start.indexOf('-f') + 1], 'utf8'), /^name: kurspilot-gate$/m);
    assert.match(result.stdout, /Gate-Container bereit:/);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
