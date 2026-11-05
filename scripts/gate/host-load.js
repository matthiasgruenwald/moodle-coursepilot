/** Host capacity guard: a full gate run must not start on a host that foreign test runs already exhaust. */
'use strict';

const fs = require('node:fs');
const { spawnSync } = require('node:child_process');

const DEFAULT_MIN_MEM_MB = 4096;
const OWN_CONTAINER_PREFIX = 'kurspilot-gate-';
const TEST_COMMAND = /phpunit|infection/i;

function availableMemoryMb(meminfoPath = '/proc/meminfo') {
  const match = /^MemAvailable:\s+(\d+) kB$/m.exec(fs.readFileSync(meminfoPath, 'utf8'));
  return match ? Math.floor(Number(match[1]) / 1024) : null;
}

/** Running containers outside the gate stack whose command is a PHPUnit or Infection run. */
function foreignTestContainers(psOutput) {
  return psOutput.split('\n').filter(Boolean)
    .map(line => line.split('\t'))
    .filter(([name, command]) => !name.startsWith(OWN_CONTAINER_PREFIX) && TEST_COMMAND.test(command || ''))
    .map(([name]) => name);
}

function listContainers() {
  const ps = spawnSync('docker', ['ps', '--no-trunc', '--format', '{{.Names}}\t{{.Command}}'], { encoding: 'utf8' });
  return ps.status === 0 ? ps.stdout : '';
}

/** Problems that make a full run unsafe; empty when the host has room. */
function hostProblems(env = process.env, io = {}) {
  const minMb = env.GATE_MIN_MEM_MB === undefined ? DEFAULT_MIN_MEM_MB : Number(env.GATE_MIN_MEM_MB);
  const problems = [];
  const available = (io.availableMemoryMb || availableMemoryMb)(env.GATE_MEMINFO || undefined);
  if (available !== null && available < minMb) {
    problems.push(`only ${available} MB memory available, need ${minMb} MB (GATE_MIN_MEM_MB)`);
  }
  const foreign = foreignTestContainers((io.listContainers || listContainers)());
  if (foreign.length > 0) {
    problems.push(`foreign test containers running: ${foreign.join(', ')}`);
  }
  return problems;
}

function assertHostCapacity(env = process.env, io = {}) {
  const problems = hostProblems(env, io);
  if (problems.length > 0) {
    throw new Error(`host overloaded, full gate not started: ${problems.join('; ')}`);
  }
}

module.exports = { assertHostCapacity, hostProblems, foreignTestContainers, availableMemoryMb, DEFAULT_MIN_MEM_MB };
