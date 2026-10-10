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

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * cm_references (Spec 0026 module 6, #597).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(cm_references::class)]
final class cm_references_test extends \advanced_testcase {
    /**
     * Provides tree.
     *
     * @param int $cmid The cmid.
     * @param bool $nested The nested.
     * @return string
     */
    private function tree(int $cmid, bool $nested = false): string {
        $cond = ['type' => 'completion', 'cm' => $cmid, 'e' => 1];
        $c = $nested ? [['op' => '|', 'c' => [$cond]]] : [$cond];
        return json_encode(['op' => '&', 'c' => $c, 'showc' => [true]]);
    }

    /**
     * Sets up course.
     *
     * @return array{0: \stdClass, 1: int, 2: int} course, target cmid, other cmid
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $target = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id], ['section' => 1])->cmid;
        $other = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id], ['section' => 1])->cmid;
        return [$course, $target, $other];
    }

    public function test_no_references_gives_empty_list(): void {
        [, $target] = $this->setup_course();
        $this->assertSame([], cm_references::references_to($target));
    }

    public function test_activity_availability_is_found_including_nested_groups(): void {
        global $DB;
        [, $target, $other] = $this->setup_course();
        $DB->set_field('course_modules', 'availability', $this->tree($target, true), ['id' => $other]);
        $this->assertSame([[
            'kind' => cm_references::KIND_ACTIVITY_AVAILABILITY,
            'location_id' => $other,
            'location' => 'cmid ' . $other,
        ]], cm_references::references_to($target));
    }

    public function test_condition_on_another_cm_is_not_a_reference(): void {
        global $DB;
        [, $target, $other] = $this->setup_course();
        $DB->set_field('course_modules', 'availability', $this->tree($other + 1000) /* no such cm */, ['id' => $other]);
        $this->assertSame([], cm_references::references_to($target));
    }

    public function test_section_availability_is_found(): void {
        global $DB;
        [$course, $target] = $this->setup_course();
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 2], '*', MUST_EXIST);
        $DB->set_field('course_sections', 'availability', $this->tree($target), ['id' => $section->id]);
        $this->assertSame([[
            'kind' => cm_references::KIND_SECTION_AVAILABILITY,
            'location_id' => (int) $section->id,
            'location' => 'section 2',
        ]], cm_references::references_to($target));
    }

    public function test_course_completion_criterion_is_found(): void {
        global $DB;
        [$course, $target] = $this->setup_course();
        $DB->insert_record('course_completion_criteria', (object) [
            'course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page',
            'moduleinstance' => $target,
        ]);
        $this->assertSame([[
            'kind' => cm_references::KIND_COURSE_COMPLETION,
            'location_id' => (int) $course->id,
            'location' => 'course ' . $course->id,
        ]], cm_references::references_to($target));
    }

    public function test_references_are_read_only(): void {
        global $DB;
        [, $target, $other] = $this->setup_course();
        $DB->set_field('course_modules', 'availability', $this->tree($target), ['id' => $other]);
        cm_references::references_to($target);
        $this->assertSame($this->tree($target), $DB->get_field('course_modules', 'availability', ['id' => $other]));
    }

    public function test_shared_parts_pairs_and_dangling(): void {
        $tree = json_decode($this->tree(7, true), true);
        $this->assertSame(['7:1'], cm_references::completion_pairs($tree));
        $this->assertTrue(cm_references::is_dangling_completion(['type' => 'completion', 'cm' => 0]));
        $this->assertFalse(cm_references::is_dangling_completion(['type' => 'completion', 'cm' => 7]));
        $this->assertFalse(cm_references::is_dangling_completion(['type' => 'date']));
    }
}
