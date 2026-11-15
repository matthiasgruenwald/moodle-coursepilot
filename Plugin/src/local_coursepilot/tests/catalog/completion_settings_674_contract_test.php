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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The completion write reports vocabulary, refusals, locks and results exactly (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(completion_settings::class)]
final class completion_settings_674_contract_test extends \advanced_testcase {
    /**
     * Enables completion and signs in an editing teacher of a new course.
     *
     * @return \stdClass The course.
     */
    private function course_with_teacher(): \stdClass {
        set_config('enablecompletion', COMPLETION_ENABLED);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Creates an activity in the course and returns its course module id.
     *
     * @param string $modname
     * @param \stdClass $course
     * @param mixed[] $record
     * @return int
     */
    private function activity(string $modname, \stdClass $course, array $record = []): int {
        return (int) $this->getDataGenerator()->create_module($modname, ['course' => $course->id] + $record)->cmid;
    }

    /**
     * Runs a write that must be refused and returns the refusal.
     *
     * @param int $cmid
     * @param string $patch
     * @param string[] $confirmlearnerlocks
     * @return \moodle_exception
     */
    private function refusal(int $cmid, string $patch, array $confirmlearnerlocks = []): \moodle_exception {
        try {
            completion_settings::write($cmid, $patch, false, $confirmlearnerlocks);
        } catch (\moodle_exception $e) {
            return $e;
        }
        $this->fail('The write must be refused.');
    }

    /**
     * Stores one learner's completion state so the activity has completion data.
     *
     * @param int $cmid
     */
    private function seed_completion(int $cmid): void {
        global $DB;
        $DB->insert_record('course_modules_completion', [
            'coursemoduleid' => $cmid,
            'userid' => $this->getDataGenerator()->create_user()->id,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => time(),
        ]);
    }

    /**
     * Activity types and the completion columns they carry in their own table.
     *
     * @return array<string, array{string, string[]}>
     */
    public static function module_columns(): array {
        return [
            'assign' => ['assign', ['completionsubmit']],
            'choice' => ['choice', ['completionsubmit']],
            'quiz' => ['quiz', ['completionattemptsexhausted', 'completionminattempts']],
            'page' => ['page', []],
        ];
    }

    /**
     * Lists the activity type's own completion columns.
     *
     * @param string $modname
     * @param string[] $expected
     */
    #[DataProvider('module_columns')]
    public function test_module_fields_are_the_columns_of_the_activity_table(string $modname, array $expected): void {
        $this->assertSame($expected, completion_settings::module_fields($modname));
    }

    /**
     * Reports the writable fields of a page with their value ranges.
     */
    public function test_allowed_fields_of_a_page_carry_their_value_ranges(): void {
        $this->assertSame(
            [
                'completion' => [0, 1, 2],
                'completionview' => [0, 1],
                'completionusegrade' => [0, 1],
                'completionpassgrade' => [0, 1],
                'completionexpected' => null,
            ],
            completion_settings::allowed_fields('page')
        );
    }

    /**
     * Reads the current completion state of an activity.
     */
    public function test_read_reports_the_completion_state(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher(), ['completion' => COMPLETION_TRACKING_MANUAL]);

        $this->assertSame(COMPLETION_TRACKING_MANUAL, (int) completion_settings::read($cmid)['completion']);
    }

    /**
     * A write without the confirmation argument never discards learner data.
     */
    public function test_write_does_not_confirm_data_loss_by_default(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher(), ['completion' => COMPLETION_TRACKING_MANUAL]);
        $this->seed_completion($cmid);

        try {
            completion_settings::write($cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
            $this->fail('The reset of existing completion data must need a confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completiondatalossconfirmationrequired', $e->errorcode);
        }
    }

    /**
     * The refusal names the learners of this activity only.
     */
    public function test_data_loss_counts_only_the_learners_of_this_activity(): void {
        $this->resetAfterTest();
        $course = $this->course_with_teacher();
        $cmid = $this->activity('page', $course, ['completion' => COMPLETION_TRACKING_MANUAL]);
        $other = $this->activity('page', $course, ['completion' => COMPLETION_TRACKING_MANUAL]);
        $this->seed_completion($cmid);
        $this->seed_completion($cmid);
        $this->seed_completion($other);

        $this->assertSame(['affected_learners' => 2], $this->refusal($cmid, '{"completion":2}')->a);
    }

