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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A section whose module sequence is NULL (inconsistent, restored or hand-edited data) is still listed (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_course_catalog::class)]
#[CoversClass(get_modules::class)]
final class null_sequence_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Teacher course whose section 1 holds two pages but has no stored sequence.
     *
     * @return \stdClass Course.
     */
    private function course_with_null_sequence(): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        /** @var \mod_page_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $generator->create_instance(['course' => $course->id, 'section' => 1, 'name' => 'Eins']);
        $generator->create_instance(['course' => $course->id, 'section' => 1, 'name' => 'Zwei']);
        $DB->set_field('course_sections', 'sequence', null, ['course' => $course->id, 'section' => 1]);
        rebuild_course_cache($course->id, true);
        return $course;
    }

    public function test_the_catalog_lists_both_modules_of_a_section_without_a_stored_sequence(): void {
        $course = $this->course_with_null_sequence();

        $result = $this->succeeded(fn() => get_course_catalog::execute((int) $course->id, 1));

        $this->assertCount(2, $result['sections'][0]['modules']);
    }

    public function test_the_module_list_contains_both_modules_of_a_section_without_a_stored_sequence(): void {
        $course = $this->course_with_null_sequence();

        $result = $this->succeeded(fn() => get_modules::execute((int) $course->id, 1));

        $this->assertCount(2, $result);
    }
}
