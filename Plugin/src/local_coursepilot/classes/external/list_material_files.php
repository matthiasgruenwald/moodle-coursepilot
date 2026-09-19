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
use local_coursepilot\material_area;
use local_coursepilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Listet den Materialordner der aufrufenden Lehrkraft (Spec 0018 §2, Issue
 * #428): Groesse, `contenthash`, Aenderungszeit je Datei, und der
 * verbleibende Speicherplatz nach Nutzerquote - kein Parameter adressiert
 * einen anderen Bereich oder eine andere Person.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class list_material_files extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Relativer Unterordner, leer fuer die Wurzel', VALUE_DEFAULT, ''),
            'ort' => material_files::ort_parameter(),
        ]);
    }

    /**
     * @param string $path
     * @param string $ort
     * @return array
     * @throws \moodle_exception invalidmaterialpath, wenn $path ein "."/".."-
     *         Segment enthaelt, invalidmaterialort bei einem unbekannten
     *         "ort"-Wert, materialpathiskontext, wenn der Kontextbereich im
     *         Bestand liegt und $path darunter fuehrt.
     */
    public static function execute(string $path = '', string $ort = material_files::ORT_BESTAND): array {
        $params = self::validate_parameters(self::execute_parameters(), ['path' => $path, 'ort' => $ort]);

        $context = material_files::own_context();
        self::validate_context($context);

        // Der Kontextpointer (Issue #445) liegt physisch im
        // Kontextbereich-Anker, nicht hier - list_entries_for_ort() schliesst
        // ihn aus Konsistenzgruenden trotzdem aus, falls Anker und
        // Materialordner je zusammenfallen. material_area::list() entfernt
        // das nur intern gebrauchte "etag"-Feld bereits ortsneutral (Issue #539).
        $result = material_area::list($params['ort'], $params['path']);

        $remaining = material_files::remaining_quota();

        return [
            'path' => $result['directory'],
            'entries' => $result['entries'],
            'remaining_quota_mb' => $remaining === null ? null : format_float($remaining / 1048576, 1),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(
                PARAM_TEXT,
                'Aufgeloester Unterordner, relativ zur Materialwurzel (leer = Wurzel) - dieselbe Schreibweise, '
                    . 'die die Werkzeuge entgegennehmen'
            ),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Datei- oder Ordnername'),
                    'type' => new external_value(
                        PARAM_ALPHA,
                        '"file", "folder" oder "kontextbereich" (der Kontextbereich liegt hier im Bestand - '
                            . 'ueber die Materialwege nicht zu betreten, siehe list_context_files)'
                    ),
                    'size' => new external_value(PARAM_INT, 'Dateigroesse in Byte, 0 bei Ordnern'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME-Typ, leer bei Ordnern'),
                    'contenthash' => new external_value(
                        PARAM_ALPHANUMEXT,
                        'Inhaltspruefsumme, leer bei Ordnern und beim externen Materialbestand (WebDAV kennt keinen contenthash)'
                    ),
                    'timemodified' => new external_value(PARAM_INT, 'Zeitpunkt der letzten Aenderung, 0 bei Ordnern'),
                ])
            ),
            'remaining_quota_mb' => new external_value(
                PARAM_RAW,
                'Verbleibender Speicherplatz in MB (als Zeichenkette formatiert), null wenn keine Quote gilt',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
