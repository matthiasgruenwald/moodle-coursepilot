<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\ausstand_notice;
use local_kurspilot\context_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Verwirft einen Eintrag der Ausstandsnotiz ausdruecklich (Issue #492, ADR
 * 0023 Punkt 3, Spec #486 §10) - der zweite der beiden Wege, auf denen ein
 * Eintrag verschwindet, neben dem Nachtragen ueber
 * `write_context_file`/`append_context_file` mit `ausstand=<Kennung>`.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dismiss_ausstand extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'kennung' => new external_value(PARAM_ALPHANUMEXT, 'Kennung des Ausstands, aus kurspilot_list_skills'),
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
            throw new \moodle_exception('ausstandunknown', 'local_kurspilot', '', $params['kennung']);
        }

        return [
            'kennung' => $params['kennung'],
            'message' => get_string('ausstanddismissed', 'local_kurspilot', $params['kennung']),
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
