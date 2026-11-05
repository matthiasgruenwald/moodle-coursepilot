'use strict';

const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { assertHostCapacity, hostProblems, foreignTestContainers, availableMemoryMb } = require('../scripts/gate/host-load');

const idle = { availableMemoryMb: () => 8000, listContainers: () => 'kurspilot-gate-webserver-1\tapache2-foreground\nqf5-db\tmariadbd\n' };

test('host guard: idle host with enough memory passes', () => {
  assert.deepEqual(hostProblems({}, idle), []);
  assert.doesNotThrow(() => assertHostCapacity({}, idle));
});

test('host guard: low memory refuses the run and names the knob', () => {
  const low = { ...idle, availableMemoryMb: () => 3000 };
  assert.throws(() => assertHostCapacity({}, low), /only 3000 MB memory available, need 4096 MB \(GATE_MIN_MEM_MB\)/);
  assert.doesNotThrow(() => assertHostCapacity({ GATE_MIN_MEM_MB: '2000' }, low));
  assert.throws(() => assertHostCapacity({ GATE_MIN_MEM_MB: '3001' }, low), /need 3001 MB/);
});

test('host guard: memory exactly at the minimum is enough', () => {
  assert.deepEqual(hostProblems({ GATE_MIN_MEM_MB: '8000' }, idle), []);
});

test('host guard: foreign PHPUnit and Infection containers refuse the run, the own gate stack does not', () => {
  const busy = { ...idle, listContainers: () => 'kurspilot-gate-webserver-1\tvendor/bin/phpunit\nqf5-run\tvendor/bin/phpunit --filter x\nqf9\tinfection --threads=1\ndb\tmariadbd\n' };
  assert.deepEqual(foreignTestContainers(busy.listContainers()), ['qf5-run', 'qf9']);
  assert.throws(() => assertHostCapacity({}, busy), /foreign test containers running: qf5-run, qf9/);
});

test('host guard: unreadable docker listing counts as no foreign containers', () => {
  assert.deepEqual(foreignTestContainers(''), []);
});

test('host guard: MemAvailable comes from the meminfo file', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'meminfo-'));
  try {
    const file = path.join(dir, 'meminfo');
    fs.writeFileSync(file, 'MemTotal:       16384000 kB\nMemAvailable:    2097152 kB\n');
    assert.equal(availableMemoryMb(file), 2048);
    fs.writeFileSync(file, 'MemTotal:       16384000 kB\n');
    assert.equal(availableMemoryMb(file), null);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});
