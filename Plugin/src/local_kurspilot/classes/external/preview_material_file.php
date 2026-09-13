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
use core_external\external_single_structure;
use core_external\external_value;
use local_kurspilot\gd_support;
use local_kurspilot\image_preview;
use local_kurspilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Liefert die Bildvorschau einer Materialdatei (Spec 0018 §3, Issue #430):
 * laengste Kante 768px, JPEG. Das Modell soll den Inhalt tatsaechlich sehen
 * koennen (Ausschnitt waehlen, Alt-Text formulieren) - dazu haengt der
 * Dispatcher an eine erfolgreiche Vorschau einen MCP-Bildblock an
 * (image_base64 + mimetype, siehe dispatcher::handle_tools_call()).
 *
 * Eine Nicht-Bilddatei ist kein Fehler (Spec 0018 §3, Abnahmekriterium):
 * "available" => false mit erklaerender Meldung statt Ausnahme. Fehlt GD,
 * ist die Vorschau dagegen ganz gesperrt und wirft (Spec 0018 §3.3) - das
 * betrifft ausschliesslich diesen und den spaeteren Zuschnitt-Endpunkt,
 * Hochladen/Einbetten bleiben unberuehrt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_material_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Dateipfad relativ zum Materialordner, z.B. "screenshot.png"'),
            'ort' => material_files::ort_parameter(),
        ]);
    }

    /**
     * @param string $path
     * @param string $ort
     * @return array
     * @throws \moodle_exception invalidmaterialpath, invalidmaterialort,
     *         materialpathiskontext, materialfilenotfound, materialgdmissing,
     *         materialpreviewunsupported
     */
    public static function execute(string $path, string $ort = material_files::ORT_BESTAND): array {
        $params = self::validate_parameters(self::execute_parameters(), ['path' => $path, 'ort' => $ort]);

        $context = material_files::own_context();
        self::validate_context($context);

        $stored = material_files::read_content_for_ort($params['ort'], $params['path']);
        if ($stored === null) {
            throw new \moodle_exception('materialfilenotfound', 'local_kurspilot', '', material_files::normalise_path($params['path']));
        }
        $relativepath = $stored['path'];
        $filename = basename($relativepath);

        // Vor der Nicht-Bild-Absage: fehlt GD ganz, ist die Faehigkeit
        // gesperrt, unabhaengig vom Dateityp (Spec 0018 §3.3).
        if (!gd_support::available()) {
            throw new \moodle_exception('materialgdmissing', 'local_kurspilot');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            return self::unavailable_response(
                $relativepath,
                get_string('materialpreviewnotanimage', 'local_kurspilot', $relativepath)
            );
        }

        return self::build_preview_response($relativepath, $stored['content']);
    }

    /**
     * Baut die Antwort fuer eine erfolgreich erzeugte Vorschau, oder die
     * erklaerte Nichtverfuegbarkeit, wenn GD die Bytes nicht als Rasterbild
     * lesen kann (Issue #523: aus execute() ausgelagert, um die Funktion
     * unter der 50-Zeilen-Grenze zu halten).
     *
     * @param string $relativepath
     * @param string $content
     * @return array
     */
    private static function build_preview_response(string $relativepath, string $content): array {
        // Wie die Nicht-Bild-Absage oben: eine Datei mit Bildendung, die GD
        // trotzdem nicht als Rasterbild lesen kann (z.B. defekte Bytes,
        // getarnte SVG), ist ebenfalls kein Fehler, sondern eine erklaerte
        // Nichtverfuegbarkeit (Spec 0018 §3, Abnahmekriterium "klare Meldung
        // statt Fehler") - image_preview::build() wirft dafuer
        // materialpreviewunsupported, hier abgefangen statt durchgereicht.
        try {
            $preview = image_preview::build($content);
        } catch (\moodle_exception $e) {
            return self::unavailable_response($relativepath, get_string('materialpreviewunsupported', 'local_kurspilot'));
        }

        return [
            'path' => $relativepath,
            'available' => true,
            'message' => null,
            'mimetype' => $preview['mimetype'],
            'image_base64' => $preview['image_base64'],
            'width' => $preview['width'],
            'height' => $preview['height'],
        ];
    }

    /**
     * Die gemeinsame "nicht verfuegbar"-Antwortform (Issue #523: aus
     * execute() ausgelagert).
     *
     * @param string $relativepath
     * @param string $message
     * @return array
     */
    private static function unavailable_response(string $relativepath, string $message): array {
        return [
            'path' => $relativepath,
            'available' => false,
            'message' => $message,
            'mimetype' => null,
            'image_base64' => null,
            'width' => null,
            'height' => null,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Aufgeloester Dateipfad, relativ zum Materialordner'),
            'available' => new external_value(PARAM_BOOL, 'true, wenn eine Bildvorschau erzeugt wurde'),
            'message' => new external_value(
                PARAM_RAW,
                'Erklaerung, wenn keine Vorschau moeglich ist (z.B. Nicht-Bilddatei), sonst null',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'mimetype' => new external_value(
                PARAM_RAW,
                'MIME-Typ der Vorschau ("image/jpeg"), null wenn nicht verfuegbar',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'image_base64' => new external_value(
                PARAM_RAW,
                'Vorschau, base64-kodiertes JPEG - der Dispatcher haengt daraus den MCP-Bildblock an, '
                    . 'null wenn nicht verfuegbar',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'width' => new external_value(PARAM_INT, 'Breite der Vorschau in Pixeln, null wenn nicht verfuegbar', VALUE_DEFAULT, null, NULL_ALLOWED),
            'height' => new external_value(PARAM_INT, 'Hoehe der Vorschau in Pixeln, null wenn nicht verfuegbar', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }
}
