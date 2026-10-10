<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

use context_course;
use context_module;
use core_external\external_api;
use core_question\local\bank\question_bank_helper;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves a named question bank in its course and validates access.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class question_bank_context {
    /**
     * @param int $courseid Course ID
     * @param int $questionbankid Question bank course module ID
     * @return array{0: \stdClass, 1: context_module} Module row with bank name and validated context
     */
    public static function resolve(int $courseid, int $questionbankid): array {
        global $DB;

        $context = context_course::instance($courseid);
        external_api::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $modulename = question_bank_helper::get_default_question_bank_activity_name();
        $sql = "SELECT cm.*, qb.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modulename}} qb ON qb.id = cm.instance
                 WHERE cm.id = :questionbankid
                   AND cm.course = :courseid
                   AND m.name = :modulename";
        $bankrecord = $DB->get_record_sql($sql, [
            'questionbankid' => $questionbankid,
            'courseid' => $course->id,
            'modulename' => $modulename,
        ]);
        if (!$bankrecord) {
            throw new \invalid_parameter_exception('Selected question bank was not found in this course.');
        }

        $bankcontext = context_module::instance((int) $bankrecord->id);
        external_api::validate_context($bankcontext);
        require_capability('local/coursepilot:use', $bankcontext);

        return [$bankrecord, $bankcontext];
    }
}
