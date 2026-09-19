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
use local_coursepilot\altbestand;
use local_coursepilot\context_files;
use local_coursepilot\pointer_location;

defined('MOODLE_INTERNAL') || die();

/**
 * Beendet den Altbestand ausdruecklich (Issue #498, Spec #486 §9), nach dem
 * Muster von {@see dismiss_ausstand}: die KI schliesst ihn nach dem
 * Kopieren, oder die Lehrkraft verzichtet auf den Rest. Nie durch
 * Zeitablauf, nie durch Namensgleichheit. Ruehrt nie an den Dateien des
 * vorherigen Ortes selbst - siehe {@see altbestand::dismiss()}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class dismiss_altbestand extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array
     * @throws \moodle_exception altbestandclosed, wenn kein Altbestand offen ist.
     * @throws \required_capability_exception ohne moodle/user:manageownfiles,
     *         nur wenn der Altbestand selbst *in Moodle* liegt (Issue #517,
     *         Spec §6: das Recht wirkt extern nicht).
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        $context = context_files::own_context();
        self::validate_context($context);

        // Zeigerbewusst wie der Schreibzweig (Issue #491): das Recht gilt nur
        // fuer Moodles Private Files, ein externer Altbestand kennt es nicht.
        // Ohne offenen Altbestand (Ort unbekannt) bleibt die Pruefung wie
        // zuvor bestehen - nur ein positiv erkannter externer Ort schaltet
        // sie ab, kein blosses Fehlen.
        $previouslocation = altbestand::current();
        if ($previouslocation === null || $previouslocation['ort'] === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }

        if (!altbestand::dismiss()) {
            throw new \moodle_exception('altbestandclosed', 'local_coursepilot');
        }

        return ['message' => get_string('altbestanddismissed', 'local_coursepilot')];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'message' => new external_value(PARAM_RAW, 'Bestaetigung in Lehrkraft-Deutsch'),
        ]);
    }
}
