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
 * course_module_placement (Spec 0026 module 4, #595).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(course_module_placement::class)]
final class course_module_placement_test extends \advanced_testcase {

    /** @return array{0: \stdClass, 1: int[]} course and the cmids of three pages in section 1 */
    private function course_with_pages(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $cmids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $cmids[] = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => $name], ['section' => 1])->cmid;
        }
        return [$course, $cmids];
    }

    /** @return int[] cmids of a section in course order */
    private function order(int $courseid, int $sectionnum): array {
        rebuild_course_cache($courseid, true);
        return array_map('intval', get_fast_modinfo($courseid)->sections[$sectionnum] ?? []);
    }

    private function cmids(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]));
    }

    public function test_place_after_in_the_middle_goes_before_the_successor(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        course_module_placement::place_after($c, $a);
        $this->assertSame([$a, $c, $b], $this->order((int) $course->id, 1));
    }

    public function test_place_after_the_last_one_goes_to_section_end(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        course_module_placement::place_after($a, $c);
        $this->assertSame([$b, $c, $a], $this->order((int) $course->id, 1));
    }

    public function test_place_after_moves_across_sections(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        $other = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id], ['section' => 2])->cmid;
        course_module_placement::place_after($a, $other);
        $this->assertSame([$other, $a], $this->order((int) $course->id, 2));
        $this->assertSame([$b, $c], $this->order((int) $course->id, 1));
    }

    public function test_place_after_its_own_predecessor_changes_nothing(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        course_module_placement::place_after($b, $a);
        $this->assertSame([$a, $b, $c], $this->order((int) $course->id, 1));
    }

    public function test_set_visible_hides_and_shows(): void {
        global $DB;
        [, [$a]] = $this->course_with_pages();
        course_module_placement::set_visible($a, false);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $a]));
        course_module_placement::set_visible($a, true);
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visible', ['id' => $a]));
    }

    public function test_discard_failed_removes_the_activity(): void {
        [$course, [$a]] = $this->course_with_pages();
        course_module_placement::discard_failed($a);
        $this->assertNotContains($a, $this->cmids((int) $course->id));
    }

    public function test_discard_failed_leaves_nothing_in_the_recycle_bin(): void {
        global $DB;
        [$course, [$a]] = $this->course_with_pages();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $bin = $DB->count_records('tool_recyclebin_course');
        course_module_placement::discard_failed($a);
        $this->assertNotContains($a, $this->cmids((int) $course->id));
        $this->assertSame($bin, $DB->count_records('tool_recyclebin_course'));
    }

    public function test_discard_failed_keeps_older_recycle_bin_items(): void {
        global $DB;
        [$course, [$a, $b]] = $this->course_with_pages();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        course_delete_module($b);
        $bin = $DB->count_records('tool_recyclebin_course');
        $this->assertGreaterThan(0, $bin);
        course_module_placement::discard_failed($a);
        $this->assertSame($bin, $DB->count_records('tool_recyclebin_course'));
    }

    public function test_discard_failed_removes_a_half_made_row(): void {
        global $DB;
        [$course] = $this->course_with_pages();
        $cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $DB->get_field('modules', 'id', ['name' => 'page']),
            'instance' => 0, 'section' => $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1]),
        ]);
        course_module_placement::discard_failed($cmid);
        $this->assertNotContains($cmid, $this->cmids((int) $course->id));
    }
}
