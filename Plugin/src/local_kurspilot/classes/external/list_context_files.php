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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\context_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Listet den Kontextbereich der aufrufenden Lehrkraft (Issue #343): ein
 * einziger, fest verdrahteter Dateibereich im eigenen privaten
 * Nutzerkontext - kein Parameter adressiert einen anderen Bereich, eine
 * andere Person oder einen anderen Ort ausserhalb dieses Bereichs.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class list_context_files extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Relativer Unterordner, leer fuer die Wurzel', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @param string $path
     * @return array
     * @throws \moodle_exception invalidcontextpath, wenn $path ein "."/".."-
     *         Segment enthaelt.
     */
    public static function execute(string $path = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), ['path' => $path]);

        // Kein zusaetzliches 'local/kurspilot:use' o.ae. (anders als
        // list_courses/get_course_catalog): der Kontextbereich ist an die
        // Person gebunden, nicht an einen Kurs - das Standard-Nutzerrecht
        // genuegt laut Issue #343. validate_context() erzwingt require_login()
        // fuer den eigenen Nutzerkontext; die globale Fernzugriffs-Capability
        // 'local/kurspilot:useremote' prueft bereits
        // dispatcher::handle_authorized() vor jedem Tool-Aufruf.
        $context = context_files::own_context();
        self::validate_context($context);

        // Zeigerbewusst (Issue #490): folgt dem Kontextpointer nach Moodle
        // oder extern (WebDAV) - der Aufrufer hier kennt den Unterschied
        // nicht, das Ergebnis hat in beiden Faellen dieselbe Form.
        $result = context_files::list_entries_pointer_aware($params['path']);

        $entries = [];
        foreach ($result['entries'] as $entry) {
            if ($entry['type'] === 'folder') {
                $entries[] = $entry + ['locked' => false];
                continue;
            }
            // Schalter fuer personenbezogene Kontextdaten (#344, ADR 0011):
            // ein gesperrter Eintrag erscheint sichtbar gesperrt, nicht
            // weggelassen - siehe local_kurspilot\personal_data.
            //
            // Nur .md-Dateien werden dafuer eingelesen: seit dem Umzug auf
            // Moodles Private Files (#407) kann die Lehrkraft hier ueber
            // "Meine Dateien" beliebige Dateien ablegen, und die Markierung
            // steht ausschliesslich im Frontmatter einer Markdown-Datei. Ohne
            // diese Grenze laese die Auflistung jede fremde Datei des Ordners
            // vollstaendig in den Speicher.
            $ismarkdown = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) === 'md';
            $locked = false;
            if ($ismarkdown) {
                $relativepath = $result['directory'] === '' ? $entry['name'] : $result['directory'] . '/' . $entry['name'];
                $content = context_files::read_content_pointer_aware($relativepath);
                $locked = $content !== null
                    && \local_kurspilot\personal_data::is_marked($content['content'])
                    && !\local_kurspilot\personal_data::allowed();
            }
            $entries[] = $entry + ['locked' => $locked];
        }

        return [
            'path' => $result['directory'],
            'entries' => $entries,
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
