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

/**
 * Deletion endpoint for material cleanup (Spec 0018 §8.3, #438): removes
 * exactly the caller-provided material paths. No automatic or age-based
 * deletion: the skill asks first. {@see report_loose_material_files}
 * already assessed usage; this endpoint does not reassess it.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class delete_material_files extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'paths' => new external_multiple_structure(
                new external_value(PARAM_PATH, 'File path relative to the material store, e.g. "screenshot.png"')
            ),
        ]);
    }

    /**
     * Runs the delete material files tool.
     *
     * @param string[] $paths
     * @return array
     * @throws \moodle_exception invalidmaterialpath, materialdeletefilenotfound
     * @throws \required_capability_exception without moodle/user:manageownfiles
     */
    public static function execute(array $paths): array {
        $params = self::validate_parameters(self::execute_parameters(), ['paths' => $paths]);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        // Resolve all files first, failing on any missing path before deleting
        // anything, so typos cannot cause partial deletion. Use material_area::read()/
        // delete() through storage_port (#539) instead of direct storage facades.
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
            // Dispatcher logs this path key (Spec 0018 §9.2): all deleted paths
            // in one entry, without a separate per-file case.
            'path' => implode(', ', $deleted),
            'message' => get_string('materialfilesdeleted', 'local_coursepilot', (object) [
                'count' => count($deleted),
                'freed' => format_float($freedbytes / 1048576, 1),
            ]),
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Deleted file path relative to the material store')
            ),
            'freed_bytes' => new external_value(PARAM_INT, 'Freed storage in bytes'),
            'path' => new external_value(PARAM_TEXT, 'All deleted paths, comma-separated for access logging'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing success message'),
        ]);
    }
}
