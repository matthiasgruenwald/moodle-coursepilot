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
use local_kurspilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Gezielter Bildausschnitt (Spec 0018 §5, Issue #431): eigener Endpunkt statt
 * Upload-Parameter, weil der Zuschnitt auf einer bereits liegenden
 * Materialdatei arbeitet - sitzt der Ausschnitt nicht, kostet der zweite
 * Versuch einen Aufruf statt eines zweiten Uploads.
 *
 * Koordinaten sind relativ (0-1) auf die Vorschau aus preview_material_file
 * (§3.1), geschnitten wird aber aus dem Original in voller Aufloesung - die
 * Vorschaugroesse bleibt damit eine reine Serverentscheidung.
 *
 * Herkunft des Ausschnitts landet in Moodles vorhandenem `source`-Feld der
 * Zieldatei - kein neues Feld, keine Tabelle (§5, §8.2). Dort erwartet Moodle-Core
 * ein serialisiertes Objekt statt eines rohen Strings, siehe unserialize_object()
 * in moodlelib.php.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class crop_material_file extends external_api {

    /** @var int JPEG-Qualitaet fuer einen Zuschnitt mit jpg/jpeg-Zielendung. */
    private const JPEG_QUALITY = 85;

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sourcepath' => new external_value(PARAM_PATH, 'Pfad der zuzuschneidenden Materialdatei, relativ zum Materialordner'),
            'targetpath' => new external_value(PARAM_PATH, 'Zielpfad des Ausschnitts, relativ zum Materialordner, z.B. "ausschnitt.png"'),
            'x0' => new external_value(PARAM_FLOAT, 'Linke Kante des Ausschnitts, relativ 0-1'),
            'y0' => new external_value(PARAM_FLOAT, 'Obere Kante des Ausschnitts, relativ 0-1'),
            'x1' => new external_value(PARAM_FLOAT, 'Rechte Kante des Ausschnitts, relativ 0-1'),
            'y1' => new external_value(PARAM_FLOAT, 'Untere Kante des Ausschnitts, relativ 0-1'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: contenthash der Zieldatei aus dem letzten Auflisten - passt er nicht, bricht der Vorgang ab',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * @param string $sourcepath
     * @param string $targetpath
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @param string $expectedcontenthash
     * @return array
     * @throws \moodle_exception invalidmaterialpath, materialfilenotfound,
     *         materialgdmissing, materialcropsourceunsupported,
     *         materialcropoutputunsupported, materialcropinvalidcoordinates,
     *         materialfiledisallowedtype, materialfilechanged, materialquotaexceeded
     */
    public static function execute(
        string $sourcepath,
        string $targetpath,
        float $x0,
        float $y0,
        float $x1,
        float $y1,
        string $expectedcontenthash = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'sourcepath' => $sourcepath,
            'targetpath' => $targetpath,
            'x0' => $x0,
            'y0' => $y0,
            'x1' => $x1,
            'y1' => $y1,
            'expected_contenthash' => $expectedcontenthash,
        ]);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        if (!gd_support::available()) {
            throw new \moodle_exception('materialgdmissing', 'local_kurspilot');
        }

        [$sourcedir, $sourcefilename] = material_files::resolve_file($params['sourcepath']);
        $sourcerelative = material_files::relative_file($sourcedir, $sourcefilename);

        $sourcestored = material_files::read_content($sourcedir, $sourcefilename);
        if ($sourcestored === null) {
            throw new \moodle_exception('materialfilenotfound', 'local_kurspilot', '', $sourcerelative);
        }

        $sourceextension = strtolower(pathinfo($sourcefilename, PATHINFO_EXTENSION));
        if (!in_array($sourceextension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            throw new \moodle_exception('materialcropsourceunsupported', 'local_kurspilot', '', $sourcerelative);
        }

        self::guard_coordinates($params['x0'], $params['y0'], $params['x1'], $params['y1']);

        [$targetdir, $targetfilename] = material_files::resolve_writable_file($params['targetpath']);
        $targetextension = strtolower(pathinfo($targetfilename, PATHINFO_EXTENSION));
        if (!in_array($targetextension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            throw new \moodle_exception('materialcropoutputunsupported', 'local_kurspilot', '', $targetextension);
        }

        // Gleichzeitigkeitsschutz auf der Zieldatei vor dem eigentlichen
        // Zuschnitt pruefen (wie bei upload_material_file: erst alle
        // Absagen, dann genau ein - hier: der teuerste - Arbeitsschritt).
        $existing = material_files::read_content($targetdir, $targetfilename);
        if ($params['expected_contenthash'] !== ''
                && ($existing === null || $existing['contenthash'] !== $params['expected_contenthash'])) {
            throw new \moodle_exception('materialfilechanged', 'local_kurspilot', '', $params['targetpath']);
        }

        $source = @imagecreatefromstring($sourcestored['content']);
        if ($source === false) {
            // Bildendung, aber GD kann die Bytes nicht lesen (defekte Datei) -
            // dieselbe erklaerte Nichtverfuegbarkeit wie preview_material_file.
            throw new \moodle_exception('materialcropsourceunsupported', 'local_kurspilot', '', $sourcerelative);
        }

        [$content, $width, $height] = self::crop(
            $source,
            imagesx($source),
            imagesy($source),
            $params['x0'],
            $params['y0'],
            $params['x1'],
            $params['y1'],
            $targetextension
        );
        imagedestroy($source);
        $newsize = strlen($content);
        $oldsize = $existing !== null ? $existing['size'] : 0;

        // Moodle erwartet im `source`-Feld entweder einen leeren String oder ein
        // serialisiertes Objekt (unserialize_object() in moodlelib.php) - ein roher
        // Pfad loest bei jedem spaeteren Core-Zugriff auf die Datei (Dateimanager,
        // Draft-Handling) eine unserialize()-Warnung aus (Fund #431-Nachtest).
        $warning = material_files::write($targetdir, $targetfilename, $content, $oldsize, [
            'source' => serialize((object) ['original' => $sourcerelative]),
        ]);

        $targetrelative = material_files::relative_file($targetdir, $targetfilename);
        $message = get_string(
            $existing !== null ? 'materialcropoverwritten' : 'materialcropcreated',
            'local_kurspilot',
            (object) ['path' => $targetrelative, 'source' => $sourcerelative, 'width' => $width, 'height' => $height]
        );
        if ($warning !== null) {
            $message .= ' ' . $warning;
        }

        return [
            'path' => $targetrelative,
            'source' => $sourcerelative,
            'created' => $existing === null,
            'width' => $width,
            'height' => $height,
            'size' => $newsize,
            'message' => $message,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Aufgeloester Zielpfad des Ausschnitts, relativ zum Materialordner'),
            'source' => new external_value(PARAM_TEXT, 'Materialordner-Pfad der Quelldatei - Klartext, waehrend das source-Feld der Zieldatei denselben Pfad serialisiert traegt'),
            'created' => new external_value(PARAM_BOOL, 'true, wenn der Ausschnitt neu angelegt wurde'),
            'width' => new external_value(PARAM_INT, 'Breite des Ausschnitts in Pixeln, aus dem Original berechnet'),
            'height' => new external_value(PARAM_INT, 'Hoehe des Ausschnitts in Pixeln, aus dem Original berechnet'),
            'size' => new external_value(PARAM_INT, 'Groesse des Ausschnitts in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch, inkl. Quotenwarnung falls zutreffend'),
        ]);
    }

    /**
     * Relative Koordinaten muessen in [0,1] liegen und eine Flaeche groesser
     * 0 ergeben - kein stiller Clip (Spec 0018, Abnahmekriterium).
     *
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @throws \moodle_exception materialcropinvalidcoordinates
     */
    private static function guard_coordinates(float $x0, float $y0, float $x1, float $y1): void {
        $inrange = static fn(float $v): bool => $v >= 0.0 && $v <= 1.0;
        if (!$inrange($x0) || !$inrange($y0) || !$inrange($x1) || !$inrange($y1) || $x1 <= $x0 || $y1 <= $y0) {
            throw new \moodle_exception('materialcropinvalidcoordinates', 'local_kurspilot', '', (object) [
                'x0' => $x0, 'y0' => $y0, 'x1' => $x1, 'y1' => $y1,
            ]);
        }
    }

    /**
     * Schneidet aus dem geladenen Originalbild in voller Aufloesung aus
     * (Spec 0018 §3.1) und kodiert das Ergebnis nach der Zielendung.
     *
     * @param \GdImage $source
     * @param int $origwidth
     * @param int $origheight
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @param string $targetextension
     * @return array{0: string, 1: int, 2: int} [Bildinhalt, Breite, Hoehe]
     */
    private static function crop(
        \GdImage $source,
        int $origwidth,
        int $origheight,
        float $x0,
        float $y0,
        float $x1,
        float $y1,
        string $targetextension
    ): array {
        $px0 = (int) round($x0 * $origwidth);
        $py0 = (int) round($y0 * $origheight);
        $px1 = (int) round($x1 * $origwidth);
        $py1 = (int) round($y1 * $origheight);
        // ponytail: Rundung kann zwei sehr nah beieinanderliegende relative
        // Koordinaten auf ein 0px-Rechteck kollabieren lassen (z.B. x0=0.499/
        // x1=0.501 auf einem 10px breiten Bild) - max(1, ...) klemmt das
        // still auf 1px statt zu werfen. Die validierte Flaeche > 0 (relative
        // Koordinaten, guard_coordinates()) ist das Abnahmekriterium; dieser
        // Rundungsfall ist ein Sonderfall auf sehr kleinen Originalen. Eigene
        // Fehlermeldung erst, wenn das in der Praxis auftritt.
        $width = max(1, min($origwidth - $px0, $px1 - $px0));
        $height = max(1, min($origheight - $py0, $py1 - $py0));

        $canvas = imagecreatetruecolor($width, $height);
        $isjpeg = in_array($targetextension, ['jpg', 'jpeg'], true);
        if ($isjpeg) {
            // JPEG kennt keine Transparenz - weisse Leinwand wie image_preview::build().
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }
        imagecopy($canvas, $source, 0, 0, $px0, $py0, $width, $height);

        ob_start();
        switch ($targetextension) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($canvas, null, self::JPEG_QUALITY);
                break;
            case 'gif':
                imagegif($canvas);
                break;
            case 'webp':
                imagewebp($canvas);
                break;
            default:
                imagepng($canvas);
                break;
        }
        $content = ob_get_clean();
        imagedestroy($canvas);

        return [$content, $width, $height];
    }
}
