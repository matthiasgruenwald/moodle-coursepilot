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
 * Unmittelbar englisch deklariert (#571, Spec 0025 §A): "previous_location"
 * statt "vorheriger_ort" - derselbe Durchstich wie bei den Kurs-/Aktivitaets-
 * und Fragenbankwerkzeugen aus #569/#570, {@see \local_coursepilot\contract_keys}
 * uebersetzt diesen Aufruf seitdem nicht mehr.
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
            'path' => new external_value(PARAM_PATH, 'Relative subfolder, empty for the root', VALUE_DEFAULT, ''),
            'previous_location' => new external_value(
                PARAM_BOOL,
                'Optional: true lists the previous location instead of the current one (read-only switch for the '
                    . 'Altbestand/legacy stock, Issue #498) - only takes effect while a legacy stock is open',
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
     *         Segment enthaelt; altbestandclosed, wenn "previous_location" ohne
     *         offenen Altbestand gesetzt ist.
     */
    public static function execute(string $path = '', bool $previouslocation = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['path' => $path, 'previous_location' => $previouslocation]
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

        $result = context_area::list($params['path'], $params['previous_location']);

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
                'Resolved subfolder, relative to the context root (empty = root) - the same notation the tools '
                    . 'accept'
            ),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'File or folder name'),
                    'type' => new external_value(PARAM_ALPHA, '"file" or "folder"'),
                    'size' => new external_value(PARAM_INT, 'File size in bytes, 0 for folders'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type, empty for folders'),
                    'locked' => new external_value(
                        PARAM_BOOL,
                        'Marked as personal data with the switch off - content unreadable, but still listed'
                    ),
                    // Additiv ergaenzt (Spec 0016 Paragraph 2): Grundlage fuer
                    // Gleichzeitigkeitsschutz und Handaenderungs-Erkennung.
                    'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Content checksum, empty for folders'),
                    'timemodified' => new external_value(PARAM_INT, 'Time of last change, 0 for folders'),
                ])
            ),
        ]);
    }
}
