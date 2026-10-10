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

/**
 * Synthetic filesystem boundary: another writer creates book.md after the missing
 * preflight but before persistence. Keep the namespace override in this child only.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot {
    /**
     * Returns file storage.
     *
     * @param mixed $reset The reset.
     */
    function get_file_storage($reset = false): \file_storage {
        $fs = \get_file_storage($reset);
        if (!$reset) {
            foreach (debug_backtrace() as $frame) {
                if (
                    ($frame['class'] ?? '') === private_files_storage_port::class &&
                        ($frame['function'] ?? '') === 'write' &&
                        ($frame['args'][1] ?? '') === 'activity-types/book.md'
                ) {
                    $GLOBALS['coursepilot603reads'] = ($GLOBALS['coursepilot603reads'] ?? 0) + 1;
                    if ($GLOBALS['coursepilot603reads'] === 2) {
                        $fs->create_file_from_string(
                            context_files::filerecord(
                                context_files::own_context()->id,
                                '/coursepilot/activity-types/',
                                'book.md'
                            ),
                            "Teacher's concurrent file"
                        );
                        $GLOBALS['coursepilot603created'] = true;
                    }
                    break;
                }
            }
        }
        return $fs;
    }
}
namespace {
    require_once(__DIR__ . '/phpunit_process_bootstrap.php');
    \core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[1]], '*', MUST_EXIST));
    $provided = [];
    \local_coursepilot\location_selection::apply([
        'context_area' => ['type' => 'moodle'], 'material_store' => ['type' => 'moodle'],
    ], $provided);
    echo json_encode([
        'injected' => $GLOBALS['coursepilot603created'] ?? false,
        'provided' => $provided,
        'content' => \local_coursepilot\context_area::read('activity-types/book.md')['content'],
    ]);
}
