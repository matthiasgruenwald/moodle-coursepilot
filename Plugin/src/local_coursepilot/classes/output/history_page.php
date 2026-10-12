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
 * Template data for history.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

use local_coursepilot\history\version_history;

/**
 * Prepares display-neutral state for the history.php templates.
 */
final class history_page {
    /**
     * Resolves the course or activity and returns the requested history view.
     *
     * @param int $cmid Activity module id, or zero for the course list.
     * @param int $courseid Course id when no module was selected.
     * @param int $restoreversion Version to restore, or zero to view history.
     * @param bool $confirmed Whether the user confirmed restoration.
     * @return page_response A list, confirmation or successful restore redirect.
     */
    public static function response(int $cmid, int $courseid, int $restoreversion, bool $confirmed): page_response {
        [$course, $cm] = self::configure($cmid, $courseid);
        if ($cmid && $restoreversion) {
            return self::restore_response($cmid, $restoreversion, $confirmed);
        }
        if ($cm !== null) {
            $context = \context_module::instance($cmid);
            $canrestore = has_capability('local/coursepilot:restoreversion', $context)
                && has_capability('moodle/course:manageactivities', $context);
            return new page_response('local_coursepilot/history_versions', self::versions_data(
                $cmid,
                $cm->name,
                $canrestore,
                new \moodle_url('/local/coursepilot/history.php', ['id' => $course->id])
            ));
        }
        return new page_response('local_coursepilot/history_activities', self::activities_data((int) $course->id));
    }

    /**
     * Applies course login, view capability and Moodle page context.
     *
     * @param int $cmid Activity module id.
     * @param int $courseid Fallback course id.
     * @return array{0: \stdClass, 1: \stdClass|null} Course and optional module.
     */
    private static function configure(int $cmid, int $courseid): array {
        global $PAGE;
        $cm = null;
        if ($cmid) {
            $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
            $course = get_course($cm->course);
            $context = \context_module::instance($cmid);
            $url = new \moodle_url('/local/coursepilot/history.php', ['cmid' => $cmid]);
        } else if ($courseid) {
            $course = get_course($courseid);
            $context = \context_course::instance($courseid);
            $url = new \moodle_url('/local/coursepilot/history.php', ['id' => $courseid]);
        } else {
            throw new \moodle_exception('invalidcoursemoduleid', 'error');
        }
        require_login($course, true, $cm);
        require_capability('local/coursepilot:viewhistory', \context_course::instance($course->id));
        $PAGE->set_context($context);
        $PAGE->set_url($url);
        $PAGE->set_pagelayout('incourse');
        $PAGE->set_title(get_string('historytitle', 'local_coursepilot'));
        $PAGE->set_heading($course->fullname);
        return [$course, $cm];
    }

    /**
     * Confirms the requested write before invoking the published restore function.
     *
     * @param int $cmid Activity module id.
     * @param int $version Historical version.
     * @param bool $confirmed Whether confirmation was submitted.
     * @return page_response Confirmation or redirect; restore failures propagate.
     */
    private static function restore_response(int $cmid, int $version, bool $confirmed): page_response {
        $context = \context_module::instance($cmid);
        require_capability('local/coursepilot:restoreversion', $context);
        require_capability('moodle/course:manageactivities', $context);
        $viewurl = new \moodle_url('/local/coursepilot/history.php', ['cmid' => $cmid]);
        if (!$confirmed) {
            return new page_response(
                message: get_string('historyrestoreconfirm', 'local_coursepilot', $version),
                confirmurl: new \moodle_url('/local/coursepilot/history.php', [
                    'cmid' => $cmid, 'restore' => $version, 'confirmed' => 1, 'sesskey' => sesskey(),
                ]),
                cancelurl: $viewurl
            );
        }
        require_sesskey();
        $result = \local_coursepilot\external\restore_activity_version::execute($cmid, $version, $confirmed);
        return new page_response(redirecturl: $viewurl, message: $result['message']);
    }

    /**
     * Activity version list (?cmid=).
     *
     * @param int $cmid
     * @param string $activityname
     * @param bool $canrestore
     * @param \moodle_url $listurl
     * @return array<string, mixed>
     */
    public static function versions_data(
        int $cmid,
        string $activityname,
        bool $canrestore,
        \moodle_url $listurl
    ): array {
        $data = version_history::list_versions($cmid, current_language());
        $newest = $data['versions'] ? end($data['versions'])['version'] : null;

        $rows = [];
        foreach ($data['versions'] as $row) {
            $canrestorerow = $canrestore && $row['version'] !== $newest;
            $rows[] = [
                'version' => $row['version'],
                'user' => $row['user'],
                'time' => userdate($row['timestamp']),
                'summary_line' => $row['summary_line'],
                'canrestore' => $canrestorerow,
                'restoreurl' => $canrestorerow
                    ? (new \moodle_url('/local/coursepilot/history.php', [
                        'cmid' => $cmid,
                        'restore' => $row['version'],
                    ]))->out(false)
                    : null,
            ];
        }

        return [
            'activityname' => format_string($activityname),
            'isquiz' => $data['modname'] === 'quiz',
            'rows' => $rows,
            'gap_notice' => $data['gap_notice'],
            'listurl' => $listurl->out(false),
        ];
    }

    /**
     * Activities with recorded history in a course.
     *
     * @param int $courseid
     * @return array<string, mixed>
     */
    public static function activities_data(int $courseid): array {
        $activities = version_history::course_activities($courseid);

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                'name' => $activity['name'],
                'modname' => $activity['modname'],
                'viewurl' => (new \moodle_url('/local/coursepilot/history.php', [
                    'cmid' => $activity['cmid'],
                ]))->out(false),
            ];
        }

        return ['empty' => $rows === [], 'rows' => $rows];
    }
}
