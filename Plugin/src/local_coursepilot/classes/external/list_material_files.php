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
 * Lists the calling teacher's material store (Spec 0018 §2, #428): file
 * size, contenthash, modification time and remaining user quota. No
 * parameter addresses another area or person.
 *
 * Direct English contract (#572, Spec 0025 §A): location replaces ort.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class list_material_files extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Relative subfolder; empty for the root', VALUE_DEFAULT, ''),
            'location' => material_files::location_parameter(),
        ]);
    }

    /**
     * Runs the list material files tool.
     *
     * @param string $path
     * @param string $location
     * @return mixed[]
     * @throws \moodle_exception invalidmaterialpath if $path contains a "."/".."
     *         segment; invalidmateriallocation for an unknown
     *         location value; materialpathiscontext if the context area is inside
     *         the material store and $path enters it.
     */
    public static function execute(string $path = '', string $location = material_files::LOCATION_STORE): array {
        $params = self::validate_parameters(self::execute_parameters(), ['path' => $path, 'location' => $location]);

        $context = material_files::own_context();
        self::validate_context($context);

        // The pointer (#445) lives in the context anchor, not here, but listings
        // also exclude it if anchor and material root coincide. material_area::list()
        // removes the internal etag field independently of location (#539).
        $result = material_area::list($params['location'], $params['path']);

        $remaining = material_files::remaining_quota();

        return [
            'path' => $result['directory'],
            'entries' => $result['entries'],
            'remaining_quota_mb' => $remaining === null ? null : format_float($remaining / 1048576, 1),
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(
                PARAM_TEXT,
                'Resolved subfolder relative to the material root (empty = root), using the same notation '
                    . 'accepted by the tools'
            ),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'File or folder name'),
                    'type' => new external_value(
                        PARAM_ALPHA,
                        '"file", "folder" or "context_area" (context area - it physically lives here inside the '
                            . 'material store, but is not enterable via the material paths, see list_context_files)'
                    ),
                    'size' => new external_value(PARAM_INT, 'File size in bytes, 0 for folders'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type, empty for folders'),
                    'contenthash' => new external_value(
                        PARAM_ALPHANUMEXT,
                        'Content checksum; empty for folders and external material storage (WebDAV has no contenthash)'
                    ),
                    'timemodified' => new external_value(PARAM_INT, 'Last modification time, 0 for folders'),
                ])
            ),
            'remaining_quota_mb' => new external_value(
                PARAM_RAW,
                'Remaining quota in MB (formatted string), null without a quota limit',
                VALUE_DEFAULT,
                null,
                NULL_ALLOWED
            ),
        ]);
    }
}
