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

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * A skill file that cannot be read is delivered as an empty text, not as a boolean (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(skill_corpus::class)]
final class skill_corpus_674_final_contract_test extends \advanced_testcase {
    /**
     * A listed skill whose file has vanished returns empty content of type string.
     */
    public function test_an_unreadable_skill_file_yields_empty_string_content(): void {
        global $CFG;
        $this->resetAfterTest();
        $root = make_request_directory();
        $skills = $root . '/local/coursepilot/skills/adapter';
        mkdir($skills, 0777, true);
        copy($CFG->dirroot . '/local/coursepilot/version.php', $root . '/local/coursepilot/version.php');
        // A dangling link is listed by glob() but cannot be opened.
        symlink($root . '/missing-target.md', $skills . '/ghost.md');
        $CFG->dirroot = $root;
        $handler = static fn(): bool => true;

        set_error_handler($handler, E_WARNING);
        try {
            $skill = skill_corpus::get('ghost');
        } finally {
            restore_error_handler();
        }

        $this->assertSame('', $skill['content']);
        $this->assertSame([], $skill['referenced_parts']);
    }
}
