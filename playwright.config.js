'use strict';

const { defineConfig } = require('@playwright/test');
const { loadEnvFile } = require('./test/e2e/helpers/env');

for (const [key, value] of Object.entries(loadEnvFile())) {
  if (!process.env[key]) process.env[key] = value;
}

module.exports = defineConfig({
  testDir: './test/e2e',
  timeout: 60_000,
  retries: 0,
  use: {
    browserName: 'chromium',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
});
