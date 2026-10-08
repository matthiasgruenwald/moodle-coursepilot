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

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * Repo check (ticket #391, acceptance criterion): none of the six functions
 * deprecated in Moodle 5.2 (MDL-86854/MDL-86862 and neighbours) is
 * NEWLY introduced by the new structure/position endpoints.
 * move_section_to()/moveto_module() are still used internally by Moodle core
 * itself (e.g. behind stateactions::cm_move()/section_move_after())
 * - that is Moodle's own implementation behind the abstraction, not a
 * call from this plugin, and is therefore NOT checked here. Only the plugin's
 * own code is checked.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversNothing]
final class no_deprecated_move_functions_test extends \advanced_testcase {

    /** @var string[] Function names that ticket #391 names as deprecated in 5.2. */
    private const FORBIDDEN_FUNCTIONS = [
        'move_section_to',
        'moveto_module',
        'course_delete_module',
        'duplicate_module',
        'set_coursemodule_groupmode',
        'course_set_marker',
        'set_section_visible',
    ];

    public function test_plugin_source_never_calls_forbidden_functions_directly(): void {
        // Guard production calls; test fixtures use Moodle's native deletion path.
        $root = dirname(__DIR__, 2) . '/classes';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = self::strip_comments_and_strings(file_get_contents($file->getPathname()));
            foreach (self::FORBIDDEN_FUNCTIONS as $functionname) {
                if (preg_match('/(?<![\w:>])' . preg_quote($functionname, '/') . '\s*\(/', $contents) === 1) {
                    $violations[] = $functionname . ' in ' . $file->getPathname();
                }
            }
        }

        $this->assertSame([], $violations, "Forbidden function calls found:\n" . implode("\n", $violations));
    }

    /**
     * Removes comments and string literals (token based) so that a
     * documenting prose reference to a forbidden function (as in
     * this class itself, or in move_section.php/move_module.php)
     * does not produce a false hit - only actual
     * PHP code is checked.
     *
     * @param string $source
     * @return string
     */
    private static function strip_comments_and_strings(string $source): string {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }
        return $out;
    }
}
