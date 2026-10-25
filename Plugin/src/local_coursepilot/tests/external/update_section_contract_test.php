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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exact response, change report and error parameters of update_section.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_section::class)]
final class update_section_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course with sections 0 to 2. */
    private \stdClass $course;

    /**
     * Course with an editing teacher; section 1 is named "Old name" with the summary "Old summary".
     */
    private function setup_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $id = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'name', 'Old name', ['id' => $id]);
        $DB->set_field('course_sections', 'summary', 'Old summary', ['id' => $id]);
        rebuild_course_cache($this->course->id, true);
    }

    /**
     * DB id of a section.
     *
     * @param int $number
     * @return int
     */
    private function section_id(int $number): int {
        global $DB;
        return (int) $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => $number]);
    }

    public function test_response_reports_each_changed_field_with_before_and_after_in_patch_order(): void {
        $this->setup_course();

        $result = update_section::execute((int) $this->course->id, 1, json_encode([
            'summary' => 'Größe/Ä', 'name' => 'New name', 'visible' => 0,
        ]));

        $this->assert_declared_types($result, update_section::execute_returns());
        $this->assertSame([
            'id' => $this->section_id(1),
            'sectionnum' => 1,
            'message' => 'Changed: "summary" from "Old summary" to "Größe/Ä", "name" from "Old name" to "New name", '
                . '"visible" from 1 to 0. All activities in this section are therefore hidden as well, '
                . 'regardless of their own visibility setting.',
            'changes' => [
                ['field' => 'summary', 'before_json' => '"Old summary"', 'after_json' => '"Größe/Ä"'],
                ['field' => 'name', 'before_json' => '"Old name"', 'after_json' => '"New name"'],
                ['field' => 'visible', 'before_json' => '1', 'after_json' => '0'],
            ],
        ], $result);
    }

    public function test_only_fields_that_really_change_are_reported(): void {
        $this->setup_course();

        $result = update_section::execute(
            (int) $this->course->id,
            1,
            json_encode(['name' => 'Old name', 'visible' => 1, 'summary' => 'Next'])
        );

        $this->assertSame(['summary'], array_column($result['changes'], 'field'));
        $this->assertSame('Changed: "summary" from "Old summary" to "Next".', $result['message']);
    }

    public function test_a_patch_that_changes_nothing_says_so_and_does_not_note_hiding(): void {
        $this->setup_course();
        update_section::execute((int) $this->course->id, 1, '{"visible":0}');

        $result = update_section::execute((int) $this->course->id, 1, '{"visible":0}');

        $this->assertSame('No change: the patch already matched the current state.', $result['message']);
        $this->assertSame([], $result['changes']);
    }

    public function test_an_empty_patch_changes_nothing(): void {
        $this->setup_course();

        $result = update_section::execute((int) $this->course->id, 1, '{}');

        $this->assertSame('No change: the patch already matched the current state.', $result['message']);
    }

    public function test_showing_a_hidden_section_gives_no_hiding_note(): void {
        $this->setup_course();
        update_section::execute((int) $this->course->id, 1, '{"visible":0}');

        $result = update_section::execute((int) $this->course->id, 1, '{"visible":1}');

        $this->assertSame('Changed: "visible" from 0 to 1.', $result['message']);
    }

    public function test_summary_without_a_format_is_stored_as_html(): void {
        global $DB;
        $this->setup_course();

        update_section::execute((int) $this->course->id, 1, '{"summary":"<b>bold</b>"}');

        $this->assertEquals(FORMAT_HTML, $DB->get_field('course_sections', 'summaryformat', ['id' => $this->section_id(1)]));
    }

    public function test_unknown_field_names_itself_and_the_allowed_fields(): void {
        $this->setup_course();

        $e = $this->caught(fn() => update_section::execute((int) $this->course->id, 1, '{"colour":"red"}'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('sectionunknownfield', $e->errorcode);
        $this->assertSame(['field' => 'colour', 'fields' => 'name, summary, visible'], $e->a);
    }

    /**
     * Provides invalid visibility values.
     *
     * @return array<string, array{string, string}>
     */
    public static function bad_visible_values(): array {
        return [
            'two' => ['2', '2'], 'text' => ['"1"', '"1"'], 'true' => ['true', 'true'], 'null' => ['null', 'null'],
            'negative' => ['-1', '-1'],
        ];
    }

    #[DataProvider('bad_visible_values')]
    public function test_visible_accepts_only_the_numbers_zero_and_one(string $json, string $shown): void {
        $this->setup_course();

        $e = $this->caught(fn() => update_section::execute((int) $this->course->id, 1, '{"visible":' . $json . '}'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('sectioninvalidvisible', $e->errorcode);
        $this->assertSame(['value' => $shown], $e->a);
    }

    public function test_unknown_section_names_its_number(): void {
        $this->setup_course();

        $e = $this->caught(fn() => update_section::execute((int) $this->course->id, 9, '{"name":"x"}'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('sectionnotfound', $e->errorcode);
        $this->assertSame(['sectionnum' => 9], $e->a);
    }

    /**
     * Provides documents that are not a patch object.
     *
     * @return array<string, array{string}>
     */
    public static function documents_that_are_no_patch(): array {
        return ['invalid' => ['not json'], 'number' => ['5'], 'string' => ['"x"'], 'null' => ['null']];
    }

    #[DataProvider('documents_that_are_no_patch')]
    public function test_document_that_is_no_json_object_is_refused(string $json): void {
        $this->setup_course();

        $e = $this->caught(fn() => update_section::execute((int) $this->course->id, 1, $json));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidpatchjson', $e->errorcode);
    }

    public function test_a_list_is_refused_as_patch(): void {
        $this->setup_course();

        $e = $this->caught(fn() => update_section::execute((int) $this->course->id, 1, '["name"]'));

        $this->assertInstanceOf(\coding_exception::class, $e);
        $this->assertStringContainsString('fields_json must be a JSON object, not an array.', $e->getMessage());
    }

    public function test_the_context_restriction_of_the_web_service_is_checked_first(): void {
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $e = $this->caught(fn() => update_section::execute((int) $this->course->id, 1, 'not json'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    /**
     * Provides the capabilities the tool requires.
     *
     * @return array<string, array{string}>
     */
    public static function required_capabilities(): array {
        return ['plugin use' => ['local/coursepilot:use'], 'course update' => ['moodle/course:update']];
    }

    #[DataProvider('required_capabilities')]
    public function test_each_capability_is_checked_before_the_patch_is_read(string $capability): void {
        global $DB;
        $this->setup_course();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $e = $this->caught(fn() => update_section::execute((int) $this->course->id, 1, 'not json'));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_a_summary_patch_without_format_resets_the_format_to_html_and_other_patches_keep_it(): void {
        global $DB;
        $this->setup_course();
        $id = $this->section_id(1);
        $DB->set_field('course_sections', 'summaryformat', FORMAT_MARKDOWN, ['id' => $id]);
        rebuild_course_cache($this->course->id, true);

        update_section::execute((int) $this->course->id, 1, '{"name":"Only the name"}');
        $this->assertEquals(FORMAT_MARKDOWN, $DB->get_field('course_sections', 'summaryformat', ['id' => $id]));

        update_section::execute((int) $this->course->id, 1, '{"summary":"<b>Now HTML</b>"}');
        $this->assertEquals(FORMAT_HTML, $DB->get_field('course_sections', 'summaryformat', ['id' => $id]));
    }

    public function test_the_change_report_keeps_umlauts_and_slashes_readable_in_both_values(): void {
        global $DB;
        $this->setup_course();
        $DB->set_field('course_sections', 'name', 'Ältere/Fassung', ['id' => $this->section_id(1)]);
        rebuild_course_cache($this->course->id, true);

        $result = update_section::execute((int) $this->course->id, 1, '{"name":"Größe/Neu"}');

        $this->assertSame(
            [['field' => 'name', 'before_json' => '"Ältere/Fassung"', 'after_json' => '"Größe/Neu"']],
            $result['changes']
        );
    }
}
