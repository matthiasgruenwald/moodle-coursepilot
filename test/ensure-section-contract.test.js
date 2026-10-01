const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');

// Issue #89: moodle_ensure_section ist ein Core-Tool; die Tool-Definition
// liegt in lib/core-tools.js, geteilt von moodle-mcp.js und
// moodle-mcp-core.js (ADR 0007).
const MCP_PATH = path.join(__dirname, '..', 'lib', 'core-tools.js');
const SERVICES_PATH = path.join(
  __dirname,
  '..',
  'legacy',
  'local_coursepilot',
  'db',
  'services.php'
);

test('moodle_ensure_section is exposed by MCP and registered in Moodle services', () => {
  const mcpSource = fs.readFileSync(MCP_PATH, 'utf8');
  const servicesSource = fs.readFileSync(SERVICES_PATH, 'utf8');

  assert.match(mcpSource, /name:\s*"moodle_ensure_section"/);
  assert.match(mcpSource, /local_coursepilot_ensure_section/);
  assert.match(servicesSource, /'local_coursepilot_ensure_section'\s*=>/);
  assert.match(servicesSource, /'local_coursepilot_ensure_section'/);
});
