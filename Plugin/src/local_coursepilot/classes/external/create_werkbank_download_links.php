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
use local_coursepilot\material_files;
use local_coursepilot\werkbank_ticket;

defined('MOODLE_INTERNAL') || die();

/**
 * Rein lesendes Werkzeug (Issue #501, Spec #486 §13): stellt fuer eine Liste
 * von Werkbankdateien je ein Einmal-Downloadticket aus, ueber das ein Client
 * mit Shell (curl) die Originalbytes ohne OAuth-Bearer-Header abrufen kann -
 * z.B. fuer den Merkzettelpunkt "Werkbank -> Bestand".
 *
 * Keine fertige Abrufzeile - nur URL, Name, Groesse und SHA-1 je Datei
 * (SHA-1 ist Moodles `contenthash`, siehe {@see \local_coursepilot\material_files::read_content()}).
 * Die eigentliche Ticket-Choreografie (Bindung, Gueltigkeit, Pruefungen beim
 * Abruf) liegt in {@see \local_coursepilot\werkbank_ticket}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class create_werkbank_download_links extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'paths' => new external_multiple_structure(
                new external_value(PARAM_PATH, 'Dateipfad relativ zur Werkbankwurzel, z.B. "blatt.pdf"')
            ),
        ]);
    }

    /**
     * @param string[] $paths
     * @return array
     * @throws \moodle_exception invalidmaterialpath, materialfilenotfound
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(array $paths): array {
        $params = self::validate_parameters(self::execute_parameters(), ['paths' => $paths]);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        $links = [];
        foreach ($params['paths'] as $path) {
            $links[] = werkbank_ticket::issue($path);
        }

        return [
            'links' => $links,
            // Fuer den access_log-Eintrag (Spec 0018 §9.2), wie delete_material_files:
            // alle betroffenen Pfade in einem Eintrag statt eines Sonderfalls je Datei.
            'path' => implode(', ', array_column($links, 'path')),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'links' => new external_multiple_structure(
                new external_single_structure([
                    'path' => new external_value(PARAM_TEXT, 'Dateipfad relativ zur Werkbankwurzel'),
                    'name' => new external_value(PARAM_TEXT, 'Dateiname'),
                    'size' => new external_value(PARAM_INT, 'Dateigroesse in Byte'),
                    'sha1' => new external_value(PARAM_ALPHANUMEXT, 'SHA-1-Pruefsumme der Datei (Moodle-contenthash)'),
                    'url' => new external_value(PARAM_URL, 'Einmal-Downloadlink, 15 Minuten gueltig'),
                ])
            ),
            'path' => new external_value(PARAM_TEXT, 'Alle betroffenen Pfade, kommagetrennt (fuer den access_log)'),
        ]);
    }
}
