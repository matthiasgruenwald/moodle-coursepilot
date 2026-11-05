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

    public function test_every_kind_of_reference_is_listed_in_order(): void {
        global $DB;
        [$course, $target, $other] = $this->setup_course();
        $third = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id], ['section' => 1])->cmid;
        $DB->set_field('course_modules', 'availability', $this->tree($target), ['id' => $other]);
        $DB->set_field('course_modules', 'availability', $this->tree($target, true), ['id' => $third]);
        $sections = $DB->get_records('course_sections', ['course' => $course->id], 'section', 'id, section');
        $firstsection = array_values($sections)[1];
        $secondsection = array_values($sections)[2];
        $DB->set_field('course_sections', 'availability', $this->tree($target), ['id' => $firstsection->id]);
        $DB->set_field('course_sections', 'availability', $this->tree($target), ['id' => $secondsection->id]);
        $DB->insert_record('course_completion_criteria', (object) [
            'course' => $course->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page', 'moduleinstance' => $target,
        ]);

        $this->assertSame([
            ['kind' => cm_references::KIND_ACTIVITY_AVAILABILITY, 'location_id' => $other, 'location' => 'cmid ' . $other],
            ['kind' => cm_references::KIND_ACTIVITY_AVAILABILITY, 'location_id' => $third, 'location' => 'cmid ' . $third],
            ['kind' => cm_references::KIND_SECTION_AVAILABILITY, 'location_id' => (int) $firstsection->id,
                'location' => 'section ' . $firstsection->section],
            ['kind' => cm_references::KIND_SECTION_AVAILABILITY, 'location_id' => (int) $secondsection->id,
                'location' => 'section ' . $secondsection->section],
            ['kind' => cm_references::KIND_COURSE_COMPLETION, 'location_id' => (int) $course->id,
                'location' => 'course ' . $course->id],
        ], cm_references::references_to($target));
    }

    public function test_completion_criterion_of_another_course_is_not_a_reference(): void {
        global $DB;
        [$course, $target] = $this->setup_course();
        $elsewhere = $this->getDataGenerator()->create_course();
        $DB->insert_record('course_completion_criteria', (object) [
            'course' => $elsewhere->id, 'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page', 'moduleinstance' => $target,
        ]);

        $this->assertSame([], cm_references::references_to($target));
    }

    public function test_availability_that_is_not_a_condition_tree_is_no_reference(): void {
        global $DB;
        [, $target, $other] = $this->setup_course();
        $DB->set_field('course_modules', 'availability', '"just text"', ['id' => $other]);
        $this->assertSame([], cm_references::references_to($target));
        $DB->set_field('course_modules', 'availability', json_encode(['op' => '&', 'c' => [['type' => 'date']]]), ['id' => $other]);
        $this->assertEmpty(cm_references::references_to($target));
    }

    public function test_a_condition_with_a_textual_cm_id_points_at_the_activity(): void {
        global $DB;
        [, $target, $other] = $this->setup_course();
        $tree = json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => (string) $target, 'e' => 1]]]);
        $DB->set_field('course_modules', 'availability', $tree, ['id' => $other]);

        $this->assertSame($other, cm_references::references_to($target)[0]['location_id']);
    }

    public function test_pairs_list_all_conditions_of_nested_and_sibling_groups(): void {
        $tree = ['type' => 'completion', 'cm' => 1, 'e' => 0, 'c' => [
            ['op' => '|', 'c' => [['type' => 'completion', 'cm' => 2, 'e' => 1], ['type' => 'date']]],
            ['type' => 'completion', 'cm' => 3, 'e' => 2],
            ['type' => 'completion', 'cm' => 4],
            'ignored',
        ]];

        $this->assertSame(['1:0', '2:1', '3:2'], cm_references::completion_pairs($tree));
    }

    public function test_pairs_use_whole_numbers(): void {
        $tree = ['type' => 'completion', 'cm' => 7.9, 'e' => 1.5];

        $this->assertSame(['7:1'], cm_references::completion_pairs($tree));
    }

    public function test_dangling_means_an_explicit_cm_zero(): void {
        $this->assertTrue(cm_references::is_dangling_completion(['type' => 'completion', 'cm' => '0']));
        $this->assertFalse(cm_references::is_dangling_completion(['type' => 'completion']));
    }
}
