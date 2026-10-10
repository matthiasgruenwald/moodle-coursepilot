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
use local_coursepilot\material_composition;
use local_coursepilot\material_files;

/**
 * Compose full-resolution raster parts into one PNG on the teacher's workbench.
 * Image bytes remain server-side; only paths, coordinates and header text cross MCP.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class compose_material_file extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'parts' => new external_multiple_structure(new external_single_structure([
                'sourcepath' => new external_value(PARAM_PATH, 'Source file path relative to its location root'),
                'location' => material_files::location_parameter(),
                'crop' => new external_single_structure(
                    [
                        'x0' => new external_value(PARAM_FLOAT, 'Left edge, relative 0-1'),
                        'y0' => new external_value(PARAM_FLOAT, 'Top edge, relative 0-1'),
                        'x1' => new external_value(PARAM_FLOAT, 'Right edge, relative 0-1'),
                        'y1' => new external_value(PARAM_FLOAT, 'Bottom edge, relative 0-1'),
                    ],
                    'Optional crop from the full-resolution original, using the same coordinates as crop_material_file',
                    VALUE_OPTIONAL
                ),
                'source_header_text' => new external_value(
                    PARAM_TEXT,
                    'Optional source reference rendered above this part in the fixed house style',
                    VALUE_DEFAULT,
                    ''
                ),
                'expected_contenthash' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Optional SHA-1 of this source file; a mismatch aborts before writing',
                    VALUE_DEFAULT,
                    ''
                ),
            ]), 'Ordered, non-empty list of raster parts; locations may be mixed'),
            'arrangement' => new external_value(PARAM_ALPHA, 'vertical (top to bottom) or horizontal (left to right)'),
            'targetpath' => new external_value(
                PARAM_PATH,
                'PNG target path relative to the workbench root, resolved like crop_material_file; an existing file is replaced'
            ),
        ]);
    }

    /**
     * Runs the compose material file tool.
     *
     * @param mixed[] $parts Ordered source parts.
     * @param string $arrangement vertical or horizontal.
     * @param string $targetpath Workbench PNG path.
     * @return mixed[] Target metadata and ordered source descriptions, without image bytes.
     */
    public static function execute(array $parts, string $arrangement, string $targetpath): array {
        $params = self::validate_parameters(self::execute_parameters(), compact('parts', 'arrangement', 'targetpath'));
        self::validate_context(material_files::own_context());
        material_files::require_manage_own_files();
        if (!$params['parts']) {
            throw new \moodle_exception('materialcompositionemptyparts', 'local_coursepilot');
        }
        if (!in_array($params['arrangement'], ['vertical', 'horizontal'], true)) {
            throw new \moodle_exception('materialcompositioninvalidarrangement', 'local_coursepilot');
        }
        [$directory, $filename] = material_files::resolve_writable_file($params['targetpath']);
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'png') {
            throw new \moodle_exception(
                'materialcompositionoutputunsupported',
                'local_coursepilot',
                '',
                pathinfo($filename, PATHINFO_EXTENSION)
            );
        }
        [$inputs, $sources] = self::read_sources($params['parts']);
        [$content, $width, $height] = material_composition::render($inputs, $params['arrangement']);
        $existing = material_files::read_content($directory, $filename);
        $warning = material_files::write($directory, $filename, $content, $existing['size'] ?? 0, [
            'source' => serialize((object) ['original' => implode('; ', $sources)]),
        ]);
        $path = material_files::relative_file($directory, $filename);
        $message = get_string(
            'materialcompositionwritten',
            'local_coursepilot',
            (object) ['path' => $path, 'width' => $width, 'height' => $height]
        );
        return [
            'path' => $path, 'sources' => $sources, 'created' => $existing === null,
            'width' => $width, 'height' => $height, 'size' => strlen($content),
            'contenthash' => sha1($content), 'message' => $message . ($warning === null ? '' : ' ' . $warning),
        ];
    }

    /**
     * Reads sources.
     *
     * @param mixed[] $parts The parts.
     * @return array{0: mixed[], 1: string[]} Validated source bytes and descriptions.
     */
    private static function read_sources(array $parts): array {
        $inputs = [];
        $sources = [];
        foreach ($parts as $part) {
            $stored = material_area::read_for_location($part['location'], $part['sourcepath']);
            if ($stored === null) {
                throw new \moodle_exception(
                    'materialfilenotfound',
                    'local_coursepilot',
                    '',
                    material_files::normalise_path($part['sourcepath'])
                );
            }
            if ($part['expected_contenthash'] !== '' && sha1($stored['content']) !== $part['expected_contenthash']) {
                throw new \moodle_exception('materialfilechanged', 'local_coursepilot', '', $stored['path']);
            }
            $inputs[] = $part + ['content' => $stored['content']];
            $sources[] = sprintf(
                '%s:%s (%d byte, changed %s)',
                $part['location'],
                $stored['path'],
                $stored['size'],
                gmdate('Y-m-d\TH:i:s\Z', $stored['timemodified'])
            );
        }
        return [$inputs, $sources];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Resolved PNG target path relative to the workbench root'),
            'sources' => new external_multiple_structure(new external_value(
                PARAM_TEXT,
                'Source location, resolved path, byte size and modification time'
            ), 'Source descriptions in part order'),
            'created' => new external_value(PARAM_BOOL, 'True if a new file was created, false if replaced'),
            'width' => new external_value(PARAM_INT, 'Output width in pixels'),
            'height' => new external_value(PARAM_INT, 'Output height in pixels'),
            'size' => new external_value(PARAM_INT, 'Output size in bytes'),
            'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'SHA-1 of the output PNG'),
            'message' => new external_value(PARAM_RAW, 'Write confirmation including any quota warning'),
        ]);
    }
}
