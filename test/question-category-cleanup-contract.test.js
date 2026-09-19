const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawn } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const SERVER_PATH = path.join(__dirname, '..', 'moodle-mcp.js');
const SERVICES_PATH = path.join(
  __dirname,
  '..',
  'legacy',
  'local_coursepilot',
  'db',
  'services.php'
);

function startServer({ profile } = {}) {
  const child = spawn('node', [SERVER_PATH], {
    env: {
      ...process.env,
      MOODLE_URL: 'https://example.test/moodle',
      MOODLE_TOKEN: 'dummy-token',
      ...(profile ? { MOODLE_MCP_PROFILE: profile } : {}),
    },
  });

  let stdoutBuffer = '';
  const pending = [];

  child.stdout.on('data', chunk => {
    stdoutBuffer += chunk;
    const lines = stdoutBuffer.split('\n');
    stdoutBuffer = lines.pop();
    for (const line of lines) {
      if (!line.trim()) continue;
      const next = pending.shift();
      if (next) next(JSON.parse(line));
    }
  });

  return {
    request(message) {
      child.stdin.write(`${JSON.stringify(message)}\n`);
      return new Promise(resolve => pending.push(resolve));
    },
    stop() {
      child.stdin.end();
    },
  };
}

async function listToolNames(server) {
  const response = await server.request({ jsonrpc: '2.0', id: 1, method: 'tools/list' });
  return response.result.tools.map(tool => tool.name);
}

function functionBlock(servicesSource, functionName) {
  const escapedName = functionName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const pattern = new RegExp(`'${escapedName}'\\s*=>\\s*\\[(.*?)\\n\\s*\\],`, 's');
  const match = servicesSource.match(pattern);
  assert.ok(match, `function block for ${functionName} exists`);
  return match[1];
}

test('question category cleanup tool is write-only in full profile and never exposes delete tools', async () => {
  const fullServer = startServer();
  const readonlyServer = startServer({ profile: 'readonly' });

  try {
    const [fullToolNames, readonlyToolNames] = await Promise.all([
      listToolNames(fullServer),
      listToolNames(readonlyServer),
    ]);

    assert.ok(fullToolNames.includes('moodle_update_question_category'));
    assert.equal(readonlyToolNames.includes('moodle_update_question_category'), false);

    assert.equal(fullToolNames.includes('moodle_delete_question_category'), false);
    assert.equal(fullToolNames.includes('moodle_delete_question'), false);
    assert.equal(readonlyToolNames.includes('moodle_delete_question_category'), false);
    assert.equal(readonlyToolNames.includes('moodle_delete_question'), false);
  } finally {
    fullServer.stop();
    readonlyServer.stop();
  }
});

test('question category cleanup webservice is registered with trainer category capability and no delete registration', () => {
  const servicesSource = fs.readFileSync(SERVICES_PATH, 'utf8');
  const updateBlock = functionBlock(servicesSource, 'local_coursepilot_update_question_category');

  assert.match(updateBlock, /'classname'\s*=>\s*'local_coursepilot\\external\\update_question_category'/);
  assert.match(updateBlock, /'type'\s*=>\s*'write'/);
  assert.match(updateBlock, /'capabilities'\s*=>\s*'moodle\/question:managecategory'/);

  assert.doesNotMatch(servicesSource, /local_coursepilot_delete_question_category/);
  assert.doesNotMatch(servicesSource, /local_coursepilot_delete_question/);
});
