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
 * The exact shape of what the catalog read contract reports: value types of the activity
 * settings, the intro/content preview rules and the name/value pairs.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(module_state::class)]
final class module_state_contract_test extends \advanced_testcase {
    /**
     * Effective settings of a page carry typed course-module facts next to the instance record.
     */
    public function test_effective_settings_have_exact_types_and_values(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Seite', 'section' => 2, 'idnumber' => 'P-1', 'visible' => 0,
            'groupmode' => 1, 'showdescription' => 1, 'lang' => 'de',
        ]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'downloadcontent', 1, ['id' => $cm->id]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);

        $settings = module_state::effective_settings($cm);

        $this->assertSame((int) $cm->id, $settings['coursemodule']);
        $this->assertSame(2, $settings['section']);
        $this->assertSame(0, $settings['visible']);
        $this->assertSame(1, $settings['visibleoncoursepage']);
        $this->assertSame('P-1', $settings['idnumber']);
        $this->assertSame(1, $settings['groupmode']);
        $this->assertSame(0, $settings['groupingid']);
        $this->assertSame((int) $course->id, $settings['course']);
        $this->assertSame((int) $cm->module, $settings['module']);
        $this->assertSame('page', $settings['modulename']);
        $this->assertSame((int) $page->id, $settings['instance']);
        $this->assertSame(1, $settings['showdescription']);
        $this->assertSame($cm->downloadcontent, $settings['downloadcontent']);
        $this->assertSame('de', $settings['lang']);
        $this->assertSame([], $settings['tags']);
        $this->assertSame('Seite', $settings['name']);
        $this->assertArrayNotHasKey('grade', $settings);
    }

    /**
     * The module's own instance row is the one read, not an arbitrary one.
     */
    public function test_effective_settings_read_the_instance_of_this_module(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Erste']);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Zweite']);

        $this->assertSame('Zweite', module_state::effective_settings(
            get_coursemodule_from_id('page', $second->cmid, 0, false, MUST_EXIST)
        )['name']);
        $this->assertSame('Erste', module_state::effective_settings(
            get_coursemodule_from_id('page', $first->cmid, 0, false, MUST_EXIST)
        )['name']);
    }

    /**
     * Availability appears as sanitised JSON when enabled, a quiz adds its grade settings, a choice its options.
     */
    public function test_availability_quiz_grades_and_repeated_groups_join_the_settings(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enableavailability = 1;
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10, 'sumgrades' => 0]);
        $DB->set_field('grade_items', 'gradepass', 4, [
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'itemnumber' => 0,
        ]);
        $choice = $this->getDataGenerator()->create_module('choice', [
            'course' => $course->id, 'option' => ['Ja', 'Nein'], 'limit' => [3, 4],
        ]);

        $quizsettings = module_state::effective_settings(get_coursemodule_from_id('quiz', $quiz->cmid, 0, false, MUST_EXIST));
        $choicesettings = module_state::effective_settings(get_coursemodule_from_id('choice', $choice->cmid, 0, false, MUST_EXIST));

        $this->assertSame(4.0, $quizsettings['gradepass']);
        $this->assertSame(10.0, $quizsettings['grademax']);
        $this->assertArrayHasKey('availabilityconditionsjson', $quizsettings);
        $this->assertArrayNotHasKey('gradepass', $choicesettings);
        $this->assertSame(['Ja', 'Nein'], $choicesettings['option']);
        $this->assertSame(['3', '4'], $choicesettings['limit']);
        $CFG->enableavailability = 0;
        $this->assertArrayNotHasKey('availabilityconditionsjson', module_state::effective_settings(
            get_coursemodule_from_id('quiz', $quiz->cmid, 0, false, MUST_EXIST)
        ));
    }

    /**
     * Repeated groups read only the rows of the given instance, in the declared order.
     */
    public function test_repeated_groups_are_scoped_to_the_instance(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_module('choice', ['course' => $course->id, 'option' => ['A', 'B']]);
        $two = $this->getDataGenerator()->create_module('choice', ['course' => $course->id, 'option' => ['C']]);

        $groups = module_state::read_repeated_groups(choice::class, (int) $two->id);

        $this->assertSame(['option', 'limit', 'optionid'], array_keys($groups));
        $this->assertSame(['C'], $groups['option']);
        $this->assertSame([], module_state::read_repeated_groups(page::class, (int) $one->id));
        $this->assertSame(['A', 'B'], module_state::read_repeated_groups(choice::class, (int) $one->id)['option']);
    }

    /**
     * Types without a catalog report their name only; a missing instance has an empty name.
     */
    public function test_unknown_reports_only_the_name(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $one = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Buch 1']);
        $two = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Buch 2']);

        $this->assertSame('Buch 2', module_state::unknown('book', (int) $two->id, false)['name']);
        $this->assertSame('Buch 1', module_state::unknown('book', (int) $one->id, false)['name']);
        $this->assertSame('', module_state::unknown('book', 0, false)['name']);
    }

    /**
     * The empty state has its fixed shape, with the content field built for the requested detail.
     */
    public function test_empty_state_shape(): void {
        $this->assertSame(
            ['name' => '', 'content' => ['html' => '', 'preview' => '', 'truncated' => false], 'settings' => [], 'quizslots' => []],
            module_state::empty(true)
        );
        $this->assertSame(['name', 'content', 'settings', 'quizslots'], array_keys(module_state::empty(false)));
    }

    /**
     * HTML is delivered only on request; the preview strips tags and collapses whitespace; truncation is flagged
     * only when content was withheld.
     */
    public function test_content_field_withholds_html_unless_requested(): void {
        $this->assertSame(
            ['html' => '', 'preview' => 'a b', 'truncated' => true],
            module_state::content_field("  <p>a \n  <b>b</b></p>  ", false)
        );
        $this->assertSame(
            ['html' => "  <p>a \n  <b>b</b></p>  ", 'preview' => 'a b', 'truncated' => false],
            module_state::content_field("  <p>a \n  <b>b</b></p>  ", true)
        );
        $this->assertSame(['html' => '', 'preview' => '', 'truncated' => false], module_state::content_field(" \n\t ", false));
        $this->assertSame(['html' => '', 'preview' => '', 'truncated' => false], module_state::content_field('', false));
    }

    /**
     * The preview is trimmed, kept whole up to 280 characters, and cut to 277 plus an ellipsis beyond.
     */
    public function test_preview_length_boundaries(): void {
        $this->assertSame('x', module_state::preview("  x \n", false));
        $this->assertSame(str_repeat('a', 280), module_state::preview(str_repeat('a', 280), false));
        $this->assertSame(str_repeat('a', 277) . '...', module_state::preview(str_repeat('a', 281), false));
        $this->assertSame(str_repeat('a', 281), module_state::preview(str_repeat('a', 281), true));
        $long = str_repeat('0123456789', 29);
        $this->assertSame(substr($long, 0, 277) . '...', module_state::preview($long, false));
        $this->assertSame(280, strlen(module_state::preview($long, false)));
    }

    /**
     * Settings become name/value text pairs in the given order, numbers as text.
     */
    public function test_settings_are_text_pairs(): void {
        $this->assertSame(
            [['name' => 'a', 'value' => '1'], ['name' => '7', 'value' => '2.5'], ['name' => 'c', 'value' => ''],
                ['name' => 'd', 'value' => '1']],
            module_state::settings(['a' => 1, 7 => 2.5, 'c' => '', 'd' => true])
        );
    }
}
