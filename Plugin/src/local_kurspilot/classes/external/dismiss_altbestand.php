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
use local_kurspilot\altbestand;
use local_kurspilot\context_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Beendet den Altbestand ausdruecklich (Issue #498, Spec #486 §9), nach dem
 * Muster von {@see dismiss_ausstand}: die KI schliesst ihn nach dem
 * Kopieren, oder die Lehrkraft verzichtet auf den Rest. Nie durch
 * Zeitablauf, nie durch Namensgleichheit. Ruehrt nie an den Dateien des
 * vorherigen Ortes selbst - siehe {@see altbestand::dismiss()}.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        $context = context_files::own_context();
        self::validate_context($context);
        context_files::require_manage_own_files();

        if (!altbestand::dismiss()) {
            throw new \moodle_exception('altbestandclosed', 'local_kurspilot');
        }

        return ['message' => get_string('altbestanddismissed', 'local_kurspilot')];
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
