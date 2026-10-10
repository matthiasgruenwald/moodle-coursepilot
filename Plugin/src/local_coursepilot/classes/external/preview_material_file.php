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
use local_coursepilot\image_preview;
use local_coursepilot\material_area;
use local_coursepilot\material_files;

/**
 * Returns a JPEG preview with longest edge 768px (Spec 0018 §3, #430).
 * The model needs to see content for cropping and alt text. Dispatcher
 * adds an MCP image block using image_base64 and mimetype; see
 * dispatcher::handle_tools_call().
 *
 * Non-image files return available=false with an explanation, not an
 * exception (Spec §3 acceptance criterion). Missing GD disables preview
 * and cropping entirely with an exception (Spec §3.3); upload/embed still work.
 *
 * Direct English contract (#572, Spec 0025 §A): location replaces ort.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class preview_material_file extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'File path relative to the material store, e.g. "screenshot.png"'),
            'location' => material_files::location_parameter(),
        ]);
    }

    /**
     * Runs the preview material file tool.
     *
     * @param string $path
     * @param string $location
     * @return mixed[]
     * @throws \moodle_exception invalidmaterialpath, invalidmateriallocation,
     *         materialpathiscontext, materialfilenotfound, materialgdmissing,
     *         materialpreviewunsupported
     */
    public static function execute(string $path, string $location = material_files::LOCATION_STORE): array {
        $params = self::validate_parameters(self::execute_parameters(), ['path' => $path, 'location' => $location]);

        $context = material_files::own_context();
        self::validate_context($context);

        $stored = material_area::read_for_location($params['location'], $params['path']);
        if ($stored === null) {
            throw new \moodle_exception(
                'materialfilenotfound',
                'local_coursepilot',
                '',
                material_files::normalise_path($params['path'])
            );
        }
        $relativepath = $stored['path'];
        $filename = basename($relativepath);

        // Check GD before rejecting non-images: absent GD disables the feature
        // regardless of file type (Spec 0018 §3.3).
        if (!gd_support::available()) {
            throw new \moodle_exception('materialgdmissing', 'local_coursepilot');
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            return self::unavailable_response(
                $relativepath,
                get_string('materialpreviewnotanimage', 'local_coursepilot', $relativepath)
            );
        }

        return self::build_preview_response($relativepath, $stored['content']);
    }

    /**
     * Returns the successful preview or explained unavailability when GD
     * cannot read raster bytes (#523), extracted to keep execute() below 50 lines.
     *
     * @param string $relativepath
     * @param string $content
     * @return mixed[]
     */
    private static function build_preview_response(string $relativepath, string $content): array {
        // Even image extensions can contain unreadable bytes or disguised SVG.
        // Like non-images, return explained unavailability (Spec §3: clear message
        // instead of an error). Catch image_preview::build()'s
        // materialpreviewunsupported exception here.
        try {
            $preview = image_preview::build($content);
        } catch (\moodle_exception $e) {
            return self::unavailable_response($relativepath, get_string('materialpreviewunsupported', 'local_coursepilot'));
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
     * Shared unavailable-response shape, extracted from execute() (#523).
     *
     * @param string $relativepath
     * @param string $message
     * @return mixed[]
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
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Resolved file path relative to the material store'),
            'available' => new external_value(PARAM_BOOL, 'true if an image preview was generated'),
            'message' => new external_value(
                PARAM_RAW,
                'Explanation when preview is unavailable (e.g. a non-image file), otherwise null',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'mimetype' => new external_value(
                PARAM_RAW,
                'Preview MIME type ("image/jpeg"), null when unavailable',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'image_base64' => new external_value(
                PARAM_RAW,
                'Base64-encoded JPEG preview; the dispatcher adds an MCP image block from it, '
                    . 'null when unavailable',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'width' => new external_value(
                PARAM_INT,
                'Preview width in pixels, null when unavailable',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
            'height' => new external_value(
                PARAM_INT,
                'Preview height in pixels, null when unavailable',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
