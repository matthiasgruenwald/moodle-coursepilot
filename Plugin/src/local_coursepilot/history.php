<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Course-navigation history page (#397, Spec 0015 §10.6/§10.7, phase 4).
 * Teachers inspect and restore history directly in Moodle without an
 * active chat, like the recycle bin. No Coursepilot approval is needed:
 * they act directly, as in an activity form.
 *
 * Thin I/O shell (#334): {@see \local_coursepilot\history\version_history}
 * prepares data and {@see \local_coursepilot\external\restore_activity_version}
 * performs restores, called directly without webservices because the
 * teacher is logged in. The same capabilities and guards apply as in chat
 * (criterion 4). This page neither writes history rows nor reads the table
 * directly, except the existence check through course_activities().
 * Persistence remains separate and extractable as its own plugin (criterion 7).
 *
 * Query modes:
 * - ?id=<courseid>: activities with history in the course.
 * - ?cmid=<cmid>: activity versions with restore actions.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\external\restore_activity_version;
use local_coursepilot\output\history_page;

$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('id', 0, PARAM_INT);
$restoreversion = optional_param('restore', 0, PARAM_INT);
$confirmed = optional_param('confirmed', 0, PARAM_BOOL);
$confirmed = optional_param('confirmed', 0, PARAM_BOOL);

if ($cmid) {
    $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    $modcontext = context_module::instance($cm->id);
    $pageurl = new moodle_url('/local/coursepilot/history.php', ['cmid' => $cmid]);
} else if ($courseid) {
    $course = get_course($courseid);
    $pageurl = new moodle_url('/local/coursepilot/history.php', ['id' => $courseid]);
} else {
    throw new moodle_exception('invalidcoursemoduleid', 'error');
}

require_login($course, true, $cmid ? $cm : null);
$coursecontext = context_course::instance($course->id);
// local/coursepilot:viewhistory uses CONTEXT_COURSE (db/access.php),
// so check the course context even in cmid mode.
require_capability('local/coursepilot:viewhistory', $coursecontext);

// Restore requires both local/coursepilot:restoreversion and
// moodle/course:manageactivities (Spec 0015 §10.7, criterion 5).
// Calculate once for both the write branch and restore-link display.
$canrestore = $cmid
    && has_capability('local/coursepilot:restoreversion', $modcontext)
    && has_capability('moodle/course:manageactivities', $modcontext);

$PAGE->set_context($cmid ? $modcontext : $coursecontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('historytitle', 'local_coursepilot'));
$PAGE->set_heading($course->fullname);

$listurl = new moodle_url('/local/coursepilot/history.php', ['id' => $course->id]);

// Restore: confirm first, execute second (criterion 3).
if ($cmid && $restoreversion) {
    // Require each capability separately so missing permissions throw their
    // own required_capability_exception. Without either restoreversion or
    // manageactivities, the restore branch is unreachable (criterion 5).
    require_capability('local/coursepilot:restoreversion', $modcontext);
    require_capability('moodle/course:manageactivities', $modcontext);

    $viewurl = new moodle_url('/local/coursepilot/history.php', ['cmid' => $cmid]);

    if (!$confirmed) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('historyrestoreconfirm', 'local_coursepilot', $restoreversion),
            new moodle_url('/local/coursepilot/history.php', [
                'cmid' => $cmid,
                'restore' => $restoreversion,
                'confirmed' => 1,
                'sesskey' => sesskey(),
            ]),
            $viewurl
        );
        echo $OUTPUT->footer();
        exit;
    }

    require_sesskey();
    try {
        $result = restore_activity_version::execute($cmid, $restoreversion, (bool) $confirmed);
        redirect($viewurl, $result['message'], null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'completiondatalossconfirmationrequired' || $confirmed) {
            throw $e;
        }
        // The two-step set_completion flow (#392) also applies through
        // restore_activity_version. Ask again rather than silently skipping
        // completion-data deletion.
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('historydatalossconfirm', 'local_coursepilot', $e->getMessage()),
            new moodle_url('/local/coursepilot/history.php', [
                'cmid' => $cmid,
                'restore' => $restoreversion,
                'confirmed' => 1,
                'confirmed' => 1,
                'sesskey' => sesskey(),
            ]),
            $viewurl
        );
        echo $OUTPUT->footer();
        exit;
    }
}

echo $OUTPUT->header();

if ($cmid) {
    echo $OUTPUT->render_from_template(
        'local_coursepilot/history_versions',
        history_page::versions_data($cmid, $cm->name, $canrestore, $listurl)
    );
} else {
    echo $OUTPUT->render_from_template(
        'local_coursepilot/history_activities',
        history_page::activities_data($course->id)
    );
}

echo $OUTPUT->footer();
