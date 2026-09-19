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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\history\version_history;

defined('MOODLE_INTERNAL') || die();

/**
 * Volles Diff zweier frei gewaehlter Staende einer Aktivitaet (Spec 0015
 * §10.6, Ticket #394) - nicht nur benachbarter Versionen. Das Diff wird beim
 * Ansehen berechnet, nicht gespeichert (Spec 0015 §10.1). Rein lesend,
 * eigene Faehigkeit 'local/coursepilot:viewhistory'.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class compare_activity_versions extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID der Aktivitaet'),
            'von_version' => new external_value(PARAM_INT, 'Erste zu vergleichende Versionsnummer'),
            'nach_version' => new external_value(PARAM_INT, 'Zweite zu vergleichende Versionsnummer'),
        ]);
    }

    /**
     * @param int $cmid
     * @param int $vonversion
     * @param int $nachversion
     * @return array
     */
    public static function execute(int $cmid, int $vonversion, int $nachversion): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'von_version' => $vonversion,
            'nach_version' => $nachversion,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:viewhistory', $context);

        return version_history::compare($params['cmid'], $params['von_version'], $params['nach_version']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $standblock = new external_single_structure([
            'version' => new external_value(PARAM_INT, 'Versionsnummer'),
            'quelle' => new external_value(PARAM_TEXT, '"moodle", "vorgefunden" oder "geklont"'),
            'vorgefunden' => new external_value(PARAM_BOOL, 'true, wenn rueckwirkend als Ausgangsstand angelegt'),
            'quellcmid' => new external_value(
                PARAM_INT,
                'Quell-Modul-ID eines Klons - nur bei quelle = "geklont" gesetzt, sonst null',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'userid' => new external_value(PARAM_INT, 'Nutzer-ID, unter der der Schreibvorgang lief'),
            'nutzer' => new external_value(PARAM_TEXT, 'Voller Name dieser Nutzerin/dieses Nutzers'),
            'zeitpunkt' => new external_value(PARAM_INT, 'Unix-Zeitstempel des Schreibvorgangs'),
        ]);

        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Aktivitaetstyp'),
            'von' => $standblock,
            'nach' => $standblock,
            'aenderungen' => new external_multiple_structure(
                new external_single_structure([
                    'feld' => new external_value(PARAM_TEXT, 'Feldname'),
                    'von_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert im Von-Stand'),
                    'auf_json' => new external_value(PARAM_RAW, 'JSON-kodierter Wert im Nach-Stand'),
                ]),
                'Je tatsaechlich unterschiedlichem Feld ein Eintrag'
            ),
            'dateien' => new external_multiple_structure(
                new external_single_structure([
                    'aenderung' => new external_value(PARAM_TEXT, '"hinzugefuegt" oder "entfernt"'),
                    'dateiname' => new external_value(PARAM_TEXT, 'Dateiname'),
                ]),
                'Dateien, die zwischen den beiden Staenden hinzugekommen oder weggefallen sind'
            ),
            'hinweis_luecken' => new external_value(
                PARAM_TEXT,
                'Fester Hinweis auf die strukturellen Luecken des Verlaufs - nicht pro Vergleich berechnet'
            ),
        ]);
    }
}
