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
use local_coursepilot\previous_location;
use local_coursepilot\context_files;
use local_coursepilot\context_reader;
use local_coursepilot\storage_anchor;

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
     * Describes the parameters of execute.
     *
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
            'section' => new external_value(
                PARAM_TEXT,
                'Optional: heading of one section to read, e.g. "Week 2" or "## Week 2" (case-insensitive); the section '
                    . 'includes its sub-headings. Files over 100 lines return only a table of contents without it',
                VALUE_DEFAULT,
                ''
            ),
            'whole' => new external_value(
                PARAM_BOOL,
                'Optional: true returns the whole file even when it is longer than 100 lines',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Runs the read context file tool.
     *
     * @param string $path
     * @param bool $previouslocation
     * @param string $section Heading of the section to read, empty for none.
     * @param bool $whole Return the whole file even when it is long.
     * @return mixed[]
     * @throws \moodle_exception invalidcontextpath for an empty path or
     *         a "."/".." segment; contextfilenotfound for missing files;
     *         previouslocationclosed if "previous_location" is requested without
     *         open legacy storage; contextsectionandwhole, contextsectionunknown and
     *         contextsectionambiguous for a bad section request.
     */
    public static function execute(
        string $path,
        bool $previouslocation = false,
        string $section = '',
        bool $whole = false
    ): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['path' => $path, 'previous_location' => $previouslocation, 'section' => $section, 'whole' => $whole]
        );
        $section = trim($params['section']);
        if ($section !== '' && $params['whole']) {
            throw new \moodle_exception('contextsectionandwhole', 'local_coursepilot');
        }

        // No additional local/coursepilot:use capability is needed (unlike course
        // tools): the context area belongs to the person, so standard user rights
        // suffice (#343). validate_context() requires login for their own context;
        // dispatcher::handle_authorized() checks global remote access for every call.
        $context = context_files::own_context();
        self::validate_context($context);

        $path = storage_anchor::normalise_client_path(context_files::area(), $params['path']);
        $previous = $params['previous_location'] ? previous_location::require_open_location() : null;
        $result = context_reader::read_selected($path, $previous, $section, $params['whole']);
        if ($result === null) {
            throw new \moodle_exception('contextfilenotfound', 'local_coursepilot', '', $params['path']);
        }
        return $result;
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'path' => new external_value(PARAM_TEXT, 'Resolved file path, relative to the context area'),
            'filename' => new external_value(PARAM_TEXT, 'File name'),
            'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
            'size' => new external_value(PARAM_INT, 'File size in bytes'),
            'content' => new external_value(
                PARAM_RAW,
                'File content; empty for a long file without section/whole (see toc and hint); only the section text '
                    . 'with section'
            ),
            // Added in Spec 0016 §2 for concurrency protection and manual-change detection.
            'contenthash' => new external_value(PARAM_RAW_TRIMMED, 'Content checksum of the file'),
            'timemodified' => new external_value(PARAM_INT, 'Time of last change'),
            // Added in #782: long files are read through a table of contents.
            'partial' => new external_value(PARAM_BOOL, 'True when content is not the whole file (toc or one section)'),
            'toc' => new external_multiple_structure(new external_single_structure([
                'level' => new external_value(PARAM_INT, 'Heading level, 2 or 3'),
                'heading' => new external_value(PARAM_TEXT, 'Heading text without the leading hashes'),
                'start_line' => new external_value(PARAM_INT, 'First line of the section, 1-based'),
                'end_line' => new external_value(PARAM_INT, 'Last line of the section, inclusive'),
            ]), 'Table of contents of a long file read without section/whole, otherwise empty'),
            'hint' => new external_value(PARAM_TEXT, 'How to read a section or the whole file; empty when content is complete'),
        ]);
    }
}
