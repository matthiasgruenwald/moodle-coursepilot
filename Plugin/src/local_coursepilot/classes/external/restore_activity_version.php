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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\history\version_restore;

/**
 * External adapter for carrying a saved activity state forward through history.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class restore_activity_version extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'target_version' => new external_value(PARAM_INT, 'Version number to write back to'),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms deleting existing completion data of learners, if writing back the '
                    . 'completion fields would trigger that (two-step confirmation of set_completion). Without '
                    . 'data-loss risk this parameter has no effect. Omit or false on the first call.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Runs the restore activity version tool.
     *
     * @param int $cmid
     * @param int $targetversion
     * @param bool $confirmed
     * @return mixed[]
     */
    public static function execute(int $cmid, int $targetversion, bool $confirmed = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'target_version' => $targetversion,
            'confirmed' => $confirmed,
        ]);

        return version_restore::restore($params['cmid'], $params['target_version'], $params['confirmed']);
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value before the write'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value after the write'),
                ]),
                'One entry per field that actually changed'
            ),
        ]);
    }
}
