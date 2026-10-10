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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\material_files;
use local_coursepilot\workbench_ticket;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only tool (#501, Spec #486 §13): issues one-time download tickets
 * for workbench files. Shell clients can retrieve original bytes through
 * curl without an OAuth Bearer header, e.g. to transfer workbench material
 * to the collection.
 *
 * Returns URL, name, size and SHA-1, not a ready-made command. SHA-1 is
 * Moodle contenthash; see material_files::read_content().
 * {@see \local_coursepilot\workbench_ticket} owns binding, lifetime and redemption guards.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class create_workbench_download_links extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'paths' => new external_multiple_structure(
                new external_value(PARAM_PATH, 'File path relative to the workbench root, e.g. "worksheet.pdf"')
            ),
        ]);
    }

    /**
     * @param string[] $paths
     * @return array
     * @throws \moodle_exception invalidmaterialpath, materialfilenotfound
     * @throws \required_capability_exception without moodle/user:manageownfiles
     */
    public static function execute(array $paths): array {
        $params = self::validate_parameters(self::execute_parameters(), ['paths' => $paths]);

        $context = material_files::own_context();
        self::validate_context($context);
        material_files::require_manage_own_files();

        $links = [];
        foreach ($params['paths'] as $path) {
            $links[] = workbench_ticket::issue($path);
        }

        return [
            'links' => $links,
            // Access logging (Spec 0018 §9.2), as in delete_material_files:
            // all affected paths in one entry instead of a per-file special case.
            'path' => implode(', ', array_column($links, 'path')),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'links' => new external_multiple_structure(
                new external_single_structure([
                    'path' => new external_value(PARAM_TEXT, 'File path relative to the workbench root'),
                    'name' => new external_value(PARAM_TEXT, 'Filename'),
                    'size' => new external_value(PARAM_INT, 'File size in bytes'),
                    'sha1' => new external_value(PARAM_ALPHANUMEXT, 'File SHA-1 checksum (Moodle contenthash)'),
                    'url' => new external_value(PARAM_URL, 'One-time download link, valid for 15 minutes'),
                ])
            ),
            'path' => new external_value(PARAM_TEXT, 'All affected paths, comma-separated for access logging'),
        ]);
    }
}
