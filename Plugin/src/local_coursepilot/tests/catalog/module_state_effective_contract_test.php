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
namespace local_coursepilot\catalog;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Nullable activity columns appear as empty text in the effective settings (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(module_state::class)]
final class module_state_effective_contract_test extends \advanced_testcase {
    public function test_a_restored_activity_without_idnumber_or_language_reads_empty_text(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'idnumber', null, ['id' => $page->cmid]);
        $DB->set_field('course_modules', 'lang', null, ['id' => $page->cmid]);
        $cm = $DB->get_record('course_modules', ['id' => $page->cmid], '*', MUST_EXIST);
        $cm->modname = 'page';

        $settings = module_state::effective_settings($cm);

        $this->assertSame('', $settings['idnumber']);
        $this->assertSame('', $settings['lang']);
        $this->assertSame('page', $settings['modulename']);
    }
}
