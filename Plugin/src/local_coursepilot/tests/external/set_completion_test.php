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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The only write path for completion fields, in the named
 * two-step flow (Spec 0015 §8, ticket #392).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(set_completion::class)]
final class set_completion_test extends \advanced_testcase {
    /**
     * Provides course with editing teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass} Course (completion tracking on), teacher (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        set_config('enablecompletion', COMPLETION_ENABLED);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * Returns current state, same shape as get_module_settings.
     *
     * @param int $cmid
     * @return array Current state, same shape as get_module_settings.
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * Creates a real course_modules_completion row for $userid on $cmid
     * - simulates existing learner completion data without having to run the whole
     * Moodle completion engine.
     *
     * @param int $cmid
     * @param int $userid
     * @return void
     */
    private function seed_completion_data(int $cmid, int $userid): void {
        global $DB;
        $DB->insert_record('course_modules_completion', [
            'coursemoduleid' => $cmid,
            'userid' => $userid,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => time(),
        ]);
    }

    /**
     * The four lock fields fail in update_module_settings (blocklist) -
     * "completionusegrade" and "completionunlocked" are #392's addition
     * next to the fields already blocked by #388.
     */
    public function test_all_completion_fields_are_blocked_in_update_module_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        foreach (
            ['completion', 'completionview', 'completionexpected', 'completionusegrade',
                'completionpassgrade', 'completionunlocked'] as $field
        ) {
            try {
                update_module_settings::execute($page->cmid, json_encode([$field => 1]));
                $this->fail('Expected moodle_exception was not thrown for field "' . $field . '".');
            } catch (\moodle_exception $e) {
                $this->assertSame('completionfieldviasetcompletion', $e->errorcode);
                $this->assertStringContainsString($field, $e->getMessage());
                $this->assertStringContainsString('set_completion', $e->getMessage());
            }
        }
    }

    /**
     * An assignment keeps its active submission types when only the
     * completion tracking is written.
     *
     * mod_assign derives "nosubmissions" on every write from the
     * enabled submission types; get_moduleinfo_data() does not supply these fields.
     * Without carrying the current state forward, set_completion silently disabled them
     * - the assignment then no longer accepted submissions (#400).
     */
    public function test_assign_keeps_its_submission_types(): void {
        $this->resetAfterTest();
        global $DB;
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $this->assertEquals(0, $DB->get_field('assign', 'nosubmissions', ['id' => $assign->id]));

        external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, json_encode(['completion' => 1]))
        );

        $this->assertEquals(0, $DB->get_field('assign', 'nosubmissions', ['id' => $assign->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'completion', ['id' => $cmid]));
    }

    /**
     * The same fields also fail on creation (create_module).
     */
    public function test_completion_field_is_blocked_in_create_module(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        try {
            create_module::execute($course->id, 0, 'page', json_encode([
                'name' => 'x',
                'page' => ['text' => 'x', 'format' => FORMAT_HTML],
                'completionview' => 1,
            ]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionfieldviasetcompletion', $e->errorcode);
            $this->assertStringContainsString('completionview', $e->getMessage());
            $this->assertStringContainsString('set_completion', $e->getMessage());
        }
    }

    /**
     * First call that would delete existing completion data: does
     * NOT execute, reports the data loss in teacher-facing English.
     */
    public function test_first_call_with_data_loss_does_not_execute_and_reports_it(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        try {
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('1', $e->getMessage());
            $this->assertStringContainsString('confirmed', $e->getMessage());
        }

        // Nothing written.
        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($page->cmid)['completion']);
        global $DB;
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
    }

    /**
     * Second call with confirmed: true executes.
     */
    public function test_second_call_with_confirmation_executes(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]), true)
        );

        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($page->cmid)['completion']);
        $this->assertNotEmpty($result['changes']);
        $this->assertStringContainsString('completion', $result['message']);
    }

    /**
     * A change of completion tracking without existing learner data
     * (freshly created activity) runs through without the two-step flow.
     */
    public function test_change_without_existing_data_runs_without_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionview' => 1]))
        );

        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($page->cmid)['completion']);
        $this->assertSame(1, $this->read($page->cmid)['completionview']);
        $this->assertNotEmpty($result['changes']);
    }

    /**
     * "completionexpected" alone never triggers the two-step flow, even with
     * existing learner data - Moodle writes it regardless of the lock
     * state (modlib.php).
     */
    public function test_completionexpected_alone_never_needs_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        $expected = time() + DAYSECS;
        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completionexpected' => $expected]))
        );

        $this->assertSame($expected, $this->read($page->cmid)['completionexpected']);
        $this->assertNotEmpty($result['changes']);

        // Existing completion data stays untouched.
        global $DB;
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($page->cmid)['completion']);
    }

    /**
     * Changing another field of the activity (update_module_settings) leaves
     * existing completion data untouched - via every path (acceptance criterion).
     */
    public function test_other_field_change_via_update_module_settings_leaves_completion_data_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        update_module_settings::execute($page->cmid, json_encode(['name' => 'Neu']));

        $this->assertSame('Neu', $this->read($page->cmid)['name']);
        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($page->cmid)['completion']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
    }

    /**
     * "completionunlocked" is never set automatically: a change that
     * actually changes none of the four lock fields calls
     * update_moduleinfo() without "completionunlocked" and therefore triggers
     * no reset_all_state() - verified indirectly via unchanged
     * completion data for a plain patch to the value already in effect.
     */
    public function test_patch_matching_current_value_does_not_touch_completion_data(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($page->cmid, $student->id);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_MANUAL]))
        );

        $this->assertEmpty($result['changes']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $page->cmid]));
    }

    /**
     * An unknown field fails before any write access.
     */
    public function test_unknown_field_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['name' => 'x']));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }
    }

    /**
     * A value outside the allowed range fails.
     */
    public function test_invalid_value_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['completion' => 99]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('completion', $e->getMessage());
        }
    }

    /**
     * Without completion tracking enabled in the course, the call fails with a
     * clear message instead of letting Moodle silently discard the fields.
     */
    public function test_completion_disabled_for_course_is_rejected(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionnotenabled', $e->errorcode);
        }
    }

    /**
     * Native capability check in the course context: without
     * moodle/course:manageactivities the write fails.
     */
    public function test_write_without_native_capability_fails(): void {
        $this->resetAfterTest();
        set_config('enablecompletion', COMPLETION_ENABLED);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        set_completion::execute($page->cmid, json_encode(['completionview' => 1]));
    }

    /**
     * The write creates a state in the change history - via
     * the same course_module_updated observer as any other
     * update_moduleinfo() call (no mechanism of its own).
     */
    public function test_write_creates_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        set_completion::execute($page->cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));

        $this->assertGreaterThan(
            0,
            $DB->count_records('local_coursepilot_cm_version', ['cmid' => $page->cmid])
        );
    }

    /**
     * "completionsubmit" ("submission required") is the actually intended
     * completion condition for an assignment - it goes through the same
     * write path as the generic fields (#461).
     */
    public function test_assign_completionsubmit_is_written(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, json_encode([
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'completionsubmit' => 1,
            ]))
        );

        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($cmid)['completion']);
        $this->assertStringContainsString('completionsubmit', $result['message']);
        // The submission types stay untouched (#400).
        $this->assertEquals(0, $DB->get_field('assign', 'nosubmissions', ['id' => $assign->id]));
    }

    /**
     * The same field for "choice" ("vote cast") - the second and
     * last module type with a module-specific completion field.
     */
    public function test_choice_completionsubmit_is_written(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $choice = $this->getDataGenerator()->get_plugin_generator('mod_choice')->create_instance([
            'course' => $course->id,
        ]);
        $cmid = (int) get_coursemodule_from_instance('choice', $choice->id)->id;

        set_completion::execute($cmid, json_encode([
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionsubmit' => 1,
        ]));

        $this->assertEquals(1, $DB->get_field('choice', 'completionsubmit', ['id' => $choice->id]));
        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($cmid)['completion']);
    }

    /**
     * The field is module-specific: for any other activity type the call fails
     * with a signpost instead of "Unknown field".
     */
    public function test_completionsubmit_is_rejected_for_other_modnames(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_completion::execute($page->cmid, json_encode(['completionsubmit' => 1]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionfieldnotformodname', $e->errorcode);
            $this->assertStringContainsString('assign', $e->getMessage());
            $this->assertStringContainsString('choice', $e->getMessage());
        }
    }

    /**
     * "completionsubmit" is a lock field like the four generic ones: Moodle
     * writes it only with "completionunlocked" (mod/assign/locallib.php:
     * update_instance()), and that deletes the learners' completion data -
     * so the same two-step flow.
     */
    public function test_completionsubmit_change_needs_confirmation_when_data_exists(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        try {
            set_completion::execute($cmid, json_encode(['completionsubmit' => 1]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completiondatalossconfirmationrequired', $e->errorcode);
        }
        $this->assertEquals(0, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));

        set_completion::execute($cmid, json_encode(['completionsubmit' => 1]), true);
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));
    }

    /**
     * A patch that merely repeats the current value changes nothing and
     * triggers no two-step flow - also for the module-specific field.
     */
    public function test_completionsubmit_patch_matching_current_value_is_a_noop(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, json_encode(['completionsubmit' => 0]))
        );

        $this->assertEmpty($result['changes']);
    }

    /**
     * The detour via update_module_settings stays blocked for both module
     * types - an incidental patch must not change completion rules.
     */
    public function test_completionsubmit_is_blocked_in_update_module_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
        ]);
        $choice = $this->getDataGenerator()->get_plugin_generator('mod_choice')->create_instance([
            'course' => $course->id,
        ]);

        foreach (['assign' => $assign->id, 'choice' => $choice->id] as $modname => $instanceid) {
            $cmid = (int) get_coursemodule_from_instance($modname, $instanceid)->id;
            try {
                update_module_settings::execute($cmid, json_encode(['completionsubmit' => 1]));
                $this->fail('Expected moodle_exception was not thrown for "' . $modname . '".');
            } catch (\moodle_exception $e) {
                $this->assertSame('completionfieldviasetcompletion', $e->errorcode);
                $this->assertStringContainsString('completionsubmit', $e->getMessage());
                $this->assertStringContainsString('set_completion', $e->getMessage());
            }
        }
    }

    /**
     * Guard (#583): completion via the grade of a teacher-graded assignment
     * waits for the teacher - rejected without confirmation.
     */
    public function test_completion_by_teacher_grade_needs_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $aufgabe = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $patch = json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC, 'completionusegrade' => 1]);

        try {
            set_completion::execute($aufgabe->cmid, $patch);
            $this->fail('Completion via teacher grade should have required confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
            $this->assertStringContainsString('completionusegrade', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->read($aufgabe->cmid)['completionusegrade']);

        set_completion::execute($aufgabe->cmid, $patch, false, ['completionusegrade']);
        $this->assertSame(1, (int) $this->read($aufgabe->cmid)['completionusegrade']);
    }

    /**
     * An automatically graded quiz needs no confirmation for this.
     */
    public function test_completion_by_quiz_grade_needs_no_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $test = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        set_completion::execute($test->cmid, json_encode([
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
        ]));

        $this->assertSame(1, (int) $this->read($test->cmid)['completionusegrade']);
    }
}
