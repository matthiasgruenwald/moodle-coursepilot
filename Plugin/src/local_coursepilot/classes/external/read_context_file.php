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
use local_coursepilot\previous_location;
use local_coursepilot\context_area;
use local_coursepilot\context_files;
use local_coursepilot\personal_data;
use local_coursepilot\storage_anchor;

defined('MOODLE_INTERNAL') || die();

/**
 * Reads a file from the calling teacher's context area (Issue #343).
 * The original read-only contract exposes no write operation.
 *
 * Location-independent since #538 (Spec 0021): {@see context_area::read()}
 * and {@see context_area::read_previous_location()} return "contenthash"
 * for Moodle and external storage, with no separate "etag" field.
 *
 * Declared directly in English (#571, Spec 0025 §A): "previous_location"
 * replaces "vorheriger_ort", as in {@see list_context_files}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class read_context_file extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'path' => new external_value(PARAM_PATH, 'File path relative to the context area, e.g. "templates.md"'),
            'previous_location' => new external_value(
                PARAM_BOOL,
                'Optional: true reads from the previous location instead of the current one (read-only switch for '
                    . 'the legacy stock, Issue #498) - only takes effect while a legacy stock is open',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param string $path
     * @param bool $previouslocation
     * @return array
     * @throws \moodle_exception invalidcontextpath for an empty path or
     *         a "."/".." segment; contextfilenotfound for missing files;
     *         previouslocationclosed if "previous_location" is requested without
     *         open legacy storage.
     */
    public static function execute(string $path, bool $previouslocation = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['path' => $path, 'previous_location' => $previouslocation]
        );

        // No additional local/coursepilot:use capability is needed (unlike course
        // tools): the context area belongs to the person, so standard user rights
        // suffice (#343). validate_context() requires login for their own context;
        // dispatcher::handle_authorized() checks global remote access for every call.
        $context = context_files::own_context();
        self::validate_context($context);

        $path = storage_anchor::normalise_client_path(context_files::area(), $params['path']);
        $previous = $params['previous_location'] ? previous_location::require_open_location() : null;
        $file = $previous ? context_area::read_previous_location($path, $previous) : context_area::read($path);
        // Read old names only when the canonical file is absent. Do not catch
        // access/storage errors or cross the selected current/previous location.
        $legacyname = [
            'templates.md' => 'vorlagen.md',
            'notepad.md' => 'merkzettel.md',
            'CONTEXT-people.md' => 'CONTEXT.personen.md',
        ][basename($path)] ?? null;
        if ($file === null && $legacyname !== null) {
            $legacypath = (dirname($path) === '.' ? '' : dirname($path) . '/') . $legacyname;
            $file = $previous
                ? context_area::read_previous_location($legacypath, $previous)
                : context_area::read($legacypath);
        }
        if ($file === null) {
            throw new \moodle_exception('contextfilenotfound', 'local_coursepilot', '', $params['path']);
        }

        // Personal-data switch (#344, ADR 0011): checks the frontmatter marker,
        // not a content-based guess; see local_coursepilot\personal_data.
        if (personal_data::is_marked($file['content']) && !personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $params['path']);
        }

        return [
            'path' => $file['path'],
            'filename' => basename($file['path']),
            'mimetype' => $file['mimetype'],
            'size' => $file['size'],
            'content' => $file['content'],
            'contenthash' => $file['contenthash'],
            'timemodified' => $file['timemodified'],
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Resolved file path, relative to the context area'),
            'filename' => new external_value(PARAM_TEXT, 'File name'),
            'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
            'size' => new external_value(PARAM_INT, 'File size in bytes'),
            'content' => new external_value(PARAM_RAW, 'File content'),
            // Added in Spec 0016 §2 for concurrency protection and manual-change detection.
            'contenthash' => new external_value(PARAM_ALPHANUMEXT, 'Content checksum of the file'),
            'timemodified' => new external_value(PARAM_INT, 'Time of last change'),
        ]);
    }
}
