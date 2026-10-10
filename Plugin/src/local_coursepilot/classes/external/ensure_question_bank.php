<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use context_course;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_question\local\bank\question_bank_helper;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

/**
 * Idempotent creation of a named question bank activity (Spec 0017 §1,
 * ticket #412): creates a question bank with the given name, or
 * reuses an existing one of the same name - a second run with the
 * same name does not create a second bank.
 *
 * Standalone port of
 * local_coursepilot\external\ensure_question_bank - per
 * Spec 0012 local_coursepilot has no runtime dependency on the other plugin (see
 * get_question_categories.php from #342, same finding). Unlike the
 * local original: teacher-facing message instead of English-only (CLAUDE.md), and
 * only the native Moodle permission check - no additional capability.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class ensure_question_bank extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'name' => new external_value(PARAM_TEXT, 'Name of the question bank, e.g. "Biologie 9a - Immunsystem"'),
        ]);
    }

    /**
     * Runs the ensure question bank tool.
     *
     * @param int $courseid
     * @param string $name
     * @return array
     */
    public static function execute(int $courseid, string $name): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'name' => $name,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $coursecontext = context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        // Native permission check: a question bank is an activity.
        require_capability('moodle/course:manageactivities', $coursecontext);

        $modulename = question_bank_helper::get_default_question_bank_activity_name();
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modulename}} qb ON qb.id = cm.instance
                 WHERE cm.course = :courseid
                   AND m.name = :modulename
                   AND qb.type = :type
                   AND qb.name = :name
              ORDER BY cm.id ASC";

        $existing = $DB->get_record_sql($sql, [
            'courseid' => $course->id,
            'modulename' => $modulename,
            'type' => question_bank_helper::TYPE_STANDARD,
            'name' => $params['name'],
        ], IGNORE_MULTIPLE);

        if ($existing) {
            $bankcontext = context_module::instance((int) $existing->id);
            self::validate_context($bankcontext);
            require_capability('local/coursepilot:use', $bankcontext);
            $topcategory = question_get_top_category($bankcontext->id, true);

            return [
                'questionbankid' => (int) $existing->id,
                'name' => $params['name'],
                'contextid' => (int) $bankcontext->id,
                'topcategoryid' => (int) $topcategory->id,
                'created' => false,
                'message' => get_string('questionbankreused', 'local_coursepilot', $params['name']),
            ];
        }

        $bankcm = question_bank_helper::create_default_open_instance(
            $course,
            $params['name'],
            question_bank_helper::TYPE_STANDARD
        );
        $bankcontext = $bankcm->context;
        self::validate_context($bankcontext);
        require_capability('local/coursepilot:use', $bankcontext);
        $topcategory = question_get_top_category($bankcontext->id, true);

        return [
            'questionbankid' => (int) $bankcm->id,
            'name' => $params['name'],
            'contextid' => (int) $bankcontext->id,
            'topcategoryid' => (int) $topcategory->id,
            'created' => true,
            'message' => get_string('questionbankcreated', 'local_coursepilot', $params['name']),
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the (created or reused) question bank'),
            'name' => new external_value(PARAM_TEXT, 'Name of the question bank'),
            'contextid' => new external_value(PARAM_INT, 'Context ID of the question bank'),
            'topcategoryid' => new external_value(PARAM_INT, 'ID of the question bank\'s top category'),
            'created' => new external_value(PARAM_BOOL, 'true if newly created; false if a same-named one was reused'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing message'),
        ]);
    }
}
