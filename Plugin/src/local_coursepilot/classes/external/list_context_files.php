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
use local_coursepilot\context_area;
use local_coursepilot\context_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Listet den Kontextbereich der aufrufenden Lehrkraft (Issue #343): ein
 * einziger, fest verdrahteter Dateibereich im eigenen privaten
 * Nutzerkontext - kein Parameter adressiert einen anderen Bereich, eine
 * andere Person oder einen anderen Ort ausserhalb dieses Bereichs.
 *
 * Ortsneutral seit Issue #538 (Spec 0021): {@see context_area::list()}
 * liefert bereits denselben Feldsatz fuer beide Orte - dieses Werkzeug
 * unterscheidet selbst nicht mehr zwischen Moodle und extern.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class list_context_files extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Relativer Unterordner, leer fuer die Wurzel', VALUE_DEFAULT, ''),
            'vorheriger_ort' => new external_value(
                PARAM_BOOL,
                'Optional: true listet den vorherigen Ort statt des aktuellen (Nur-Lese-Schalter fuer den '
                    . 'Altbestand, Issue #498) - wirkt nur, solange ein Altbestand offen ist',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param string $path
     * @param bool $previouslocation
     * @return array
     * @throws \moodle_exception invalidcontextpath, wenn $path ein "."/".."-
     *         Segment enthaelt; altbestandclosed, wenn "vorheriger_ort" ohne
     *         offenen Altbestand gesetzt ist.
     */
    public static function execute(string $path = '', bool $previouslocation = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['path' => $path, 'vorheriger_ort' => $previouslocation]
        );

        // Kein zusaetzliches 'local/coursepilot:use' o.ae. (anders als
        // list_courses/get_course_catalog): der Kontextbereich ist an die
        // Person gebunden, nicht an einen Kurs - das Standard-Nutzerrecht
        // genuegt laut Issue #343. validate_context() erzwingt require_login()
        // fuer den eigenen Nutzerkontext; die globale Fernzugriffs-Capability
        // 'local/coursepilot:useremote' prueft bereits
        // dispatcher::handle_authorized() vor jedem Tool-Aufruf.
        $context = context_files::own_context();
        self::validate_context($context);

        $result = context_area::list($params['path'], $params['vorheriger_ort']);

        return [
            'path' => $result['directory'],
            'entries' => $result['entries'],
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(
                PARAM_TEXT,
                'Aufgeloester Unterordner, relativ zur Kontextwurzel (leer = Wurzel) - dieselbe Schreibweise, '
                    . 'die die Werkzeuge entgegennehmen'
            ),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Datei- oder Ordnername'),
                    'type' => new external_value(PARAM_ALPHA, '"file" oder "folder"'),
                    'size' => new external_value(PARAM_INT, 'Dateigroesse in Byte, 0 bei Ordnern'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME-Typ, leer bei Ordnern'),
                    'locked' => new external_value(
                        PARAM_BOOL,
                        'Personenbezogen markiert und Schalter aus - Inhalt nicht lesbar, aber sichtbar gelistet'
                    ),
                    // Additiv ergaenzt (Spec 0016 Paragraph 2): Grundlage fuer
                    // Gleichzeitigkeitsschutz und Handaenderungs-Erkennung.
                    'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Inhaltspruefsumme, leer bei Ordnern'),
                    'timemodified' => new external_value(PARAM_INT, 'Zeitpunkt der letzten Aenderung, 0 bei Ordnern'),
                ])
            ),
        ]);
    }
}
