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
use local_coursepilot\gd_support;
use local_coursepilot\material_files;

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
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
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
            'ort' => material_files::ort_parameter(),
        ]);
    }

    /**
     * @param string $sourcepath
     * @param string $ort
     * @param string $targetpath
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @param string $expectedcontenthash
     * @return array
     * @throws \moodle_exception invalidmaterialpath, invalidmaterialort,
     *         materialpathiskontext, materialfilenotfound,
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
        string $expectedcontenthash = '',
        string $ort = material_files::ORT_BESTAND
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'sourcepath' => $sourcepath,
            'ort' => $ort,
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
            throw new \moodle_exception('materialgdmissing', 'local_coursepilot');
        }

        [$sourcestored, $sourcerelative] = self::resolve_source($params);
        self::guard_coordinates($params['x0'], $params['y0'], $params['x1'], $params['y1']);
        [$targetdir, $targetfilename, $targetextension, $existing] = self::resolve_target($params);

        return self::crop_and_write(
            $params,
            $sourcestored,
            $sourcerelative,
            $targetdir,
            $targetfilename,
            $targetextension,
            $existing
        );
    }

    /**
     * Liest und prueft die Quelldatei (Issue #523: aus execute() ausgelagert,
     * um die Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param array $params Validierte Parameter von execute().
     * @return array{0: array{content: string, path: string, size: int, timemodified: int}, 1: string}
     *         [Quelldatei-Inhalt, aufgeloester Quellpfad]
     */
    private static function resolve_source(array $params): array {
        $sourcestored = material_files::read_content_for_ort($params['ort'], $params['sourcepath']);
        if ($sourcestored === null) {
            throw new \moodle_exception(
                'materialfilenotfound',
                'local_coursepilot',
                '',
                material_files::normalise_path($params['sourcepath'])
            );
        }
        $sourcerelative = $sourcestored['path'];
        $sourceextension = strtolower(pathinfo(basename($sourcerelative), PATHINFO_EXTENSION));
        if (!in_array($sourceextension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            throw new \moodle_exception('materialcropsourceunsupported', 'local_coursepilot', '', $sourcerelative);
        }

        return [$sourcestored, $sourcerelative];
    }

    /**
     * Loest die Zieldatei auf und prueft den Gleichzeitigkeitsschutz (Issue
     * #523: aus execute() ausgelagert).
     *
     * @param array $params Validierte Parameter von execute().
     * @return array{0: string, 1: string, 2: string, 3: ?array} [Zielordner, Zieldateiname,
     *         Zielendung, bestehende Datei oder null]
     */
    private static function resolve_target(array $params): array {
        [$targetdir, $targetfilename] = material_files::resolve_writable_file($params['targetpath']);
        $targetextension = strtolower(pathinfo($targetfilename, PATHINFO_EXTENSION));
        if (!in_array($targetextension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            throw new \moodle_exception('materialcropoutputunsupported', 'local_coursepilot', '', $targetextension);
        }

        // Gleichzeitigkeitsschutz auf der Zieldatei vor dem eigentlichen
        // Zuschnitt pruefen (wie bei upload_material_file: erst alle
        // Absagen, dann genau ein - hier: der teuerste - Arbeitsschritt).
        $existing = material_files::read_content($targetdir, $targetfilename);
        if ($params['expected_contenthash'] !== ''
                && ($existing === null || $existing['contenthash'] !== $params['expected_contenthash'])) {
            throw new \moodle_exception('materialfilechanged', 'local_coursepilot', '', $params['targetpath']);
        }

        return [$targetdir, $targetfilename, $targetextension, $existing];
    }

    /**
     * Schneidet zu, schreibt die Zieldatei und baut die Antwort (Issue #523:
     * aus execute() ausgelagert).
     *
     * @param array $params Validierte Parameter von execute().
     * @param array{content: string, path: string, size: int, timemodified: int} $sourcestored
     * @param string $sourcerelative
     * @param string $targetdir
     * @param string $targetfilename
     * @param string $targetextension
     * @param ?array $existing
     * @return array
     */
    private static function crop_and_write(
        array $params,
        array $sourcestored,
        string $sourcerelative,
        string $targetdir,
        string $targetfilename,
        string $targetextension,
        ?array $existing
    ): array {
        [$content, $width, $height] = self::load_and_crop($params, $sourcestored, $sourcerelative, $targetextension);
        $newsize = strlen($content);
        $oldsize = $existing !== null ? $existing['size'] : 0;

        // Moodle erwartet im `source`-Feld entweder einen leeren String oder ein
        // serialisiertes Objekt (unserialize_object() in moodlelib.php) - ein roher
        // Pfad loest bei jedem spaeteren Core-Zugriff auf die Datei (Dateimanager,
        // Draft-Handling) eine unserialize()-Warnung aus (Fund #431-Nachtest).
        $warning = material_files::write($targetdir, $targetfilename, $content, $oldsize, [
            'source' => serialize((object) ['original' => $sourcerelative]),
        ]);

        return self::build_crop_response(
            $params,
            $sourcestored,
            $sourcerelative,
            $targetdir,
            $targetfilename,
            $existing,
            $width,
            $height,
            $newsize,
            $warning
        );
    }

    /**
     * Laedt die Quelldatei als GD-Bild und schneidet zu (Issue #523: aus
     * crop_and_write() ausgelagert, um die Funktion unter der
     * 50-Zeilen-Grenze zu halten).
     *
     * @param array $params Validierte Parameter von execute().
     * @param array{content: string, path: string, size: int, timemodified: int} $sourcestored
     * @param string $sourcerelative
     * @param string $targetextension
     * @return array{0: string, 1: int, 2: int} [Inhalt, Breite, Hoehe]
     */
    private static function load_and_crop(
        array $params,
        array $sourcestored,
        string $sourcerelative,
        string $targetextension
    ): array {
        $source = @imagecreatefromstring($sourcestored['content']);
        if ($source === false) {
            // Bildendung, aber GD kann die Bytes nicht lesen (defekte Datei) -
            // dieselbe erklaerte Nichtverfuegbarkeit wie preview_material_file.
            throw new \moodle_exception('materialcropsourceunsupported', 'local_coursepilot', '', $sourcerelative);
        }

        $result = self::crop(
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

        return $result;
    }

    /**
     * Baut die Zuschnitt-Antwort (Issue #523: aus crop_and_write()
     * ausgelagert, um die Funktion unter der 50-Zeilen-Grenze zu halten).
     *
     * @param array $params Validierte Parameter von execute().
     * @param array{content: string, path: string, size: int, timemodified: int} $sourcestored
     * @param string $sourcerelative
     * @param string $targetdir
     * @param string $targetfilename
     * @param ?array $existing
     * @param int $width
     * @param int $height
     * @param int $newsize
     * @param ?string $warning
     * @return array
     */
    private static function build_crop_response(
        array $params,
        array $sourcestored,
        string $sourcerelative,
        string $targetdir,
        string $targetfilename,
        ?array $existing,
        int $width,
        int $height,
        int $newsize,
        ?string $warning
    ): array {
        $targetrelative = material_files::relative_file($targetdir, $targetfilename);
        $message = get_string(
            $existing !== null ? 'materialcropoverwritten' : 'materialcropcreated',
            'local_coursepilot',
            (object) ['path' => $targetrelative, 'source' => $sourcerelative, 'width' => $width, 'height' => $height]
        );
        if ($warning !== null) {
            $message .= ' ' . $warning;
        }

        return [
            'path' => $targetrelative,
            // Ort + Pruefmerkmal (Groesse, Aenderungszeit) statt eines
            // reinen Pfads (Issue #495): der Materialbestand traegt keinen
            // contenthash, dieser Fingerabdruck ist deshalb das einzige, was
            // die KI ueber "was genau wurde zugeschnitten" mitnehmen kann.
            'source' => self::describe_source($params['ort'], $sourcerelative, $sourcestored),
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
            'source' => new external_value(
                PARAM_TEXT,
                'Ort und Pruefmerkmal der Quelldatei: "<ort>:<pfad> (<Groesse> Byte, geändert <Zeitpunkt>)" - '
                    . 'der Materialbestand traegt keinen contenthash, dieses Pruefmerkmal ersetzt ihn hier'
            ),
            'created' => new external_value(PARAM_BOOL, 'true, wenn der Ausschnitt neu angelegt wurde'),
            'width' => new external_value(PARAM_INT, 'Breite des Ausschnitts in Pixeln, aus dem Original berechnet'),
            'height' => new external_value(PARAM_INT, 'Hoehe des Ausschnitts in Pixeln, aus dem Original berechnet'),
            'size' => new external_value(PARAM_INT, 'Groesse des Ausschnitts in Byte'),
            'message' => new external_value(PARAM_RAW, 'Aenderungsmeldung in Lehrkraft-Deutsch, inkl. Quotenwarnung falls zutreffend'),
        ]);
    }

    /**
     * "Ort und Pruefmerkmal (Groesse und Aenderungszeit)" der Quelldatei
     * (Issue #495) - fuer eine externe Bestandsdatei gibt es keinen
     * contenthash zum Vergleichen (Spec #486 §7: "contenthash bleibt leer"),
     * dieser Fingerabdruck ist der Ersatz.
     *
     * @param string $ort
     * @param string $path
     * @param array{size: int, timemodified: int} $stored
     * @return string
     */
    private static function describe_source(string $ort, string $path, array $stored): string {
        return sprintf(
            '%s:%s (%d Byte, geändert %s)',
            $ort,
            $path,
            $stored['size'],
            gmdate('Y-m-d\TH:i:s\Z', $stored['timemodified'])
        );
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
            throw new \moodle_exception('materialcropinvalidcoordinates', 'local_coursepilot', '', (object) [
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
        [$px0, $py0, $width, $height] = self::pixel_rect($origwidth, $origheight, $x0, $y0, $x1, $y1);

        $canvas = self::render_canvas($source, $px0, $py0, $width, $height, $targetextension);

        ob_start();
        self::output_canvas($canvas, $targetextension);
        $content = ob_get_clean();
        imagedestroy($canvas);

        return [$content, $width, $height];
    }

    /**
     * Rechnet die relativen Koordinaten auf ein Pixel-Rechteck um (Issue
     * #523: aus crop() ausgelagert).
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [px0, py0, width, height]
     */
    private static function pixel_rect(
        int $origwidth,
        int $origheight,
        float $x0,
        float $y0,
        float $x1,
        float $y1
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

        return [$px0, $py0, $width, $height];
    }

    /**
     * Baut die Ziel-Leinwand und kopiert den Ausschnitt hinein (Issue #523:
     * aus crop() ausgelagert).
     */
    private static function render_canvas(
        \GdImage $source,
        int $px0,
        int $py0,
        int $width,
        int $height,
        string $targetextension
    ): \GdImage {
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

        return $canvas;
    }

    /**
     * Gibt die Leinwand im Zielformat auf den Output-Buffer aus (Issue #523:
     * aus crop() ausgelagert).
     */
    private static function output_canvas(\GdImage $canvas, string $targetextension): void {
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
    }
}
