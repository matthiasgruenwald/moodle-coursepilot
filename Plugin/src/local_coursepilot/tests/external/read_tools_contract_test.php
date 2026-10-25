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
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\declared_return_types;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact results, filters, ordering and types of the read tools for modules, sections and courses.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_modules::class)]
#[CoversClass(get_sections::class)]
#[CoversClass(list_courses::class)]
final class read_tools_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course with two sections. */
    private \stdClass $course;

    /**
     * Course with an editing teacher.
     *
     * @param int $sections
     */
    private function setup_course(int $sections = 2): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => $sections]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * Adds a page.
     *
     * @param string $name
     * @param int $section
     * @param \stdClass|null $course
     * @return \stdClass
     */
    private function page(string $name, int $section, ?\stdClass $course = null): \stdClass {
        return $this->getDataGenerator()->create_module('page', [
            'course' => ($course ?? $this->course)->id,
            'name' => $name,
            'section' => $section,
        ]);
    }

    public function test_modules_come_with_exact_typed_rows_ordered_by_section_and_position(): void {
        global $DB;
        $this->setup_course();
        $b = $this->page('B', 1);
        $a = $this->page('A', 1);
        $z = $this->page('Z', 0);
        $DB->set_field('course_modules', 'visibleoncoursepage', 0, ['id' => $a->cmid]);
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->set_field('course_sections', 'sequence', $a->cmid . ',' . $b->cmid, ['id' => $section->id]);

        $rows = get_modules::execute((int) $this->course->id);

        $this->assert_declared_types($rows, get_modules::execute_returns());
        $this->assertSame(['Z', 'A', 'B'], array_column($rows, 'name'));
        $this->assertSame([0, 1, 1], array_column($rows, 'sectionnum'));
        $this->assertSame(
            [
                'cmid' => (int) $a->cmid,
                'sectionnum' => 1,
                'modname' => 'page',
                'name' => 'A',
                'visible' => 1,
                'visibleoncoursepage' => 0,
                'coursepagevisibility' => 'stealth',
                'availability_status' => 'stealth',
            ],
            $rows[1]
        );
        $this->assertSame(1, $rows[2]['visibleoncoursepage']);
        $this->assertSame((int) $z->cmid, $rows[0]['cmid']);
    }

    public function test_a_section_number_selects_only_that_section_of_this_course(): void {
        $this->setup_course();
        $this->page('Zero', 0);
        $this->page('One', 1);
        $other = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $other->id);
        $this->page('Elsewhere', 1, $other);
        $id = (int) $this->course->id;

        $this->assertSame(['Zero'], array_column(get_modules::execute($id, 0), 'name'));
        $this->assertSame(['One'], array_column(get_modules::execute($id, 1), 'name'));
        $this->assertSame(['Zero', 'One'], array_column(get_modules::execute($id, -1), 'name'));
        $this->assertSame(['Zero', 'One'], array_column(get_modules::execute($id), 'name'));
        $this->assertSame(['Zero', 'One'], array_column(get_modules::execute($id, 9), 'name'));
    }

    public function test_an_activity_missing_from_its_sequence_sorts_after_those_in_it(): void {
        global $DB;
        $this->setup_course();
        $first = $this->page('Unlisted', 1);
        $second = $this->page('Listed', 1);
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->set_field('course_sections', 'sequence', (string) $second->cmid, ['id' => $section->id]);

        $names = array_column(get_modules::execute((int) $this->course->id, 1), 'name');

        $this->assertSame(['Listed', 'Unlisted'], $names);
        $this->assertNotSame((int) $first->cmid, (int) $second->cmid);
    }

    public function test_a_section_without_a_sequence_does_not_break_the_listing(): void {
        global $DB;
        $this->setup_course();
        $this->page('One', 1);
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->set_field('course_sections', 'sequence', null, ['id' => $section->id]);

        $e = $this->caught(fn() => get_modules::execute((int) $this->course->id));

        $this->assertNull($e);
    }

    public function test_the_read_tools_respect_a_context_restriction(): void {
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $modules = $this->caught(fn() => get_modules::execute((int) $this->course->id));
            $sections = $this->caught(fn() => get_sections::execute((int) $this->course->id));
            $courses = $this->caught(fn() => list_courses::execute());
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $modules);
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $sections);
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $courses);
    }

    public function test_sections_are_exact_typed_and_limited_to_the_course(): void {
        global $DB;
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $DB->set_field('course_sections', 'name', 'Algebra', ['course' => $this->course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'summary', '<p>Lead</p>', ['course' => $this->course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'visible', 0, ['course' => $this->course->id, 'section' => 2]);
        $DB->set_field('course_sections', 'name', null, ['course' => $this->course->id, 'section' => 2]);
        $DB->set_field('course_sections', 'summary', null, ['course' => $this->course->id, 'section' => 2]);

        $rows = get_sections::execute((int) $this->course->id);

        $this->assert_declared_types($rows, get_sections::execute_returns());
        $this->assertSame([0, 1, 2], array_column($rows, 'sectionnum'));
        $one = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 1], '*', MUST_EXIST);
        $this->assertSame(
            ['id' => (int) $one->id, 'sectionnum' => 1, 'name' => 'Algebra', 'summary' => '<p>Lead</p>', 'visible' => 1],
            $rows[1]
        );
        $this->assertSame('', $rows[2]['name']);
        $this->assertSame('', $rows[2]['summary']);
        $this->assertSame(0, $rows[2]['visible']);
        $this->assertNotEmpty($other->id);
    }

    public function test_courses_are_listed_with_typed_names_and_visibility(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $shown = $this->getDataGenerator()->create_course(['fullname' => 'Shown course', 'shortname' => 'shown', 'visible' => 1]);
        $hidden = $this->getDataGenerator()->create_course(
            ['fullname' => 'Hidden course', 'shortname' => 'hidden', 'visible' => 0]
        );
        $student = $this->getDataGenerator()->create_course(['fullname' => 'Student only', 'shortname' => 'stud']);
        $this->getDataGenerator()->enrol_user($user->id, $shown->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $hidden->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $student->id, 'student');

        $result = list_courses::execute();

        $this->assert_declared_types($result, list_courses::execute_returns());
        $rows = $result['courses'];
        usort($rows, static fn($a, $b) => $a['id'] <=> $b['id']);
        $this->assertSame([
            ['id' => (int) $shown->id, 'fullname' => 'Shown course', 'shortname' => 'shown', 'visible' => true],
            ['id' => (int) $hidden->id, 'fullname' => 'Hidden course', 'shortname' => 'hidden', 'visible' => false],
        ], $rows);
    }

    public function test_without_any_usable_course_the_list_names_the_missing_right(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->caught(fn() => list_courses::execute());

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('capabilitymissing', $e->errorcode);
        $this->assertSame('local/coursepilot:use', $e->a);
    }
}
