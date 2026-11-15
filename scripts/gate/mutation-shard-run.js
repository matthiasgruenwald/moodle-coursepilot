#!/usr/bin/env node
/** Runs the given commands concurrently (JSON [{argv, log, done, key}] on stdin); a job that exits 0 leaves its key in the atomic done file; prints the exit codes as JSON. */
'use strict';

const fs = require('node:fs');
const { spawn } = require('node:child_process');

const jobs = JSON.parse(fs.readFileSync(0, 'utf8'));
Promise.all(jobs.map(job => new Promise(resolve => {
  const out = fs.openSync(job.log, 'w');
  const child = spawn(job.argv[0], job.argv.slice(1), { stdio: ['ignore', out, out] });
  child.on('error', () => resolve(127));
  child.on('close', code => {
    if (code === 0) {
      fs.writeFileSync(`${job.done}.tmp`, job.key);
      fs.renameSync(`${job.done}.tmp`, job.done);
    }
    resolve(code === null ? 1 : code);
  });
}))).then(codes => process.stdout.write(JSON.stringify(codes)));
