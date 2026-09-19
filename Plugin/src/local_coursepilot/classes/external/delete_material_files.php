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
 * Loeschweg fuer den Aufraeumbericht (Spec 0018 §8.3, Issue #438): entfernt
 * genau die uebergebenen Materialordner-Pfade, nichts darueber hinaus.
 *
 * Kein automatisches Loeschen, keine Altersregel als Loeschgrund - die
 * Liste kommt immer explizit vom Aufrufer (der Skill fragt vorher nach,
 * Spec 0018 §8.3). Ob eine Datei "lose" ist, entscheidet dieser Endpunkt
 * nicht selbst noch einmal - das war {@see report_loose_material_files}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class delete_material_files extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'paths' => new external_multiple_structure(
                new external_value(PARAM_PATH, 'Dateipfad relativ zum Materialordner, z.B. "screenshot.png"')
            ),
        ]);
    }

    /**
     * @param string[] $paths
     * @return array
     * @throws \moodle_exception invalidmaterialpath, materialdeletefilenotfound
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(array $paths): array {
        $params = self::validate_parameters(self::execute_parameters(), ['paths' => $paths]);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        // Erst alle Dateien aufloesen (jeder fehlende Pfad bricht komplett
        // ab), dann erst loeschen - kein Teilerfolg bei einem Tippfehler in
        // der Liste. Beides ueber den Anker (Issue #539, material_area::read()/
        // delete() ueber den storage_port-Adapter), statt direkt ueber
        // material_files/storage_anchor.
        $targets = [];
        foreach ($params['paths'] as $path) {
            $info = material_area::read($path);
            if ($info === null) {
                throw new \moodle_exception(
                    'materialdeletefilenotfound',
                    'local_coursepilot',
                    '',
                    material_files::normalise_path($path)
                );
            }
            $targets[] = [$path, $info['size']];
        }

        $deleted = [];
        $freedbytes = 0;
        foreach ($targets as [$path, $size]) {
            material_area::delete($path);
            $deleted[] = material_files::normalise_path($path);
            $freedbytes += $size;
        }

        return [
            'deleted' => $deleted,
            'freed_bytes' => $freedbytes,
            // Fuer den access_log-Eintrag (Spec 0018 §9.2): der Dispatcher
            // protokolliert genau diesen Schluessel, hier alle geloeschten
            // Pfade in einem Eintrag statt eines Sonderfalls je Datei.
            'path' => implode(', ', $deleted),
            'message' => get_string('materialfilesdeleted', 'local_coursepilot', (object) [
                'count' => count($deleted),
                'freed' => format_float($freedbytes / 1048576, 1),
            ]),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Geloeschter Dateipfad, relativ zum Materialordner')
            ),
            'freed_bytes' => new external_value(PARAM_INT, 'Freigewordener Speicherplatz in Byte'),
            'path' => new external_value(PARAM_TEXT, 'Alle geloeschten Pfade, kommagetrennt (fuer den access_log)'),
            'message' => new external_value(PARAM_RAW, 'Erfolgsmeldung in Lehrkraft-Deutsch'),
        ]);
    }
}
