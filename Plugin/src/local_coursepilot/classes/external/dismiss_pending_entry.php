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
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\pending_write_notice;
use local_coursepilot\context_files;

/**
 * Explicitly dismisses a pending-note entry (#492, ADR 0023 item 3,
 * Spec #486 §10), the second removal path besides replaying the write
 * through write_context_file/append_context_file with pending_entry=<ID>
 * (parameter declared in English since #571).
 *
 * First complete English migration slice from #568 (Spec 0025 §A):
 * input, return keys and descriptions are directly English. Since #573
 * this is the sole MCP contract, without a translation layer.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class dismiss_pending_entry extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'identifier' => new external_value(PARAM_ALPHANUMEXT, 'Identifier of the pending entry, from coursepilot_list_skills'),
        ]);
    }

    /**
     * Runs the dismiss pending entry tool.
     *
     * @param string $identifier
     * @return mixed[]
     * @throws \moodle_exception pendingunknown if the identifier does not exist
     * @throws \required_capability_exception without moodle/user:manageownfiles
     */
    public static function execute(string $identifier): array {
        $params = self::validate_parameters(self::execute_parameters(), ['identifier' => $identifier]);

        $context = context_files::own_context();
        self::validate_context($context);
        context_files::require_manage_own_files();

        if (!pending_write_notice::dismiss($params['identifier'])) {
            throw new \moodle_exception('pendingunknown', 'local_coursepilot', '', $params['identifier']);
        }

        return [
            'identifier' => $params['identifier'],
            'message' => get_string('pendingdismissed', 'local_coursepilot', $params['identifier']),
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'identifier' => new external_value(PARAM_ALPHANUMEXT, 'Dismissed identifier'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing confirmation'),
        ]);
    }
}
