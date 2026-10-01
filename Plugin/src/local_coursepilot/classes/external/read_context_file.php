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
use local_coursepilot\previous_location;
use local_coursepilot\context_area;
use local_coursepilot\context_files;
use local_coursepilot\personal_data;

defined('MOODLE_INTERNAL') || die();

/**
 * Liest eine Datei aus dem Kontextbereich der aufrufenden Lehrkraft (Issue
 * #343). V1-Vertrag: nur lesen. Schreiben ist ueber diese Oberflaeche
 * technisch nicht moeglich - es gibt keine entsprechende Funktion.
 *
 * Ortsneutral seit Issue #538 (Spec 0021): dieses Werkzeug kennt kein
 * "etag"-Sonderfeld mehr - {@see context_area::read()}/{@see context_area::read_previous_location()}
 * liefern den Pruefwert bereits als "contenthash", gleich ob Moodle- oder
 * externer Ort.
 *
 * Unmittelbar englisch deklariert (#571, Spec 0025 §A): "previous_location"
 * statt "vorheriger_ort", derselbe Durchstich wie bei {@see list_context_files}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class read_context_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'File path relative to the context area, e.g. "vorlagen.md"'),
            'previous_location' => new external_value(
                PARAM_BOOL,
                'Optional: true reads from the previous location instead of the current one (read-only switch for '
                    . 'the Altbestand/legacy stock, Issue #498) - only takes effect while a legacy stock is open',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param string $path
     * @param bool $previouslocation
     * @return array
     * @throws \moodle_exception invalidcontextpath fuer einen leeren Pfad oder
     *         ein "."/".."-Segment; contextfilenotfound, wenn die Datei fehlt;
     *         altbestandclosed, wenn "previous_location" ohne offenen Altbestand
     *         gesetzt ist.
     */
    public static function execute(string $path, bool $previouslocation = false): array {
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

        $file = $params['previous_location']
            ? context_area::read_previous_location($params['path'], previous_location::require_open_location())
            : context_area::read($params['path']);
        if ($file === null) {
            throw new \moodle_exception('contextfilenotfound', 'local_coursepilot', '', $params['path']);
        }

        // Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011):
        // wirkt auf der Frontmatter-Markierung, nicht auf dem Inhalt -
        // siehe local_coursepilot\personal_data.
        if (personal_data::is_marked($file['content']) && !personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $params['path']);
        }

        return [
            'path' => $file['path'],
            'filename' => basename($file['path']),
            'mimetype' => $file['mimetype'],
            'size' => $file['size'],
            'content' => $file['content'],
            'contenthash' => $file['contenthash'],
            'timemodified' => $file['timemodified'],
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Resolved file path, relative to the context area'),
            'filename' => new external_value(PARAM_TEXT, 'File name'),
            'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
            'size' => new external_value(PARAM_INT, 'File size in bytes'),
            'content' => new external_value(PARAM_RAW, 'File content'),
            // Additiv ergaenzt (Spec 0016 Paragraph 2): Grundlage fuer
            // Gleichzeitigkeitsschutz und Handaenderungs-Erkennung.
            'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Content checksum of the file'),
            'timemodified' => new external_value(PARAM_INT, 'Time of last change'),
        ]);
    }
}
