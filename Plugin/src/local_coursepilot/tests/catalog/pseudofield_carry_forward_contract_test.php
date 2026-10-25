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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact normalisation and carry-forward rules for the pseudofields of the native write sequence (#400, #405, #434).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(pseudofield_carry_forward::class)]
final class pseudofield_carry_forward_contract_test extends \advanced_testcase {
    use caught_throwable;

    public function test_editor_shorthand_and_partial_arrays_are_completed_to_the_full_editor_shape(): void {
        $string = ['page' => 'Text'];
        $partial = ['page' => ['text' => 'T']];
        $full = ['page' => ['text' => 'T', 'format' => 2, 'itemid' => 9]];
        $other = ['name' => 'N'];

        foreach ([&$string, &$partial, &$full, &$other] as &$patch) {
            pseudofield_carry_forward::normalise_editor_pseudofields(page::class, $patch);
        }
        unset($patch);

        $this->assertSame(['page' => ['text' => 'Text', 'format' => FORMAT_HTML, 'itemid' => 0]], $string);
        $this->assertSame(['page' => ['text' => 'T', 'format' => FORMAT_HTML, 'itemid' => 0]], $partial);
        $this->assertSame(['page' => ['text' => 'T', 'format' => 2, 'itemid' => 9]], $full);
        $this->assertSame(['name' => 'N'], $other);
    }

    public function test_an_editor_value_without_text_is_refused_naming_field_and_the_raw_value(): void {
        $patch = ['page' => ['x' => 'ä/ö']];

        $e = $this->caught(function () use (&$patch) {
            pseudofield_carry_forward::normalise_editor_pseudofields(page::class, $patch);
        });

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalideditorpseudofield', $e->errorcode);
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame(['field', 'value'], array_keys($e->a));
        $this->assertSame('page', $e->a['field']);
        $this->assertSame('{"x":"ä/ö"}', $e->a['value']);
        $number = ['page' => 5];
        $e = $this->caught(function () use (&$number) {
            pseudofield_carry_forward::normalise_editor_pseudofields(page::class, $number);
        });
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('5', $e->a['value']);
    }

    public function test_gradepass_values_are_unformatted_wherever_the_name_ends_with_it(): void {
        $this->resetAfterTest();
        $info = (object) [
            'gradepass' => '12.50',
            'submissiongradepass' => '3.5',
            'emptygradepass' => '',
            'numericgradepass' => 4,
            'other' => '7.5',
        ];

        pseudofield_carry_forward::unformat_localised_gradepass($info);

        $this->assertSame(12.5, $info->gradepass);
        $this->assertSame(3.5, $info->submissiongradepass);
        $this->assertSame('', $info->emptygradepass);
        $this->assertSame(4, $info->numericgradepass);
        $this->assertSame('7.5', $info->other);
    }

    public function test_the_intro_editor_follows_the_patch_text_and_format_as_exact_types(): void {
        $info = (object) ['introeditor' => ['text' => 'old', 'format' => 1, 'itemid' => 5]];
        pseudofield_carry_forward::sync_intro_editor_from_patch($info, ['intro' => 123, 'introformat' => '4']);
        $this->assertSame(['text' => '123', 'format' => 4, 'itemid' => 5], $info->introeditor);

        $untouched = (object) ['introeditor' => ['text' => 'old', 'format' => 1, 'itemid' => 5]];
        pseudofield_carry_forward::sync_intro_editor_from_patch($untouched, ['name' => 'x']);
        $this->assertSame(['text' => 'old', 'format' => 1, 'itemid' => 5], $untouched->introeditor);

        $without = (object) ['name' => 'n'];
        pseudofield_carry_forward::sync_intro_editor_from_patch($without, ['intro' => 'x']);
        $this->assertEquals((object) ['name' => 'n'], $without);
        $notarray = (object) ['introeditor' => 'x'];
        pseudofield_carry_forward::sync_intro_editor_from_patch($notarray, ['intro' => 'x']);
        $this->assertSame('x', $notarray->introeditor);
    }

    /**
     * Teacher, course and an assignment with plugin configuration rows.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function assignments(): array {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        /** @var \mod_assign_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $other = $generator->create_instance(['course' => $course->id]);
        $mine = $generator->create_instance(['course' => $course->id]);
        $DB->insert_record('assign_plugin_config', (object) [
            'assignment' => $other->id, 'plugin' => 'zzfake', 'subtype' => 'assignsubmission', 'name' => 'enabled',
            'value' => 'FOREIGN',
        ]);
        $DB->insert_record('assign_plugin_config', (object) [
            'assignment' => $mine->id, 'plugin' => 'aafake', 'subtype' => 'assignsubmission', 'name' => 'first', 'value' => 'ONE',
        ]);
        $DB->insert_record('assign_plugin_config', (object) [
            'assignment' => $mine->id, 'plugin' => 'bbfake', 'subtype' => 'assignfeedback', 'name' => 'second', 'value' => 'TWO',
        ]);
        $DB->insert_record('assign_plugin_config', (object) [
            'assignment' => $mine->id, 'plugin' => 'ccfake', 'subtype' => 'assignfeedback', 'name' => 'third', 'value' => 'THREE',
        ]);
        $cm = get_coursemodule_from_instance('assign', $mine->id, $course->id, false, MUST_EXIST);
        return [$cm, $mine];
    }

    public function test_assignment_plugin_settings_are_carried_forward_without_overwriting_what_is_present(): void {
        [$cm] = $this->assignments();
        $info = (object) ['assignsubmission_aafake_first' => 'PRESENT'];

        pseudofield_carry_forward::apply('assign', assign::class, $info, [], $cm, ['assignfeedback_bbfake_second' => 'PATCHED']);

        $this->assertSame('PRESENT', $info->assignsubmission_aafake_first);
        $this->assertFalse(property_exists($info, 'assignfeedback_bbfake_second'));
        $this->assertSame('THREE', $info->assignfeedback_ccfake_third);
        $this->assertFalse(property_exists($info, 'assignsubmission_zzfake_enabled'));
    }

    public function test_the_grading_method_becomes_an_empty_string_only_for_forms_that_know_markers(): void {
        [$cm] = $this->assignments();
        $withmarkers = (object) ['markercount' => 1, 'advancedgradingmethod_submissions' => null];
        $given = (object) ['markercount' => 1, 'advancedgradingmethod_submissions' => 'rubric'];
        $nomarkers = (object) ['advancedgradingmethod_submissions' => null];

        foreach ([$withmarkers, $given, $nomarkers] as $info) {
            pseudofield_carry_forward::apply('assign', assign::class, $info, [], $cm, []);
        }

        $this->assertSame('', $withmarkers->advancedgradingmethod_submissions);
        $this->assertSame('rubric', $given->advancedgradingmethod_submissions);
        $this->assertFalse(isset($nomarkers->advancedgradingmethod_submissions));
    }

    public function test_the_native_write_sequence_removes_localised_gradepass_formatting(): void {
        [$cm] = $this->assignments();
        $info = (object) ['gradepass' => '5.50'];

        pseudofield_carry_forward::apply('assign', assign::class, $info, [], $cm, []);

        $this->assertSame(5.5, $info->gradepass);
    }

    public function test_pseudofield_defaults_fill_only_absent_properties_and_not_named_ones(): void {
        [$cm] = $this->assignments();
        $info = (object) ['assignsubmission_file_maxfiles' => 99];

        pseudofield_carry_forward::apply('assign', assign::class, $info, [], $cm, ['assignsubmission_file_maxsizebytes' => 5]);

        $this->assertSame(99, $info->assignsubmission_file_maxfiles);
        $this->assertFalse(property_exists($info, 'assignsubmission_file_maxsizebytes'));
        $this->assertTrue(property_exists($info, 'assignsubmission_onlinetext_wordlimit'));
    }

    public function test_the_draft_placeholder_is_added_for_folders_and_resources_only_when_needed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('folder', $folder->cmid, 0, false, MUST_EXIST);

        $bare = new \stdClass();
        pseudofield_carry_forward::apply('folder', folder::class, $bare, [], $cm, []);
        $this->assertSame(0, $bare->files);

        $kept = (object) ['files' => 77];
        pseudofield_carry_forward::apply('folder', folder::class, $kept, [], $cm, []);
        $this->assertSame(77, $kept->files);

        $named = new \stdClass();
        pseudofield_carry_forward::apply('folder', folder::class, $named, [], $cm, ['files' => ['a.pdf']]);
        $this->assertFalse(property_exists($named, 'files'));

        $url = $this->getDataGenerator()->create_module('url', ['course' => $course->id]);
        $urlcm = get_coursemodule_from_id('url', $url->cmid, 0, false, MUST_EXIST);
        $other = new \stdClass();
        pseudofield_carry_forward::apply('url', url::class, $other, [], $urlcm, []);
        $this->assertFalse(property_exists($other, 'files'));
    }

    public function test_choice_options_are_rebuilt_from_the_table_unless_the_patch_names_them(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $choice = $this->getDataGenerator()->create_module('choice', ['course' => $course->id, 'option' => ['A', 'B']]);
        $cm = get_coursemodule_from_id('choice', $choice->cmid, 0, false, MUST_EXIST);

        $info = new \stdClass();
        pseudofield_carry_forward::apply('choice', choice::class, $info, [], $cm, []);
        $named = new \stdClass();
        pseudofield_carry_forward::apply('choice', choice::class, $named, [], $cm, ['option' => ['X']]);

        $this->assertSame(['A', 'B'], $info->option);
        $this->assertCount(2, $info->limit);
        $this->assertCount(2, $info->optionid);
        $this->assertSame(array_values($info->optionid), $info->optionid);
        $this->assertFalse(property_exists($named, 'option'));
    }

    public function test_editor_content_is_rebuilt_from_the_stored_text_with_its_embedded_files(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_page',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'pic.png',
        ], 'png-bytes');
        $before = ['content' => '<img src="@@PLUGINFILE@@/pic.png">', 'contentformat' => '2'];

        $stored = new \stdClass();
        pseudofield_carry_forward::apply('page', page::class, $stored, $before, $cm, []);
        $patched = new \stdClass();
        pseudofield_carry_forward::apply('page', page::class, $patched, $before, $cm, ['content' => 'NEW', 'contentformat' => 4]);
        $defaulted = new \stdClass();
        pseudofield_carry_forward::apply('page', page::class, $defaulted, [], $cm, []);
        $named = new \stdClass();
        pseudofield_carry_forward::apply('page', page::class, $named, $before, $cm, ['page' => ['text' => 'x']]);

        $this->assertSame(2, $stored->page['format']);
        $this->assertStringContainsString('draftfile.php', $stored->page['text']);
        $this->assertStringContainsString('pic.png', $stored->page['text']);
        $this->assertGreaterThan(0, $stored->page['itemid']);
        $this->assertSame('NEW', $patched->page['text']);
        $this->assertSame(4, $patched->page['format']);
        $this->assertSame('', $defaulted->page['text']);
        $this->assertSame((int) FORMAT_HTML, $defaulted->page['format']);
        $this->assertFalse(property_exists($named, 'page'));
    }
}
