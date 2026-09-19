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
use local_coursepilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Legt eine Datei im Materialordner der aufrufenden Lehrkraft an oder
 * ueberschreibt sie vollstaendig (Spec 0018 §2/§4.2/§8.1, Issue #428) - die
 * eine Eintrittstuer, ueber die jede Herkunft (Chat-Anhang, spaeterer
 * Zuschnitt) den Materialordner erreicht.
 *
 * Reihenfolge ist Absicht: erst alle Absagen (Pfad, Endung, Servergroesse,
 * Gleichzeitigkeit, Quote), dann genau ein Schreibvorgang.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class upload_material_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Materialordner, z.B. "screenshot.png"'),
            'content_base64' => new external_value(PARAM_RAW, 'Dateiinhalt, base64-kodiert'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: contenthash aus dem letzten Auflisten - passt er nicht, bricht der Vorgang ab',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * @param string $path
     * @param string $contentbase64
     * @param string $expectedcontenthash
     * @return array
     * @throws \moodle_exception invalidmaterialpath, materialfiledisallowedtype,
     *         materialfiletoolarge, materialfilechanged, materialquotaexceeded
     * @throws \invalid_parameter_exception ungueltiges base64
     * @throws \required_capability_exception ohne moodle/user:manageownfiles
     */
    public static function execute(string $path, string $contentbase64, string $expectedcontenthash = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'path' => $path,
            'content_base64' => $contentbase64,
            'expected_contenthash' => $expectedcontenthash,
        ]);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        [$directory, $filename] = material_files::resolve_writable_file($params['path']);

        $content = base64_decode($params['content_base64'], true);
        if ($content === false) {
            throw new \invalid_parameter_exception('content_base64 ist kein gueltiges base64.');
        }
        $newsize = strlen($content);

        self::guard_server_size_limit($newsize);

        $existing = material_files::read_content($directory, $filename);
        $oldsize = $existing !== null ? $existing['size'] : 0;

        // Gleichzeitigkeitsschutz ohne Locks (Spec 0016 §5.3, hier
        // uebernommen): eine fehlende Datei ist ebenfalls ein Konflikt.
        if ($params['expected_contenthash'] !== ''
                && ($existing === null || $existing['contenthash'] !== $params['expected_contenthash'])) {
            throw new \moodle_exception('materialfilechanged', 'local_coursepilot', '', $params['path']);
        }

        return self::write_and_build_response($directory, $filename, $content, $oldsize, $newsize, $existing !== null);
    }

    /**
     * Schreibt die Datei und baut die Antwort (Issue #523: aus execute()
     * ausgelagert, um die Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param string $directory
     * @param string $filename
     * @param string $content
     * @param int $oldsize
     * @param int $newsize
     * @param bool $overwritten
     * @return array
     */
    private static function write_and_build_response(
        string $directory,
        string $filename,
        string $content,
        int $oldsize,
        int $newsize,
        bool $overwritten
    ): array {
        $warning = material_files::write($directory, $filename, $content, $oldsize);

        $relativepath = material_files::relative_file($directory, $filename);
        $message = $overwritten
            ? get_string('materialfileoverwritten', 'local_coursepilot', (object) [
                'path' => $relativepath,
                'before' => $oldsize,
                'after' => $newsize,
            ])
            : get_string('materialfilecreated', 'local_coursepilot', $relativepath);
        if ($warning !== null) {
            $message .= ' ' . $warning;
        }

        return [
            'path' => $relativepath,
            'created' => !$overwritten,
            'size' => $newsize,
            'message' => $message,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Aufgeloester Dateipfad, relativ zum Materialordner'),
            'created' => new external_value(PARAM_BOOL, 'true, wenn die Datei neu angelegt wurde'),
            'size' => new external_value(PARAM_INT, 'Neue Dateigroesse in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch, inkl. Quotenwarnung falls zutreffend'),
        ]);
    }

    /**
     * Weist einen Upload ab, der die Servergrenze fuer Uploads ueberschreitet
     * (Spec 0018 §8.1: keine eigene Groessengrenze, Praezedenz Spec 0017 §9 -
     * dieselbe Aufteilung testbarer Kern/Servergrenze wie
     * import_questions_xml::guard_server_size_limit()).
     *
     * @param int $bytes
     * @return void
     */
    private static function guard_server_size_limit(int $bytes): void {
        self::guard_size_against_limit($bytes, get_max_upload_file_size());
    }

    /**
     * Testbarer Kern von {@see self::guard_server_size_limit()}: $maxbytes
     * kommt vom Aufrufer, damit Tests die Schwelle setzen koennen, ohne die
     * PHP-Ini-Werte des Testcontainers zu aendern.
     *
     * @param int $bytes
     * @param int $maxbytes
     * @return void
     * @throws \moodle_exception materialfiletoolarge
     */
    private static function guard_size_against_limit(int $bytes, int $maxbytes): void {
        if ($maxbytes <= 0 || $bytes <= $maxbytes) {
            return;
        }
        throw new \moodle_exception('materialfiletoolarge', 'local_coursepilot', '', (object) [
            'size' => $bytes,
            'max' => $maxbytes,
        ]);
    }
}