    /**
     * A user outside the course is stopped by the context check before any permission check.
     */
    public function test_a_user_outside_the_course_is_stopped_by_the_context_check(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\require_login_exception::class);
        completion_settings::write($cmid, '{"completionview":1}');
    }

    /**
     * Withdraws one capability from the editing teacher role in the activity.
     *
     * @param int $cmid
     * @param string $capability
     */
    private function withdraw(int $cmid, string $capability): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_module::instance($cmid)->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Capabilities the completion write demands.
     *
     * @return array<string, array{string}>
     */
    public static function required_capabilities(): array {
        return [
            'Coursepilot use' => ['local/coursepilot:use'],
            'native activity management' => ['moodle/course:manageactivities'],
        ];
    }

    /**
     * Refuses a teacher who lacks either capability and leaves the activity untouched.
     *
     * @param string $capability
     */
    #[DataProvider('required_capabilities')]
    public function test_each_required_capability_is_enforced(string $capability): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());
        $this->withdraw($cmid, $capability);

        try {
            // An unknown field proves the capability is checked before the patch is looked at.
            completion_settings::write($cmid, '{"bogus":1}');
            $this->fail('The missing capability must stop the write.');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string($capability), $e->a);
        }
    }

    /**
     * Patches that are valid JSON but not an object.
     *
     * @return array<string, array{string}>
     */
    public static function non_object_patches(): array {
        return ['number' => ['5'], 'string' => ['"text"'], 'null' => ['null'], 'boolean' => ['true']];
    }

    /**
     * Valid JSON that is no object is refused as an invalid patch.
     *
     * @param string $patch
     */
    #[DataProvider('non_object_patches')]
    public function test_a_json_patch_that_is_no_object_is_refused(string $patch): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame('invalidpatchjson', $this->refusal($cmid, $patch)->errorcode);
    }

    /**
     * A numeric patch key is no field name.
     */
    public function test_a_patch_with_a_numeric_key_is_refused_as_invalid_field_name(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame('invalidfieldname', $this->refusal($cmid, '[1]')->errorcode);
    }

    /**
     * An unknown field is refused with the field and the allowed vocabulary.
     */
    public function test_an_unknown_field_is_refused_with_the_allowed_vocabulary(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame(
            [
                'field' => 'bogus',
                'allowed_fields' => 'completion, completionview, completionusegrade, completionpassgrade, completionexpected',
            ],
            $this->refusal($cmid, '{"bogus":1}')->a
        );
    }

    /**
     * A field of another activity type is refused with the types that carry it.
     */
    public function test_a_foreign_module_field_is_refused_with_the_carrying_types(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame(
            ['field' => 'completionsubmit', 'modname' => 'page', 'modnames' => 'assign, choice'],
            $this->refusal($cmid, '{"completionsubmit":1}')->a
        );
    }

    /**
     * A value outside the field's range is refused with field and value.
     */
    public function test_a_value_outside_the_range_is_refused_with_field_and_value(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame(['field' => 'completion', 'value' => '5'], $this->refusal($cmid, '{"completion":5}')->a);
    }

    /**
     * A minimum above the quiz attempt limit is refused with field and value.
     */
    public function test_a_minimum_above_the_attempt_limit_is_refused_with_field_and_value(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('quiz', $this->course_with_teacher(), ['attempts' => 2]);

        $this->assertSame(
            ['field' => 'completionminattempts', 'value' => '3'],
            $this->refusal($cmid, '{"completion":2,"completionminattempts":3}')->a
        );
    }

    /**
     * A minimum equal to the quiz attempt limit is accepted.
     */
    public function test_a_minimum_equal_to_the_attempt_limit_is_accepted(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('quiz', $this->course_with_teacher(), ['attempts' => 2]);

        completion_settings::write($cmid, '{"completion":2,"completionminattempts":2}');

        $this->assertSame(2, (int) completion_settings::read($cmid)['completionminattempts']);
    }

    /**
     * A patch that repeats the current state reports that nothing changed.
     */
    public function test_a_patch_matching_the_current_state_reports_no_change(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame(
            [
                'cmid' => $cmid,
                'modname' => 'page',
                'message' => 'No change: the patch already matched the current state.',
                'changes' => [],
            ],
            completion_settings::write($cmid, '{"completionview":0}')
        );
    }

    /**
     * A patch that repeats the current state writes nothing to the activity.
     */
    public function test_a_patch_matching_the_current_state_does_not_update_the_activity(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());
        $sink = $this->redirectEvents();

        completion_settings::write($cmid, '{"completionview":0}');

        $this->assertSame([], $sink->get_events());
    }

    /**
     * The message names each changed field with its values in the order of the vocabulary.
     */
    public function test_the_message_lists_every_change_with_before_and_after(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('page', $this->course_with_teacher());

        $this->assertSame(
            'Completion changed: "completion" from 0 to 1, "completionview" from 0 to 1.',
            completion_settings::write($cmid, '{"completionview":1,"completion":1}')['message']
        );
    }

    /**
     * Activating completion through the teacher's grade names every field that waits for the teacher.
     */
    public function test_completion_by_teacher_grade_names_each_waiting_field(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('assign', $this->course_with_teacher());
        $detail = ' = 1: the activity only counts as complete once the teacher has graded it.';

        $this->assertSame(
            [
                'modname' => 'assign',
                'locks' => '"completionusegrade"' . $detail . ' | "completionpassgrade"' . $detail,
                'ids' => '["completionusegrade","completionpassgrade"]',
                'parameter' => 'confirm_learner_locks',
            ],
            $this->refusal(
                $cmid,
                '{"completion":2,"completionusegrade":1,"completionpassgrade":1}'
            )->a
        );
    }

    /**
     * Turning automatic completion on locks a grade requirement that was already set.
     */
    public function test_turning_automatic_completion_on_locks_an_existing_grade_requirement(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('assign', $this->course_with_teacher());
        completion_settings::write($cmid, '{"completion":1,"completionusegrade":1}');

        $this->assertSame('learnerlocksunconfirmed', $this->refusal($cmid, '{"completion":2}')->errorcode);
    }

    /**
     * Setting a grade requirement under automatic completion locks that requirement.
     */
    public function test_setting_a_grade_requirement_under_automatic_completion_is_locked(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('assign', $this->course_with_teacher());
        completion_settings::write($cmid, '{"completion":2}');

        $this->assertSame('learnerlocksunconfirmed', $this->refusal($cmid, '{"completionusegrade":1}')->errorcode);
    }

    /**
     * An unrelated change under an already confirmed grade lock needs no new confirmation.
     */
    public function test_an_unrelated_change_keeps_an_existing_grade_lock_open(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('assign', $this->course_with_teacher());
        completion_settings::write($cmid, '{"completion":2,"completionusegrade":1}', false, ['completionusegrade']);

        completion_settings::write($cmid, '{"completionview":1}');

        $this->assertSame(1, (int) completion_settings::read($cmid)['completionview']);
    }

    /**
     * Reads the completion grade item number stored for the activity.
     *
     * @param int $cmid
     * @return string|null
     */
    private function stored_grade_item_number(int $cmid): ?string {
        global $DB;
        $value = $DB->get_field('course_modules', 'completiongradeitemnumber', ['id' => $cmid]);
        return $value === null ? null : (string) $value;
    }

    /**
     * A grade requirement binds completion to the first grade item.
     */
    public function test_a_grade_requirement_stores_the_first_grade_item(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('quiz', $this->course_with_teacher());

        completion_settings::write($cmid, '{"completion":2,"completionusegrade":1}');

        $this->assertSame('0', $this->stored_grade_item_number($cmid));
    }

    /**
     * Dropping the grade requirement clears the stored grade item.
     */
    public function test_dropping_the_grade_requirement_clears_the_grade_item(): void {
        $this->resetAfterTest();
        $cmid = $this->activity('quiz', $this->course_with_teacher());
        completion_settings::write($cmid, '{"completion":2,"completionusegrade":1}');

        completion_settings::write($cmid, '{"completionusegrade":0}');

        $this->assertNull($this->stored_grade_item_number($cmid));
    }
}
