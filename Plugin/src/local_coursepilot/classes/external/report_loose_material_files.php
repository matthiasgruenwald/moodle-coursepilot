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

defined('MOODLE_INTERNAL') || die();

/**
 * Bericht ueber "lose" Materialdateien (Spec 0018 §8.2/§8.3, Issue #438):
 * jede Datei im Materialordner, deren `contenthash` in keiner
 * Aktivitaets-Filearea eines eigenen Kurses auftaucht.
 *
 * "Verwendet" wird nie geraten: Moodles Dateipool ist contenthash-basiert
 * und das Feld indiziert, deshalb ein Abgleich je Aufruf statt einer neuen
 * Tabelle, die driften koennte (Spec 0018 §8.2). Ein Zuschnitt hat einen
 * anderen contenthash als sein Original - das Original erscheint danach
 * bewusst als lose, das ist die richtige Auskunft, nicht ein Fehler dieses
 * Berichts.
 *
 * Gefragt (ob geloescht werden soll) wird nicht hier - das ist Sache des
 * Skills (Spec 0018 §8.3), dieser Bericht liefert nur die Fakten.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class report_loose_material_files extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        $usedcontenthashes = material_files::used_contenthashes();

        $entries = [];
        $totalsize = 0;
        $now = time();
        foreach (material_files::list_entries_recursive(material_files::resolve_directory('')) as $file) {
            if (in_array($file['contenthash'], $usedcontenthashes, true)) {
                continue;
            }
            $size = $file['size'];
            $totalsize += $size;
            $entries[] = [
                'path' => material_files::relative_file($file['directory'], $file['name']),
                'size' => $size,
                'age_days' => (int) floor(max(0, $now - $file['timecreated']) / DAYSECS),
                'contenthash' => $file['contenthash'],
            ];
        }

        $remaining = material_files::remaining_quota();

        return [
            'files' => $entries,
            'total_size' => $totalsize,
            'remaining_quota_mb' => $remaining === null ? null : format_float($remaining / 1048576, 1),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'files' => new external_multiple_structure(
                new external_single_structure([
                    'path' => new external_value(PARAM_TEXT, 'Dateipfad relativ zum Materialordner'),
                    'size' => new external_value(PARAM_INT, 'Dateigroesse in Byte'),
                    'age_days' => new external_value(PARAM_INT, 'Alter in Tagen seit Anlage'),
                    'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Inhaltspruefsumme'),
                ])
            ),
            'total_size' => new external_value(PARAM_INT, 'Summe der Groesse aller losen Dateien in Byte'),
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
