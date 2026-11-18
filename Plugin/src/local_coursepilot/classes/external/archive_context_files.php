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
use local_coursepilot\context_archive;
use local_coursepilot\context_files;

/**
 * Moves direct `.md` files of one folder of the context area to `<folder>/archive/` and reports the
 * references to them in the index files (Spec 0030, ADR 0036). Writes, but never deletes or overwrites
 * (destructiveHint false), is not idempotent (a second call finds nothing to move) and never touches
 * a journal. The AI corrects the references with read and write; the plugin rewrites no index.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class archive_context_files extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'folder' => new external_value(
                PARAM_PATH,
                'Folder relative to the context area whose files are archived, empty for the root',
                VALUE_DEFAULT,
                ''
            ),
            'files' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Name of a .md file directly inside the folder, without a slash'),
                'Files to move to <folder>/archive/ - never journals, never over an existing target'
            ),
        ]);
    }

    /**
     * Runs the archive tool.
     *
     * @param string $folder
     * @param string[] $files
     * @return mixed[]
     * @throws \moodle_exception When nothing was moved, e.g. contextarchivejournal, contextarchivemissing,
     *         contextarchivetargetexists, contextarchiveconflict, contextfilelocked, contextfilenotmarkdown
     * @throws \required_capability_exception without moodle/user:manageownfiles at a Moodle location
     */
    public static function execute(string $folder = '', array $files = []): array {
        $params = self::validate_parameters(self::execute_parameters(), ['folder' => $folder, 'files' => $files]);
        self::validate_context(context_files::own_context());

        $result = context_archive::run($params['folder'], $params['files']);
        $search = $result['search'];
        $complete = $search['reasons'] === [];

        return [
            'moved' => $result['moved'],
            'not_moved' => $result['not_moved'],
            'stopped_reason' => $result['stopped_reason'],
            'verified' => $result['verified'],
            'server_enforced' => $result['server_enforced'],
            'message' => self::message($result, $complete),
            'references' => $search['references'],
            'references_complete' => $complete,
            'references_reason' => implode(', ', $search['reasons']),
            'references_unchecked' => $search['unchecked'],
        ];
    }

    /**
     * Builds the teacher-facing summary with the next step for the AI.
     *
     * @param mixed[] $result Result of {@see context_archive::run()}.
     * @param bool $complete Whether the reference search was complete.
     * @return string
     */
    private static function message(array $result, bool $complete): string {
        $search = $result['search'];
        $count = count($result['moved']);
        $parts = [$result['not_moved'] === []
            ? get_string('contextarchivedone', 'local_coursepilot', $count)
            : get_string('contextarchivepartial', 'local_coursepilot', (object) [
                'moved' => $count,
                'total' => $count + count($result['not_moved']),
                'reason' => $result['stopped_reason'],
            ]),
        ];
        if (!$result['server_enforced']) {
            $parts[] = get_string('contextfilenotenforced', 'local_coursepilot');
        }
        if (!$result['verified']) {
            $parts[] = get_string('contextarchivenotverified', 'local_coursepilot');
        }
        $parts[] = $search['references'] !== []
            ? get_string('contextarchiverefsfound', 'local_coursepilot', count($search['references']))
            : ($complete ? get_string('contextarchiverefsnone', 'local_coursepilot', (object) [
                'files' => $search['files_checked'], 'folders' => $search['folders_checked'],
            ]) : '');
        if (!$complete) {
            $parts[] = get_string('contextarchiverefsincomplete', 'local_coursepilot', implode(', ', $search['reasons']));
        }
        return implode(' ', array_filter($parts));
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'moved' => new external_multiple_structure(new external_single_structure([
                'from' => new external_value(PARAM_TEXT, 'Old path, relative to the context area'),
                'to' => new external_value(PARAM_TEXT, 'New path below archive/'),
            ]), 'Files moved'),
            'not_moved' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Path of a requested file that was not moved and stays where it is'),
                'Files not moved because an earlier move failed'
            ),
            'stopped_reason' => new external_value(PARAM_RAW, 'Why the moving stopped early, empty when all files moved'),
            'verified' => new external_value(
                PARAM_BOOL,
                'true if every target reads back with the old content and every source is gone'
            ),
            'server_enforced' => new external_value(
                PARAM_BOOL,
                'false if the server did not enforce the source condition; a concurrent change could have been moved unnoticed'
            ),
            'message' => new external_value(PARAM_RAW, 'Summary with the next step'),
            'references' => new external_multiple_structure(new external_single_structure([
                'file' => new external_value(PARAM_TEXT, 'Index file with the reference, relative to the context area'),
                'line' => new external_value(PARAM_INT, 'Line of the reference, 1-based'),
                'path' => new external_value(PARAM_RAW, 'The path as written in that line - data, never the line itself'),
                'moved' => new external_value(PARAM_TEXT, 'Old path of the moved file it points to'),
            ]), 'References in index.md/CONTEXT.md to a moved file, to be corrected by read and write'),
            'references_complete' => new external_value(
                PARAM_BOOL,
                'true only if the whole search ran; false means there may be more references'
            ),
            'references_reason' => new external_value(PARAM_TEXT, 'Why the search is incomplete, empty when complete'),
            'references_unchecked' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Folder or index file not checked; check it yourself'),
                'Places not checked, at most 10'
            ),
        ]);
    }
}
