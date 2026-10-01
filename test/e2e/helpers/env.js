'use strict';

const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..', '..', '..');
const profile = process.env.KURSPILOT_E2E_PROFILE || '';
const ENV_PATH = path.join(ROOT, profile === 'spike' ? '.env.e2e.spike' : '.env.e2e');

function loadEnvFile() {
  if (!fs.existsSync(ENV_PATH)) return {};
  const lines = fs.readFileSync(ENV_PATH, 'utf8').split('\n');
  const vars = {};
  for (const line of lines) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq === -1) continue;
    vars[trimmed.slice(0, eq)] = trimmed.slice(eq + 1);
  }
  return vars;
}

const env = loadEnvFile();

const config = {
  moodleUrl: process.env.MOODLE_URL || env.MOODLE_URL || '',
  moodleToken: process.env.MOODLE_TOKEN || env.MOODLE_TOKEN || '',
  courseId: Number(process.env.MOODLE_TEST_COURSEID || env.MOODLE_TEST_COURSEID || 0),
  username: process.env.MOODLE_USERNAME || env.MOODLE_USERNAME || '',
  password: process.env.MOODLE_PASSWORD || env.MOODLE_PASSWORD || '',
};

const isConfigured = Boolean(config.moodleUrl && config.moodleToken && config.courseId);
const hasBrowserCredentials = Boolean(config.username && config.password);

const isSpikeProfile = profile === 'spike';

module.exports = { config, isConfigured, hasBrowserCredentials, isSpikeProfile, loadEnvFile };
