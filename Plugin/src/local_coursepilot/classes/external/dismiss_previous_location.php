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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\previous_location;
use local_coursepilot\context_files;
use local_coursepilot\pointer_location;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicitly closes legacy context (#498, Spec #486 §9), following
 * {@see dismiss_pending_entry}: after copying, or when the teacher
 * waives the rest. Never closes by elapsed time or identical names.
 * Never modifies previous-location files; see {@see previous_location::dismiss()}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class dismiss_previous_location extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array
     * @throws \moodle_exception previouslocationclosed if legacy context is not open.
     * @throws \required_capability_exception without moodle/user:manageownfiles,
     *         only for legacy context in Moodle (#517,
     *         Spec §6: the capability does not apply to external storage).
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        $context = context_files::own_context();
        self::validate_context($context);

        // Pointer-aware capability check as in writes (#491): own-file management
        // applies only to Moodle Private Files. With no open legacy location, keep
        // the check; disable it only for a positively resolved external location.
        $previouslocation = previous_location::current();
        if ($previouslocation === null || $previouslocation['location'] === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }

        if (!previous_location::dismiss()) {
            throw new \moodle_exception('previouslocationclosed', 'local_coursepilot');
        }

        return ['message' => get_string('previouslocationdismissed', 'local_coursepilot')];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => new external_value(PARAM_RAW, 'Teacher-facing confirmation'),
        ]);
    }
}
