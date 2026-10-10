<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\gd_support;
use local_coursepilot\material_area;
use local_coursepilot\material_files;

defined('MOODLE_INTERNAL') || die();

/**
 * Crop a material image (Spec 0018 §5, #431). A separate endpoint rather
 * than an upload parameter because the source is already stored. If the
 * crop needs adjustment, another attempt costs one call rather than an upload.
 *
 * Coordinates are relative (0-1) to preview_material_file's preview (§3.1),
 * but cropping uses the full-resolution original. Preview dimensions remain
 * a server decision.
 *
 * Store provenance in Moodle's existing source field of the target file;
 * no extra field or table (§5, §8.2). Core expects a serialized object rather
 * than a raw string; see unserialize_object() in moodlelib.php.
 *
 * The published contract directly uses location instead of the legacy ort
 * (#572, Spec 0025 §A), via material_files::location_parameter().
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class crop_material_file extends external_api {
    /** @var int JPEG quality for a crop whose target extension is jpg/jpeg. */
    private const JPEG_QUALITY = 85;

    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sourcepath' => new external_value(PARAM_PATH, 'Source material image path, relative to the material folder'),
            'targetpath' => new external_value(PARAM_PATH, 'Target crop path, relative to the material folder, e.g. "crop.png"'),
            'x0' => new external_value(PARAM_FLOAT, 'Left crop edge, relative 0-1'),
            'y0' => new external_value(PARAM_FLOAT, 'Top crop edge, relative 0-1'),
            'x1' => new external_value(PARAM_FLOAT, 'Right crop edge, relative 0-1'),
            'y1' => new external_value(PARAM_FLOAT, 'Bottom crop edge, relative 0-1'),
            'expected_contenthash' => new external_value(
                PARAM_ALPHANUMEXT,
                'Optional: target contenthash from the last listing; a mismatch aborts the operation',
                VALUE_DEFAULT,
                ''
            ),
            'location' => material_files::location_parameter(),
        ]);
    }

    /**
     * Runs the crop material file tool.
     *
     * @param string $sourcepath
     * @param string $targetpath
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @param string $expectedcontenthash
     * @param string $location
     * @return array
     * @throws \moodle_exception invalidmaterialpath, invalidmateriallocation,
     *         materialpathiscontext, materialfilenotfound,
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
        string $location = material_files::LOCATION_STORE
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'sourcepath' => $sourcepath,
            'location' => $location,
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
     * Read and validate the source (#523: extracted from execute() to keep
     * the function below 50 lines).
     *
     * @param array $params Validated execute() parameters.
     * @return array{0: array{content: string, path: string, size: int, timemodified: int}, 1: string}
     *         [source content, resolved source path]
     */
    private static function resolve_source(array $params): array {
        $sourcestored = material_area::read_for_location($params['location'], $params['sourcepath']);
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
     * Resolve the target and check concurrency protection (#523: extracted
     * from execute()).
     *
     * @param array $params Validated execute() parameters.
     * @return array{0: string, 1: string, 2: string, 3: ?array} [target directory, target filename,
     *         target extension, existing file or null]
     */
    private static function resolve_target(array $params): array {
        [$targetdir, $targetfilename] = material_files::resolve_writable_file($params['targetpath']);
        $targetextension = strtolower(pathinfo($targetfilename, PATHINFO_EXTENSION));
        if (!in_array($targetextension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            throw new \moodle_exception('materialcropoutputunsupported', 'local_coursepilot', '', $targetextension);
        }

        // Check target concurrency before cropping, like upload_material_file:
        // perform all rejection checks before the single expensive operation.
        $existing = material_files::read_content($targetdir, $targetfilename);
        if (
            $params['expected_contenthash'] !== ''
                && ($existing === null || $existing['contenthash'] !== $params['expected_contenthash'])
        ) {
            throw new \moodle_exception('materialfilechanged', 'local_coursepilot', '', $params['targetpath']);
        }

        return [$targetdir, $targetfilename, $targetextension, $existing];
    }

    /**
     * Crop, write the target and build the response (#523: extracted from execute()).
     *
     * @param array $params Validated execute() parameters.
     * @param array $sourcestored
     * @phpstan-param array{content:string,path:string,size:int,timemodified:int} $sourcestored
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

        // Moodle expects an empty string or serialized object in source (see
        // unserialize_object() in moodlelib.php). A raw path causes unserialize()
        // warnings on later core file-manager/draft access (#431 follow-up).
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
     * Load the source as a GD image and crop it (#523: extracted from
     * crop_and_write() to keep the function below 50 lines).
     *
     * @param array $params Validated execute() parameters.
     * @param array $sourcestored
     * @phpstan-param array{content:string,path:string,size:int,timemodified:int} $sourcestored
     * @param string $sourcerelative
     * @param string $targetextension
     * @return array{0: string, 1: int, 2: int} [content, width, height]
     */
    private static function load_and_crop(
        array $params,
        array $sourcestored,
        string $sourcerelative,
        string $targetextension
    ): array {
        $source = @imagecreatefromstring($sourcestored['content']);
        if ($source === false) {
            // The extension names an image, but GD cannot read the corrupt bytes.
            // Explain unavailability in the same way as preview_material_file.
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
     * Build the crop response (#523: extracted from crop_and_write() to keep
     * the function below 50 lines).
     *
     * @param array $params Validated execute() parameters.
     * @param array $sourcestored
     * @phpstan-param array{content:string,path:string,size:int,timemodified:int} $sourcestored
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
            // Return location plus size/modification-time fingerprint, not just a path
            // (#495). Material storage has no contenthash, so this is the only evidence
            // the AI can retain of exactly which source was cropped.
            'source' => self::describe_source($params['location'], $sourcerelative, $sourcestored),
            'created' => $existing === null,
            'width' => $width,
            'height' => $height,
            'size' => $newsize,
            'message' => $message,
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Resolved crop target path, relative to the material folder'),
            'source' => new external_value(
                PARAM_TEXT,
                'Location and fingerprint of the source file: "<location>:<path> (<size> byte, changed <timestamp>)" - '
                    . 'the material store carries no contenthash, this fingerprint replaces it here'
            ),
            'created' => new external_value(PARAM_BOOL, 'true if the crop was newly created'),
            'width' => new external_value(PARAM_INT, 'Crop width in pixels, computed from the original'),
            'height' => new external_value(PARAM_INT, 'Crop height in pixels, computed from the original'),
            'size' => new external_value(PARAM_INT, 'Crop size in bytes'),
            'message' => new external_value(PARAM_RAW, 'Localized teacher-facing change message, including a quota warning when applicable'),
        ]);
    }

    /**
     * Source location and fingerprint (size and modification time, #495).
     * External existing files have no contenthash to compare (Spec #486 §7),
     * so this fingerprint replaces it.
     *
     * @param string $location
     * @param string $path
     * @param array $stored
     * @phpstan-param array{size:int,timemodified:int} $stored
     * @return string
     */
    private static function describe_source(string $location, string $path, array $stored): string {
        return sprintf(
            '%s:%s (%d bytes, modified %s)',
            $location,
            $path,
            $stored['size'],
            gmdate('Y-m-d\TH:i:s\Z', $stored['timemodified'])
        );
    }

    /**
     * Relative coordinates must lie in [0,1] and define an area greater than
     * zero. Do not silently clip them (Spec 0018 acceptance criterion).
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
     * Crop the full-resolution original (Spec 0018 §3.1) and encode it for the
     * target extension.
     *
     * @param \GdImage $source
     * @param int $origwidth
     * @param int $origheight
     * @param float $x0
     * @param float $y0
     * @param float $x1
     * @param float $y1
     * @param string $targetextension
     * @return array{0: string, 1: int, 2: int} [image content, width, height]
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
     * Convert relative coordinates to a pixel rectangle (#523: extracted from crop()).
     *
     * @param int $origwidth The origwidth.
     * @param int $origheight The origheight.
     * @param float $x0 The x0.
     * @param float $y0 The y0.
     * @param float $x1 The x1.
     * @param float $y1 The y1.
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
        // ponytail: Rounding can collapse nearby relative coordinates to a zero-
        // pixel rectangle (e.g. x0=0.499/x1=0.501 on a 10px image). max(1, ...)
        // clamps this to one pixel rather than throwing. Validated relative area
        // greater than zero (guard_coordinates()) is the acceptance criterion;
        // this rounding case affects very small originals. Add a dedicated error
        // only if it occurs in practice.
        $width = max(1, min($origwidth - $px0, $px1 - $px0));
        $height = max(1, min($origheight - $py0, $py1 - $py0));

        return [$px0, $py0, $width, $height];
    }

    /**
     * Create the target canvas and copy the crop into it (#523: extracted from crop()).
     *
     * @param \GdImage $source The source.
     * @param int $px0 The px0.
     * @param int $py0 The py0.
     * @param int $width The width.
     * @param int $height The height.
     * @param string $targetextension The targetextension.
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
            // JPEG has no transparency; use a white canvas as in image_preview::build().
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
     * Write the canvas to the output buffer in the target format (#523: extracted from crop()).
     *
     * @param \GdImage $canvas The canvas.
     * @param string $targetextension The targetextension.
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
