'use strict';

const { spawn } = require('node:child_process');
const container = process.env.KURSPILOT_SPIKE_CONTAINER || 'moodle-kurspilot-spike-webserver-1';
const username = process.env.KURSPILOT_SPIKE_USERNAME || 'teacher_edit';

async function runPhp(source, args = []) {
  const result = await new Promise((resolve, reject) => {
    const child = spawn('docker', ['exec', '-i', container, 'php', '--', username, ...args]);
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', chunk => { stdout += chunk; });
    child.stderr.on('data', chunk => { stderr += chunk; });
    child.on('error', reject);
    child.on('close', code => {
      if (code === 0) {
        resolve(stdout);
      } else {
        reject(new Error(`Spike fixture failed (${code}): ${stderr}`));
      }
    });
    child.stdin.end(source);
  });
  return JSON.parse(result);
}

const SETUP = String.raw`<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
$user = $DB->get_record('user', ['username' => $argv[1], 'deleted' => 0], '*', MUST_EXIST);
\core\session\manager::set_user($user);
$stamp = $argv[2];
$original = \local_coursepilot\storage_anchor::read_raw_pointer();
$instance = null;
foreach (\local_coursepilot\location_selection::own_instances() as $candidate) {
    if (!$candidate['selectable']) { continue; }
    try { \local_coursepilot\location_selection::browse($candidate['id'], ''); $instance = $candidate['id']; break; } catch (\Throwable $e) { }
}
if ($instance === null) { throw new \moodle_exception('webdavinstancemissing', 'local_coursepilot'); }
$resolved = \local_coursepilot\webdav\webdav_instance::resolve_owned($instance);
$filled = 'E2E-Ortswahl-' . $stamp;
$resolved->client()->mkcol_chain($resolved->directory_url(''), [$filled]);
$resolved->client()->put_new($resolved->file_url($filled . '/vorhanden.md'), 'E2E');
$typeid = $DB->get_field('repository', 'id', ['type' => 'webdav'], MUST_EXIST);
$badid = $DB->insert_record('repository_instances', (object) ['name' => 'E2E-Speicherausfall-' . $stamp, 'typeid' => $typeid, 'userid' => 0, 'contextid' => \context_user::instance($user->id)->id, 'timecreated' => time(), 'timemodified' => time(), 'readonly' => 0]);
foreach (['webdav_type' => '1', 'webdav_server' => '127.0.0.1', 'webdav_port' => '9', 'webdav_path' => '', 'webdav_user' => '', 'webdav_password' => '', 'webdav_auth' => 'basic'] as $name => $value) {
    $DB->insert_record('repository_instance_config', (object) ['instanceid' => $badid, 'name' => $name, 'value' => $value]);
}
\local_coursepilot\storage_anchor::write_pointer_document(['kontextbereich' => ['ort' => 'moodle', 'pfad' => 'coursepilot'], 'materialbestand' => ['ort' => 'moodle', 'pfad' => 'coursepilot-material'], 'ortsverlauf' => []]);
echo json_encode(['instanceid' => $instance, 'filled' => $filled, 'badid' => $badid, 'original' => $original === null ? null : base64_encode(json_encode($original))]);`;

const CLEANUP = String.raw`<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
$user = $DB->get_record('user', ['username' => $argv[1], 'deleted' => 0], '*', MUST_EXIST);
\core\session\manager::set_user($user);
[$instanceid, $filled, $badid, $original] = array_slice($argv, 2);
try { $resolved = \local_coursepilot\webdav\webdav_instance::resolve_owned((int) $instanceid); $resolved->client()->delete($resolved->file_url($filled . '/vorhanden.md')); $resolved->client()->delete($resolved->directory_url($filled)); } catch (\Throwable $e) { }
$DB->delete_records('repository_instance_config', ['instanceid' => (int) $badid]);
$DB->delete_records('repository_instances', ['id' => (int) $badid]);
if ($original !== 'null') { \local_coursepilot\storage_anchor::write_pointer_document(json_decode(base64_decode($original), true, 512, JSON_THROW_ON_ERROR)); } else { $file = get_file_storage()->get_file(\context_user::instance($user->id)->id, 'user', 'private', 0, \local_coursepilot\storage_anchor::anchor_root(), \local_coursepilot\storage_anchor::POINTER_FILENAME); if ($file) { $file->delete(); } }
echo json_encode(['ok' => true]);`;

async function setupLocationFixture(stamp) {
  return runPhp(SETUP, [stamp]);
}

async function cleanupLocationFixture(fixture) {
  await runPhp(CLEANUP, [String(fixture.instanceid), fixture.filled, String(fixture.badid), fixture.original || 'null']);
}

module.exports = { setupLocationFixture, cleanupLocationFixture };
