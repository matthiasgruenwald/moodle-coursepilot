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
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

/**
 * Write core 13 (Spec 0015 phase 3, ticket #391): moves a section
 * to another position in the course.
 *
 * Ticket #391 names cmactions/sectionactions::move_after()/move_at() as the
 * target API of the 5.2 successor (MDL-86854/MDL-86862). On this instance (real
 * Moodle 5.0.8 source, see /opt/moodle/course/format/classes/local/) this
 * replacement has not landed yet - {@see \core_courseformat\local\sectionactions}
 * has no "move_after" in 5.0.8. The command bus that actually exists and is
 * NOT deprecated for this action is
 * {@see \core_courseformat\stateactions::section_move_after()} - the same
 * method that core_courseformat\external\update_course (the web service
 * function "core_courseformat_update_course" used by the JS course editing)
 * calls for the action "section_move_after". No direct call of
 * move_section_to() - that remains Moodle's own internal implementation
 * behind this abstraction and will be swapped out invisibly for this caller
 * on the later switch to sectionactions::move_after(). *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class move_section extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sourcesectionnum' => new external_value(PARAM_INT, 'Current section number'),
            'targetsectionnum' => new external_value(PARAM_INT, 'Desired section number after the move'),
        ]);
    }

    /**
     * Runs the move section tool.
     *
     * @param int $courseid
     * @param int $sourcesectionnum
     * @param int $targetsectionnum
     * @return array
     * @throws moodle_exception sectionnotmovable|sectiontargetoutofrange
     */
    public static function execute(int $courseid, int $sourcesectionnum, int $targetsectionnum): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sourcesectionnum' => $sourcesectionnum,
            'targetsectionnum' => $targetsectionnum,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native permission check: stateactions::section_move_after()
        // re-checks 'moodle/course:movesections' itself anyway - the
        // call here is cheap and ensures a missing permission is not
        // hidden behind a position validation.
        require_capability('moodle/course:movesections', $context);

        $course = get_course($params['courseid']);
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        $maxsectionnum = max(array_keys($sections));

        $from = $params['sourcesectionnum'];
        $to = $params['targetsectionnum'];

        if ($from <= 0 || !array_key_exists($from, $sections)) {
            throw new moodle_exception('sectionnotmovable', 'local_coursepilot', '', ['sectionnum' => $from]);
        }
        if ($to < 1 || $to > $maxsectionnum) {
            throw new moodle_exception(
                'sectiontargetoutofrange',
                'local_coursepilot',
                '',
                ['target' => $to, 'max' => $maxsectionnum]
            );
        }

        $format = course_get_format($course);
        $sectionname = $format->get_section_name($sections[$from]);

        if ($from === $to) {
            return [
                'id' => (int) $sections[$from]->id,
                'sectionnum' => (int) $to,
                'message' => "Section \"{$sectionname}\" is already at position {$to}.",
            ];
        }

        // Destination number: sectionactions::move_after()/move_at() would have
        // taken the target position directly; the command bus available here
        // (section_move_after) instead asks "insert after which
        // section" - the conversion is pure index arithmetic
        // (see class doc).
        $destinationnum = $to > $from ? $to : $to - 1;
        $destination = $sections[$destinationnum];

        $updates = $format->get_stateupdates_instance();
        $actions = $format->get_stateactions_instance();
        $actions->section_move_after($updates, $course, [$sections[$from]->id], $destination->id);

        return [
            'id' => (int) $sections[$from]->id,
            'sectionnum' => (int) $to,
            'message' => "Section \"{$sectionname}\" moved from position {$from} to position {$to}.",
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Section DB ID'),
            'sectionnum' => new external_value(PARAM_INT, 'New section number after the move'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
        ]);
    }
}
