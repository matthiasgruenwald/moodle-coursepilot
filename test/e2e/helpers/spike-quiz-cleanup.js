'use strict';

const { execFileSync } = require('node:child_process');

/** Remove only the temporary activities created by the quiz-local E2E test. */
function cleanupQuizActivities(cmids, courseid) {
  if (!cmids.length) return;
  const source = String.raw`<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
$courseid = (int) $argv[1];
foreach (array_slice($argv, 2) as $id) {
    $cm = get_coursemodule_from_id('', (int) $id, $courseid, false, MUST_EXIST);
    if (!str_starts_with($cm->name, 'E2E-QuizLocal-')) {
        throw new coding_exception('Refusing to delete an activity outside the E2E fixture.');
    }
    course_delete_module($cm->id);
}
`;
  execFileSync('docker', [
    'exec', '-i', process.env.KURSPILOT_SPIKE_CONTAINER || 'moodle-kurspilot-spike-webserver-1',
    'php', '--', String(courseid), ...cmids.map(String),
  ], { input: source, stdio: ['pipe', 'pipe', 'pipe'] });
}

module.exports = { cleanupQuizActivities };
