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
use local_coursepilot\context_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Liest eine Datei aus dem Kontextbereich der aufrufenden Lehrkraft (Issue
 * #343). V1-Vertrag: nur lesen. Schreiben ist ueber diese Oberflaeche
 * technisch nicht moeglich - es gibt keine entsprechende Funktion.
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
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Kontextbereich, z.B. "vorlagen.md"'),
            'vorheriger_ort' => new external_value(
                PARAM_BOOL,
                'Optional: true liest vom vorherigen Ort statt vom aktuellen (Nur-Lese-Schalter fuer den '
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
     * @throws \moodle_exception invalidcontextpath fuer einen leeren Pfad oder
     *         ein "."/".."-Segment; contextfilenotfound, wenn die Datei fehlt;
     *         altbestandclosed, wenn "vorheriger_ort" ohne offenen Altbestand
     *         gesetzt ist.
     */
    public static function execute(string $path, bool $previouslocation = false): array {
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

        // Zeigerbewusst (Issue #490): folgt dem Kontextpointer nach Moodle
        // oder extern (WebDAV) - der Aufrufer hier kennt den Unterschied
        // nicht, das Ergebnis hat in beiden Faellen dieselbe Form.
        //
        // Nur-Lese-Schalter fuer den vorherigen Ort (Issue #498, Spec #486
        // §6/§9): siehe list_context_files fuer die Begruendung.
        $file = $params['vorheriger_ort']
            ? context_files::read_content_previous_location($params['path'], \local_coursepilot\altbestand::require_open_location())
            : context_files::read_content_pointer_aware($params['path']);
        if ($file === null) {
            throw new \moodle_exception('contextfilenotfound', 'local_coursepilot', '', $params['path']);
        }

        // Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011):
        // wirkt auf der Frontmatter-Markierung, nicht auf dem Inhalt -
        // siehe local_coursepilot\personal_data.
        if (\local_coursepilot\personal_data::is_marked($file['content']) && !\local_coursepilot\personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $params['path']);
        }

        return [
            'path' => $file['path'],
            'filename' => basename($file['path']),
            'mimetype' => $file['mimetype'],
            'size' => $file['size'],
            'content' => $file['content'],
            'contenthash' => self::checkvalue($file),
            'timemodified' => $file['timemodified'],
        ];
    }

    /**
     * Konfliktschutz (Issue #513, Spec #486 §4/§6): am externen Ort traegt
     * {@see context_files::read_content_pointer_aware()}/{@see \local_coursepilot\pointer_reader::read_content()}
     * zusaetzlich ein internes "etag"-Feld (auch mit Wert null, IServ) - nur
     * dann bildet diese Methode daraus den Pruefwert, den
     * "write_context_file"/"append_context_file" als "expected_contenthash"
     * wieder entgegennehmen. Am Moodle-Ort (kein "etag"-Feld) bleibt der
     * bereits mitgelieferte, echte Moodle-Contenthash unangetastet.
     *
     * @param array $file
     * @return string
     */
    private static function checkvalue(array $file): string {
        if (!array_key_exists('etag', $file)) {
            return $file['contenthash'];
        }
        return \local_coursepilot\pointer_reader::external_checkvalue($file['etag'], $file['timemodified']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Aufgeloester Dateipfad, relativ zum Kontextbereich'),
            'filename' => new external_value(PARAM_TEXT, 'Dateiname'),
            'mimetype' => new external_value(PARAM_RAW, 'MIME-Typ'),
            'size' => new external_value(PARAM_INT, 'Dateigroesse in Byte'),
            'content' => new external_value(PARAM_RAW, 'Dateiinhalt'),
            // Additiv ergaenzt (Spec 0016 Paragraph 2): Grundlage fuer
            // Gleichzeitigkeitsschutz und Handaenderungs-Erkennung.
            'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Inhaltspruefsumme der Datei'),
            'timemodified' => new external_value(PARAM_INT, 'Zeitpunkt der letzten Aenderung'),
        ]);
    }
}
