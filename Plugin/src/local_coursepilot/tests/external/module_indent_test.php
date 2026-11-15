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
use local_coursepilot\catalog\cm_indent;
use local_coursepilot\catalog\shared_block;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Indentation of an activity on the course page, a field shared by every activity type.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(cm_indent::class)]
#[CoversClass(shared_block::class)]
#[CoversClass(update_module_settings::class)]
#[CoversClass(create_module::class)]
#[CoversClass(get_modules::class)]
#[CoversClass(\local_coursepilot\catalog\module_state::class)]
#[CoversClass(\local_coursepilot\catalog\write_target::class)]
final class module_indent_test extends \advanced_testcase {
    /**
     * Activity types that exercise the shared write path with real instances.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function module_types(): array {
        return [
            'url' => ['url', ['externalurl' => 'https://example.org/chat']],
            'assign' => ['assign', ['name' => 'Aufgabe']],
            'page' => ['page', ['name' => 'Seite']],
        ];
    }

    /**
     * Activity types with the fields their creation needs besides the name.
     *
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function creatable_types(): array {
        return [
            'url' => ['url', ['externalurl' => 'https://example.org/chat']],
            'assign' => ['assign', ['intro' => 'Aufgabentext']],
            'page' => ['page', ['page' => 'Inhalt']],
        ];
    }

    /**
     * Logs in an editing teacher in a new course.
     *
     * @return \stdClass The course.
     */
    private function course_with_editing_teacher(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Current state as get_module_settings returns it.
     *
     * @param int $cmid
     * @return mixed[]
     */
    private function settings(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * Provides the indentation get_modules reports for one activity.
     *
     * @param int $courseid
     * @param int $cmid
     * @return int
     */
    private function listed_indent(int $courseid, int $cmid): int {
        $modules = external_api::clean_returnvalue(get_modules::execute_returns(), get_modules::execute($courseid));
        foreach ($modules as $module) {
            if ($module['cmid'] === $cmid) {
                return $module['indent'];
            }
        }
        $this->fail("Activity $cmid is not listed by get_modules.");
    }

    /**
     * A new activity is not indented, and every read tool says so.
     */
    public function test_new_activity_is_not_indented_in_every_read_tool(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->assertSame(0, $this->settings($page->cmid)['indent']);
        $this->assertSame(0, $this->listed_indent($course->id, (int) $page->cmid));
    }

    /**
     * Indenting and un-indenting works for different activity types and is reported as a change.
     *
     * @param string $modname
     * @param array<string, mixed> $record
     */
    #[DataProvider('module_types')]
    public function test_indent_is_set_and_cleared(string $modname, array $record): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $module = $this->getDataGenerator()->create_module($modname, ['course' => $course->id] + $record);

        $result = external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($module->cmid, json_encode(['indent' => 1]))
        );

        $this->assertSame([['field' => 'indent', 'before_json' => '0', 'after_json' => '1']], $result['changes']);
        $this->assertSame(1, $this->settings($module->cmid)['indent']);
        $this->assertSame(1, $this->listed_indent($course->id, (int) $module->cmid));

        update_module_settings::execute($module->cmid, json_encode(['indent' => 0]));

        $this->assertSame(0, $this->settings($module->cmid)['indent']);
    }

    /**
     * Indenting alongside another field writes both and keeps the other settings.
     */
    public function test_indent_together_with_another_field_keeps_the_rest(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);

        update_module_settings::execute($page->cmid, json_encode(['indent' => 1, 'name' => 'Neu']));

