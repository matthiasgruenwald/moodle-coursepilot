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

namespace local_coursepilot\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

/**
 * Lists the courses in which the calling teacher may use Coursepilot.
 *
 * The list is restricted to courses with 'local/coursepilot:use'
 * (card decision #295, point 3). Without a single such course there
 * is no data, but the concrete capability error (#295, point 4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class list_courses extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Runs the list courses tool.
     *
     * @return array
     * @throws moodle_exception CAPABILITY_MISSING if no course is authorized.
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        $courses = [];
        foreach (enrol_get_my_courses('id, fullname, shortname, visible') as $course) {
            $context = context_course::instance($course->id);
            if (!has_capability('local/coursepilot:use', $context)) {
                continue;
            }
            self::validate_context($context);
            $courses[] = [
                'id' => (int) $course->id,
                'fullname' => (string) $course->fullname,
                'shortname' => (string) $course->shortname,
                'visible' => (bool) $course->visible,
            ];
        }

        if (!$courses) {
            throw new moodle_exception('capabilitymissing', 'local_coursepilot', '', 'local/coursepilot:use');
        }

        return ['courses' => $courses];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Course id'),
                    'fullname' => new external_value(PARAM_TEXT, 'Course full name'),
                    'shortname' => new external_value(PARAM_TEXT, 'Course short name'),
                    'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
                ])
            ),
        ]);
    }
}
