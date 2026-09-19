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
use local_coursepilot\ausstand_notice;
use local_coursepilot\context_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Verwirft einen Eintrag der Ausstandsnotiz ausdruecklich (Issue #492, ADR
 * 0023 Punkt 3, Spec #486 §10) - der zweite der beiden Wege, auf denen ein
 * Eintrag verschwindet, neben dem Nachtragen ueber
 * `write_context_file`/`append_context_file` mit `ausstand=<Kennung>`.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class dismiss_ausstand extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'kennung' => new external_value(PARAM_ALPHANUMEXT, 'Kennung des Ausstands, aus coursepilot_list_skills'),
        ]);
    }

    /**
     * @param string $kennung
     * @return array
     * @throws \moodle_exception ausstandunknown, wenn keine Kennung existiert
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(string $kennung): array {
        $params = self::validate_parameters(self::execute_parameters(), ['kennung' => $kennung]);

        $context = context_files::own_context();
        self::validate_context($context);
        context_files::require_manage_own_files();

        if (!ausstand_notice::dismiss($params['kennung'])) {
            throw new \moodle_exception('ausstandunknown', 'local_coursepilot', '', $params['kennung']);
        }

        return [
            'kennung' => $params['kennung'],
            'message' => get_string('ausstanddismissed', 'local_coursepilot', $params['kennung']),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'kennung' => new external_value(PARAM_ALPHANUMEXT, 'Verworfene Kennung'),
            'message' => new external_value(PARAM_RAW, 'Bestaetigung in Lehrkraft-Deutsch'),
        ]);
    }
}
