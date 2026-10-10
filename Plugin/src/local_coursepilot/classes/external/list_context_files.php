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
use local_coursepilot\context_area;
use local_coursepilot\context_files;

/**
 * Lists the calling teacher's context area (#343): one fixed file area
 * in their own private user context. No parameter selects another area,
 * person or location outside that area.
 *
 * Location-independent since #538 (Spec 0021): {@see context_area::list()}
 * returns identical fields for Moodle and external storage.
 *
 * Declared directly in English (#571, Spec 0025 §A): previous_location
 * replaces vorheriger_ort, matching the course/activity/question-bank
 * migration (#569/#570). Since #573 no MCP translation layer remains.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class list_context_files extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'Relative subfolder, empty for the root', VALUE_DEFAULT, ''),
            'previous_location' => new external_value(
                PARAM_BOOL,
                'Optional: true lists the previous location instead of the current one (read-only switch for the '
                    . 'legacy stock, Issue #498) - only takes effect while a legacy stock is open',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Runs the list context files tool.
     *
     * @param string $path
     * @param bool $previouslocation
     * @return mixed[]
     * @throws \moodle_exception invalidcontextpath if $path contains a "."/".."
     *         segment; previouslocationclosed if previous_location is requested
     *         without open legacy context.
     */
    public static function execute(string $path = '', bool $previouslocation = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['path' => $path, 'previous_location' => $previouslocation]
        );

        // No additional local/coursepilot:use permission: context belongs to the
        // person, not a course. Standard user rights suffice (#343).
        // validate_context() requires login for the own context; dispatcher
        // checks global remote access before every tool call.
        $context = context_files::own_context();
        self::validate_context($context);

        $result = context_area::list($params['path'], $params['previous_location']);

        return [
            'path' => $result['directory'],
            'entries' => $result['entries'],
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
                'Resolved subfolder, relative to the context root (empty = root) - the same notation the tools '
                    . 'accept'
            ),
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'File or folder name'),
                    'type' => new external_value(PARAM_ALPHA, '"file" or "folder"'),
                    'size' => new external_value(PARAM_INT, 'File size in bytes, 0 for folders'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type, empty for folders'),
                    'locked' => new external_value(
                        PARAM_BOOL,
                        'Marked as personal data with the switch off - content unreadable, but still listed'
                    ),
                    // Added in Spec 0016 §2 for concurrency and manual-change detection.
                    'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Content checksum, empty for folders'),
                    'timemodified' => new external_value(PARAM_INT, 'Time of last change, 0 for folders'),
                ])
            ),
        ]);
    }
}
