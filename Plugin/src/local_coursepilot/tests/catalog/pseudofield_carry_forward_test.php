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
 * Prepare the shared moduleinfo field object outside the form path
 * (#388/#392/#400).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(pseudofield_carry_forward::class)]
final class pseudofield_carry_forward_test extends \advanced_testcase {
    /**
     * get_moduleinfo_data() formats gradepass for the active language ("0,00"
     * in German). Convert it back to a number before writing; otherwise the
     * gradebook write fails after the activity change has persisted (#400).
     */
    public function test_localised_gradepass_becomes_a_number(): void {
        $this->resetAfterTest();
        $moduleinfo = (object) [
            'gradepass' => format_float(12.5, 2),
            // Activity types with multiple grade columns (workshop).
            'submissiongradepass' => format_float(7.0, 2),
            'name' => 'unberuehrt',
        ];

        pseudofield_carry_forward::unformat_localised_gradepass($moduleinfo);

        $this->assertSame(12.5, $moduleinfo->gradepass);
        $this->assertSame(7.0, $moduleinfo->submissiongradepass);
        $this->assertSame('unberuehrt', $moduleinfo->name);
    }

    /**
     * Empty stays empty; no passing threshold is not zero.
     */
    public function test_empty_gradepass_is_left_alone(): void {
        $this->resetAfterTest();
        $moduleinfo = (object) ['gradepass' => ''];

        pseudofield_carry_forward::unformat_localised_gradepass($moduleinfo);

        $this->assertSame('', $moduleinfo->gradepass);
    }

    /**
     * A bare string is shorthand for an HTML editor array; an array with text
     * keeps its own values and only gains the missing format and draft item;
     * fields that are no editor are left alone.
     */
    public function test_editor_pseudofield_shorthand_is_completed_and_other_fields_stay(): void {
        $this->resetAfterTest();
        $patch = ['page' => 'Kurztext'];
        pseudofield_carry_forward::normalise_editor_pseudofields(page::class, $patch);
        $this->assertSame(['text' => 'Kurztext', 'format' => FORMAT_HTML, 'itemid' => 0], $patch['page']);

        $patch = ['page' => ['text' => 'Mit Format', 'format' => FORMAT_MARKDOWN]];
        pseudofield_carry_forward::normalise_editor_pseudofields(page::class, $patch);
        $this->assertSame(['text' => 'Mit Format', 'format' => FORMAT_MARKDOWN, 'itemid' => 0], $patch['page']);

        $patch = ['name' => 'unberuehrt'];
        pseudofield_carry_forward::normalise_editor_pseudofields(page::class, $patch);
        $this->assertSame(['name' => 'unberuehrt'], $patch);
    }

    /**
     * A value without text is refused by field name instead of creating an empty page.
     */
    public function test_editor_pseudofield_without_text_is_rejected_by_name(): void {
        $this->resetAfterTest();
        $patch = ['page' => ['format' => FORMAT_HTML]];

        try {
            pseudofield_carry_forward::normalise_editor_pseudofields(page::class, $patch);
            $this->fail('An editor value without text must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalideditorpseudofield', $e->errorcode);
            $this->assertStringContainsString('page', $e->getMessage());
        }
    }

    /**
     * The intro editor follows intro and introformat from the patch; without an
     * editor array there is nothing to synchronise.
     */
    public function test_intro_editor_follows_text_and_format_of_the_patch(): void {
        $moduleinfo = (object) ['introeditor' => ['text' => 'alt', 'format' => FORMAT_HTML, 'itemid' => 7]];

        pseudofield_carry_forward::sync_intro_editor_from_patch($moduleinfo, ['intro' => 'neu', 'introformat' => FORMAT_MARKDOWN]);

        $this->assertSame(['text' => 'neu', 'format' => (int) FORMAT_MARKDOWN, 'itemid' => 7], $moduleinfo->introeditor);

        $bare = (object) ['name' => 'ohne Editor'];
        pseudofield_carry_forward::sync_intro_editor_from_patch($bare, ['intro' => 'neu']);
        $this->assertFalse(property_exists($bare, 'introeditor'));
    }

    /**
     * Moodle 5.2 compares the grading method strictly with an empty string: a
     * null from get_moduleinfo_data() must become '' for assign with markers.
     */
    public function test_assign_marker_grading_method_null_becomes_empty_string(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('assign', $assign->cmid, 0, false, MUST_EXIST);
        $moduleinfo = (object) ['markercount' => 2, 'advancedgradingmethod_submissions' => null];

        pseudofield_carry_forward::apply('assign', assign::class, $moduleinfo, [], $cm, []);

        $this->assertSame('', $moduleinfo->advancedgradingmethod_submissions);
    }

    /**
     * A choice without stored options leaves option fields absent instead of
     * writing empty lists over them.
     */
    public function test_choice_without_stored_options_gets_no_option_fields(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $choice = $this->getDataGenerator()->create_module('choice', ['course' => $course->id, 'option' => ['A', 'B']]);
        $cm = get_coursemodule_from_id('choice', $choice->cmid, 0, false, MUST_EXIST);
        $DB->delete_records('choice_options', ['choiceid' => $cm->instance]);
        $moduleinfo = new \stdClass();

        pseudofield_carry_forward::apply('choice', choice::class, $moduleinfo, [], $cm, []);

        $this->assertFalse(property_exists($moduleinfo, 'option'));
    }

    /**
     * A patch that names the page editor itself is not overwritten by the stored content.
     */
    public function test_named_editor_pseudofield_is_not_rebuilt_from_stored_content(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => 'gespeichert']);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $moduleinfo = (object) ['page' => ['text' => 'vom Patch', 'format' => FORMAT_HTML, 'itemid' => 0]];

        pseudofield_carry_forward::apply('page', page::class, $moduleinfo, [], $cm, ['page' => $moduleinfo->page]);

        $this->assertSame('vom Patch', $moduleinfo->page['text']);
    }
}