        $after = $this->settings($page->cmid);
        $this->assertSame(1, $after['indent']);
        $this->assertSame('Neu', $after['name']);
        $this->assertSame(1, $after['visible']);
    }

    /**
     * Another write leaves an existing indentation alone.
     */
    public function test_other_patch_keeps_the_indentation(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));

        update_module_settings::execute($page->cmid, json_encode(['name' => 'Umbenannt']));

        $this->assertSame(1, $this->settings($page->cmid)['indent']);
    }

    /**
     * Values other than 0 and 1 are refused with the field named, and nothing is written.
     *
     * @return array<string, array{mixed}>
     */
    public static function invalid_values(): array {
        return ['two' => [2], 'sixteen' => [16], 'negative' => [-1], 'text' => ['deep']];
    }

    /**
     * An out-of-range indentation is refused and the activity stays as it was.
     *
     * @param mixed $value
     */
    #[DataProvider('invalid_values')]
    public function test_invalid_indent_is_refused_and_nothing_is_written(mixed $value): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);

        try {
            update_module_settings::execute($page->cmid, json_encode(['indent' => $value, 'name' => 'Nie']));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidfieldvalue', $e->errorcode);
            $this->assertStringContainsString('"indent"', $e->getMessage());
        }
        $after = $this->settings($page->cmid);
        $this->assertSame(0, $after['indent']);
        $this->assertSame('Seite', $after['name']);
    }

    /**
     * A course format without indentation refuses to indent, names the reason and writes nothing.
     */
    public function test_format_without_indentation_refuses_to_indent(): void {
        $this->resetAfterTest();
        set_config('indentation', 0, 'format_topics');
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);

        try {
            update_module_settings::execute($page->cmid, json_encode(['indent' => 1, 'name' => 'Nie']));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('indentationnotsupported', $e->errorcode);
        }
        $after = $this->settings($page->cmid);
        $this->assertSame(0, $after['indent']);
        $this->assertSame('Seite', $after['name']);
    }

    /**
     * Resetting to 0 stays possible where the format does not use indentation.
     */
    public function test_format_without_indentation_still_accepts_zero(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));
        set_config('indentation', 0, 'format_topics');

        update_module_settings::execute($page->cmid, json_encode(['indent' => 0]));

        $this->assertSame(0, $this->settings($page->cmid)['indent']);
    }

    /**
     * Creating an activity with an indentation stores it for different types.
     *
     * @param string $modname
     * @param array<string, mixed> $record
     */
    #[DataProvider('creatable_types')]
    public function test_indent_is_set_when_creating(string $modname, array $record): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $fields = ['name' => 'Neu', 'indent' => 1] + $record;

        $result = external_api::clean_returnvalue(
            create_module::execute_returns(),
            create_module::execute($course->id, 1, $modname, json_encode($fields))
        );

        $this->assertSame(1, $this->settings($result['cmid'])['indent']);
        $this->assertContains('indent', array_column($result['created_fields'], 'field'));
    }

    /**
     * An invalid indentation on creation leaves no activity behind.
     */
    public function test_invalid_indent_on_create_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $before = $DB->count_records('course_modules', ['course' => $course->id]);

        try {
            create_module::execute($course->id, 1, 'url', json_encode([
                'name' => 'Neu', 'externalurl' => 'https://example.org', 'indent' => 5,
            ]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidfieldvalue', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * The field catalog lists the indentation with its allowed values for every activity type.
     */
    public function test_describe_module_fields_lists_indent(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        foreach (['url', 'assign', 'quiz'] as $modname) {
            $result = external_api::clean_returnvalue(
                describe_module_fields::execute_returns(),
                describe_module_fields::execute($modname, true)
            );
            $fields = array_column($result['module']['fields'], null, 'name');
            $this->assertArrayHasKey('indent', $fields, $modname);
            $this->assertSame('[0,1]', $fields['indent']['value_range']['values_json'], $modname);
        }
    }

    /**
     * An indented quiz keeps its indentation through the quiz write route.
     */
    public function test_quiz_settings_update_reports_and_stores_indent(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Test']);

        external_api::clean_returnvalue(
            update_quiz_settings::execute_returns(),
            update_quiz_settings::execute($quiz->cmid, json_encode(['indent' => 1]))
        );

        $this->assertSame(1, $this->settings($quiz->cmid)['indent']);
        $this->assertSame(1, $this->listed_indent($course->id, (int) $quiz->cmid));
    }

    /**
     * Number of recorded versions of an activity.
     *
     * @param int $cmid
     * @return int
     */
    private function version_count(int $cmid): int {
        global $DB;
        return $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]);
    }

    /**
     * Writes that name the indentation record exactly one new version.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function update_patches(): array {
        return ['indent only' => [['indent' => 1]], 'indent and name' => [['indent' => 1, 'name' => 'Neu']]];
    }

    /**
     * An update with an indentation leaves one version behind, not an extra one for the indentation.
     *
     * @param array<string, mixed> $patch
     */
    #[DataProvider('update_patches')]
    public function test_update_with_indent_records_one_version(array $patch): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);
        update_module_settings::execute($page->cmid, json_encode(['name' => 'Anders']));
        $before = $this->version_count((int) $page->cmid);

        update_module_settings::execute($page->cmid, json_encode($patch));

        $this->assertSame($before + 1, $this->version_count((int) $page->cmid));
        $this->assertSame(1, $this->settings($page->cmid)['indent']);
    }

    /**
     * A quiz update with an indentation records one version as well.
     */
    public function test_quiz_update_with_indent_records_one_version(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Test']);
        update_quiz_settings::execute($quiz->cmid, json_encode(['name' => 'Anders']));
        $before = $this->version_count((int) $quiz->cmid);

        update_quiz_settings::execute($quiz->cmid, json_encode(['indent' => 1]));

        $this->assertSame($before + 1, $this->version_count((int) $quiz->cmid));
        $this->assertSame(1, $this->settings($quiz->cmid)['indent']);
    }

    /**
     * Repeating the current indentation is not refused where the format shows none.
     */
    public function test_repeating_the_current_indent_is_accepted_without_format_support(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));
        set_config('indentation', 0, 'format_topics');

        $result = update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));

        $this->assertSame([], $result['changes']);
        $this->assertSame(1, $this->settings($page->cmid)['indent']);
    }

    /**
     * Restoring an older version brings the indentation back.
     */
    public function test_restore_brings_the_indentation_back(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));
        $indented = $this->version_count((int) $page->cmid);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 0, 'name' => 'Spaeter']));

        restore_activity_version::execute($page->cmid, $indented);

        $state = $this->settings($page->cmid);
        $this->assertSame(1, $state['indent']);
        $this->assertSame('Seite', $state['name']);
    }

    /**
     * A version recorded before the field existed restores without touching the indentation.
     */
    public function test_restore_of_a_version_without_indent_keeps_the_indentation(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);
        $row = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $page->cmid, 'version' => 1], '*', MUST_EXIST);
        foreach (['moduleinfo_json', 'coursemodule_json'] as $column) {
            $recorded = json_decode($row->$column, true);
            unset($recorded['indent']);
            $DB->set_field('local_coursepilot_cm_version', $column, json_encode($recorded), ['id' => $row->id]);
        }
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1, 'name' => 'Spaeter']));

        restore_activity_version::execute($page->cmid, 1);

        $state = $this->settings($page->cmid);
        $this->assertSame('Seite', $state['name']);
        $this->assertSame(1, $state['indent']);
    }

    /**
     * Where the format no longer shows indentation, a restore still brings back the rest of the version.
     */
    public function test_restore_skips_the_indentation_without_format_support(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));
        $indented = $this->version_count((int) $page->cmid);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 0, 'name' => 'Spaeter']));
        set_config('indentation', 0, 'format_topics');

        restore_activity_version::execute($page->cmid, $indented);

        $state = $this->settings($page->cmid);
        $this->assertSame('Seite', $state['name']);
        $this->assertSame(0, $state['indent']);
    }

    /**
     * A quiz created with an indentation is indented.
     */
    public function test_create_quiz_sets_indent(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();

        $result = external_api::clean_returnvalue(
            create_quiz::execute_returns(),
            create_quiz::execute($course->id, 1, json_encode([
                'name' => 'Test', 'intro' => 'Beschreibung', 'subnet' => '', 'browsersecurity' => '-',
                'preferredbehaviour' => 'deferredfeedback', 'indent' => 1,
            ]))
        );

        $this->assertSame(1, $this->settings($result['cmid'])['indent']);
    }

    /**
     * A clone keeps the indentation of its original.
     */
    public function test_clone_keeps_the_indentation(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Seite']);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));

        $result = external_api::clean_returnvalue(
            clone_activity::execute_returns(),
            clone_activity::execute((int) $page->cmid, 'Kopie')
        );

        $this->assertSame(1, $this->settings((int) $result['cmid'])['indent']);
    }

    /**
     * Setting completion leaves the indentation alone.
     */
    public function test_set_completion_keeps_the_indentation(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));

        set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_MANUAL]));

        $this->assertSame(1, $this->settings($page->cmid)['indent']);
    }

    /**
     * Without the edit capability the indentation is refused and unchanged.
     */
    public function test_indent_needs_the_edit_capability(): void {
        $this->resetAfterTest();
        $course = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        try {
            update_module_settings::execute($page->cmid, json_encode(['indent' => 1]));
            $this->fail('Expected exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('', $e->errorcode);
        }
        $this->setAdminUser();
        $this->assertSame(0, $this->settings($page->cmid)['indent']);
    }
}
