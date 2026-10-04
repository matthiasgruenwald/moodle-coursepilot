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
use local_coursepilot\course_module_placement;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Write core 13 (Spec 0015 phase 3, #391): move an activity to another
 * section, optionally to a specific position within it.
 *
 * #391 names cmactions::move_before()/move_end_section() as the Moodle 5.2
 * successor API (MDL-86854). This Moodle 5.0.8 installation's cmactions
 * (/opt/moodle/course/format/classes/local/cmactions.php) only implements
 * rename()/set_visibility(), not move. The available, non-deprecated command
 * bus is {@see \core_courseformat\stateactions::cm_move()}, also called by
 * core_courseformat\external\update_course for the course editor's cm_move
 * action. It checks moodle/course:manageactivities itself. Do not call
 * moveto_module() directly; Moodle keeps that implementation behind the API.
 *
 * position mirrors move_before(): the zero-based index of the activity to
 * move BEFORE in the target section. Omitted, negative or out-of-range
 * indices mirror move_end_section() and append at the end.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class move_module extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity to move'),
            'sectionnum' => new external_value(PARAM_INT, 'Target section number (0-based)'),
            'position' => new external_value(
                PARAM_INT,
                'Optional zero-based index in the target section (before the activity currently at that index); '
                    . 'omit to append at the end of the target section',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param int $sectionnum
     * @param int|null $position
     * @return array
     * @throws moodle_exception sectionnotfound
     */
    public static function execute(int $cmid, int $sectionnum, ?int $position = null): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'sectionnum' => $sectionnum,
            'position' => $position,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_course::instance($cm->course);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Check native permissions even though stateactions::cm_move() repeats it.
        // This cheap check ensures a missing capability cannot be hidden behind
        // position validation.
        require_capability('moodle/course:manageactivities', $context);

        $course = get_course($cm->course);
        $modinfo = get_fast_modinfo($course);
        $sections = $modinfo->get_section_info_all();
        // Use a Coursepilot error instead of get_section_info(..., MUST_EXIST),
        // matching update_section/move_section for a missing target section, with
        // a localized message that points toward the describe tools.
        if (!array_key_exists($params['sectionnum'], $sections)) {
            throw new moodle_exception('sectionnotfound', 'local_coursepilot', '', ['sectionnum' => $params['sectionnum']]);
        }
        $targetsection = $sections[$params['sectionnum']];

        $targetcmids = $modinfo->sections[$params['sectionnum']] ?? [];
        // Exclude this cmid when moving within its current section. Counting it as
        // a "before itself" target would shift positions after the current one by one.
        $targetcmids = array_values(array_filter($targetcmids, static fn (int $id): bool => $id !== $cm->id));

        $position = $params['position'];
        $targetcmid = null;
        if ($position !== null && $position >= 0 && $position < count($targetcmids)) {
            $targetcmid = $targetcmids[$position];
        }

        course_module_placement::move_to((int) $cm->id, (int) $targetsection->id, $targetcmid);
        $format = course_get_format($course);

        $sectionname = $format->get_section_name($targetsection);
        $positionmessage = $targetcmid !== null
            ? " an Position {$position}"
            : '';

        return [
            'cmid' => (int) $cm->id,
            'sectionnum' => (int) $params['sectionnum'],
            'message' => "Activity \"{$cm->name}\" moved to section \"{$sectionname}\"{$positionmessage}.",
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the moved activity'),
            'sectionnum' => new external_value(PARAM_INT, 'Target section number'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German change message'),
        ]);
    }
}
