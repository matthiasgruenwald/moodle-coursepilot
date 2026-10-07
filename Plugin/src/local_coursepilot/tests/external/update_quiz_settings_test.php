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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Dedicated quiz patch tool (Spec 0015 §5, issue #398).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_quiz_settings::class)]
final class update_quiz_settings_test extends \advanced_testcase {

    /**
     * Moodle 5.3's informational due date survives a later unrelated patch.
     */
    public function test_due_date_can_be_changed_without_resetting_other_settings(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        if ((int) $CFG->branch < 503) {
            $this->markTestSkipped('Quiz due dates require Moodle 5.3.');
        }
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id, 'timelimit' => 600,
        ]);
        $duedate = time() + DAYSECS;
        $result = external_api::clean_returnvalue(update_quiz_settings::execute_returns(),
            update_quiz_settings::execute($quiz->cmid, json_encode(['duedate' => $duedate])));
        $this->assertSame('duedate', $result['changes'][0]['field']);
        external_api::clean_returnvalue(update_quiz_settings::execute_returns(),
            update_quiz_settings::execute($quiz->cmid, json_encode(['name' => 'Neuer Titel'])));
        $after = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertEquals($duedate, $after->duedate);
        $this->assertEquals(600, $after->timelimit);
        $this->assertSame('Neuer Titel', $after->name);
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass} Course, teacher (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * @param int $cmid
     * @param array $felder
     * @param string $mode
     * @param float $grade
     * @param string[] $confirmlearnerlocks Explicitly confirmed learner restrictions (#583).
     * @return array
     */
    private function patch(
        int $cmid,
        array $felder,
        string $mode = '',
        float $grade = -1.0,
        array $confirmlearnerlocks = []
    ): array {
        return external_api::clean_returnvalue(
            update_quiz_settings::execute_returns(),
            update_quiz_settings::execute($cmid, json_encode($felder), $mode, $grade, $confirmlearnerlocks)
        );
    }

    /**
     * @param int $cmid
     * @return \stdClass Raw quiz table row.
     */
    private function raw_quiz(int $cmid): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        return $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /** An 80% requirement is supplied as points, never as a percentage. */
    #[DataProvider('passing_grades')]
    public function test_gradepass_is_persisted_in_quiz_grade_points(float $maximum, float $passing): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => $maximum]);

        $result = $this->patch($quiz->cmid, ['gradepass' => $passing]);

        $item = $DB->get_record('grade_items', [
            'itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id, 'itemnumber' => 0,
        ], '*', MUST_EXIST);
        $this->assertEqualsWithDelta($passing, (float) $item->gradepass, 0.00001);
        $this->assertEqualsWithDelta($maximum, (float) $item->grademax, 0.00001);
        $changes = array_column($result['changes'], null, 'field');
        $this->assertEquals($passing, json_decode($changes['gradepass']['after_json']));
    }

    public static function passing_grades(): array {
        return [
            'ten points' => [10.0, 8.0], 'twenty-five points' => [25.0, 20.0],
            'fractional maximum' => [12.5, 10.0], 'inclusive maximum' => [10.0, 10.0],
        ];
    }

    /** Read tools and the published field catalog agree on points and persisted values. */
    public function test_gradepass_readback_and_contract_are_consistent(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
        $this->patch($quiz->cmid, ['gradepass' => 8.125]);

        $read = external_api::clean_returnvalue(get_module_settings::execute_returns(), get_module_settings::execute($quiz->cmid));
        $settings = json_decode($read['settings_json'], true);
        $this->assertSame(8.125, $settings['gradepass']);
        $this->assertEquals(10, $settings['grademax']);
        $catalog = external_api::clean_returnvalue(
            get_course_catalog::execute_returns(), get_course_catalog::execute($course->id, -1, 'quiz', 'full')
        );
        foreach ($catalog['sections'] as $section) {
            foreach ($section['modules'] as $module) {
                if ($module['cmid'] === (int) $quiz->cmid) {
                    $values = array_column($module['settings'], 'value', 'name');
                    $this->assertSame('8.125', $values['gradepass']);
                    $this->assertSame('10', $values['grademax']);
                }
            }
        }
        $fields = describe_module_fields::execute('quiz', true)['module'];
        $passing = array_column($fields['pseudo_fields'], null, 'name')['gradepass'];
        $this->assertSame('PARAM_FLOAT', $passing['type']);
        $this->assertStringContainsString('NOT percent', $passing['meaning']);
        $this->assertStringContainsString('0 to the maximum', $passing['meaning']);
    }

    /** Changing the threshold preserves questions, pages, sections, feedback and settings. */
    public function test_gradepass_preserves_existing_quiz_content_and_settings(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'grade' => 10, 'password' => 'unchanged', 'timelimit' => 123,
        ]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $questions = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questions->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        foreach ([1, 2] as $page) {
            $question = $questions->create_question('truefalse', null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quiz, $page);
        }
        $this->patch($quiz->cmid, ['feedbacktext' => ['Pass', 'Retry'], 'feedbackboundaries' => [8]]);
        $before = json_decode(get_module_settings::execute($quiz->cmid)['settings_json'], true);
        $slots = $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot');
        $sections = $DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot');
        $references = $DB->get_records('question_references', ['component' => 'mod_quiz'], 'id');
        $feedback = \local_coursepilot\catalog\quiz_write_bridge::read_feedback($quiz->id);

        foreach ([8, 7.5] as $passing) {
            $this->patch($quiz->cmid, ['gradepass' => $passing]);
            $after = json_decode(get_module_settings::execute($quiz->cmid)['settings_json'], true);
            unset($before['timemodified'], $before['gradepass'], $after['timemodified'], $after['gradepass']);
            $this->assertSame($before, $after);
            $this->assertEquals($slots, $DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot'));
            $this->assertEquals($sections, $DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot'));
            $this->assertEquals($references, $DB->get_records('question_references', ['component' => 'mod_quiz'], 'id'));
            $this->assertSame($feedback, \local_coursepilot\catalog\quiz_write_bridge::read_feedback($quiz->id));
        }
    }

    /** Invalid thresholds reject the entire call before even changing the maximum grade. */
    #[DataProvider('invalid_passing_grades')]
    public function test_invalid_gradepass_rejects_the_entire_patch(mixed $passing): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
        $before = get_module_settings::execute($quiz->cmid)['settings_json'];
        $versions = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $quiz->cmid]);

        try {
            $this->patch($quiz->cmid, ['name' => 'Must not persist', 'gradepass' => $passing], '', 5.0);
            $this->fail('Invalid gradepass must reject the whole patch.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidquizgradepass', $e->errorcode);
            $this->assertStringContainsString('grade points', $e->getMessage());
            $this->assertStringContainsString('0 to 5', $e->getMessage());
        }
        $this->assertSame($before, get_module_settings::execute($quiz->cmid)['settings_json']);
        $this->assertSame($versions, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $quiz->cmid]));
    }

    public static function invalid_passing_grades(): array {
        return [
            'negative' => [-0.01], 'above new maximum' => [8], 'percent mistaken for points' => [80],
            'percent text' => ['80%'], 'numeric string' => ['4.5'], 'locale string' => ['4,5'],
            'empty string' => [''], 'null' => [null], 'boolean' => [true], 'array' => [[4]],
            'object' => [(object) ['value' => 4]], 'non-finite text' => ['INF'],
        ];
    }

    /** JSON can decode an overflowing numeric literal to infinity. */
    public function test_nonfinite_numeric_gradepass_is_rejected_before_writing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
        $before = get_module_settings::execute($quiz->cmid)['settings_json'];
        try {
            update_quiz_settings::execute($quiz->cmid, '{"name":"Must not persist","gradepass":1e309}', '', 5.0);
            $this->fail('Infinity must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidquizgradepass', $e->errorcode);
        }
        $this->assertSame($before, get_module_settings::execute($quiz->cmid)['settings_json']);
    }

    /** New maximum, decimal points and disabling the threshold survive the Moodle lifecycle. */
    public function test_gradepass_uses_new_maximum_and_can_be_disabled(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
        force_current_language('de');
        $this->patch($quiz->cmid, ['gradepass' => 20], '', 25.0);
        $settings = json_decode(get_module_settings::execute($quiz->cmid)['settings_json'], true);
        $this->assertSame(20, $settings['gradepass']);
        $this->assertSame(25, $settings['grademax']);
        $this->patch($quiz->cmid, ['gradepass' => 19.125]);
        $this->patch($quiz->cmid, ['intro' => 'Unrelated patch']);
        $settings = json_decode(get_module_settings::execute($quiz->cmid)['settings_json'], true);
        $this->assertSame(19.125, $settings['gradepass']);
        $this->patch($quiz->cmid, ['gradepass' => 0]);
        $settings = json_decode(get_module_settings::execute($quiz->cmid)['settings_json'], true);
        $this->assertSame(0, $settings['gradepass']);
    }

    /**
     * Changing the description preserves overall feedback (criterion 2).
     */
    public function test_updating_intro_preserves_overall_feedback(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'intro' => 'Alte Beschreibung',
            'grade' => 100,
        ]);

        $this->patch($quiz->cmid, [
            'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
            'feedbackboundaries' => [50],
        ], '', 100.0);

        $this->patch($quiz->cmid, ['intro' => 'Neue Beschreibung']);

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertSame('Neue Beschreibung', $raw->intro);
        $records = $DB->get_records('quiz_feedback', ['quizid' => $raw->id]);
        $this->assertCount(2, $records, 'Overall feedback must not disappear through an unrelated patch.');
    }

    /**
     * Mode presets preserve explicit fields (criterion 3).
     */
    public function test_bundle_does_not_override_explicitly_named_fields(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        $this->patch($quiz->cmid, ['attempts' => 9], 'mini-check', -1.0, ['attempts']);

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertEquals(9, $raw->attempts);
        $this->assertSame('immediatefeedback', $raw->preferredbehaviour, 'Bundle must otherwise apply.');
    }

    /**
     * grade uses Moodle’s grade_calculator::update_quiz_maximum_grade(),
     * not fields_json (criterion 4).
     */
    public function test_grade_via_felder_json_is_blocked(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        try {
            $this->patch($quiz->cmid, ['grade' => 50]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('grade', $e->getMessage());
        }
    }

    /**
     * Changing the dedicated grade parameter proportionally scales existing
     * feedback boundaries, proving use of Moodle’s grade calculator
     * rather than direct database writes.
     */
    public function test_grade_parameter_changes_grade_via_native_path(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'grade' => 100,
        ]);

        $this->patch($quiz->cmid, [
            'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
            'feedbackboundaries' => [50],
        ], '', 100.0);

        $this->patch($quiz->cmid, [], '', 50.0);

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertEqualsWithDelta(50.0, (float) $raw->grade, 0.0001);

        $records = array_values($DB->get_records('quiz_feedback', ['quizid' => $raw->id], 'mingrade DESC'));
        $feedback = current(array_filter($records, fn ($record) => $record->feedbacktext === 'Bestanden'));
        $this->assertEqualsWithDelta(25.0, (float) $feedback->mingrade, 0.0001, 'Boundary must be converted proportionally (50->25 when halved).');
    }

    /**
     * When changing grade and feedback boundaries together, apply grade
     * first. Explicit boundaries already refer to the new maximum; scaling
     * them afterward would incorrectly halve a freshly supplied 25 to 12.5.
     */
    public function test_grade_change_and_new_feedback_boundaries_in_one_call_are_not_double_scaled(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'grade' => 100,
        ]);

        // Halve 100 to 50 and supply a new boundary of 25, already valid for 50, in the same call.
        $this->patch($quiz->cmid, [
            'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
            'feedbackboundaries' => [25],
        ], '', 50.0);

        $raw = $this->raw_quiz($quiz->cmid);
        $this->assertEqualsWithDelta(50.0, (float) $raw->grade, 0.0001);

        $records = array_values($DB->get_records('quiz_feedback', ['quizid' => $raw->id], 'mingrade DESC'));
        $feedback = current(array_filter($records, fn ($record) => $record->feedbacktext === 'Bestanden'));
        $this->assertEqualsWithDelta(25.0, (float) $feedback->mingrade, 0.0001, 'Explicitly given boundary must not be scaled additionally.');
    }

    /**
     * Reject unknown fields without writes.
     */
    public function test_unknown_field_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'name' => 'Unveraendert',
        ]);

        try {
            $this->patch($quiz->cmid, ['gibtsnicht' => 'x']);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gibtsnicht', $e->getMessage());
        }

        $this->assertSame('Unveraendert', $this->raw_quiz($quiz->cmid)->name);
    }

    /**
     * Reject invalid values without writes.
     */
    public function test_invalid_value_fails_and_writes_nothing(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        try {
            $this->patch($quiz->cmid, ['navmethod' => 'diagonal']);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('navmethod', $e->getMessage());
        }

        $this->assertSame('free', $this->raw_quiz($quiz->cmid)->navmethod);
    }

    /**
     * Reject invalid feedback-boundary combinations without writes.
     */
    public function test_combination_rule_violation_fails_and_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
            'grade' => 100,
        ]);

        try {
            $this->patch($quiz->cmid, [
                'feedbacktext' => ['Bestanden', 'Nicht bestanden'],
                'feedbackboundaries' => [150], // Ausserhalb von (0, grade).
            ]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('feedbackboundaries', $e->getMessage());
        }

        $this->assertCount(0, $DB->get_records('quiz_feedback', ['quizid' => $this->raw_quiz($quiz->cmid)->id]));
    }

    /**
     * Require moodle/course:manageactivities to write in the course
     * context; read-only Coursepilot access remains available.
     */
    public function test_write_without_native_capability_fails(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $editingteacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($editingteacher->id, $course->id, 'editingteacher');
        $this->setUser($editingteacher);
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        update_quiz_settings::execute($quiz->cmid, json_encode(['name' => 'Uebernommen']));
    }

    /**
     * Patching creates a history entry.
     */
    public function test_patch_creates_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);

        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $quiz->cmid]);
        $this->patch($quiz->cmid, ['name' => 'Verlauf-Test']);
        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $quiz->cmid]));
    }

    /**
     * This endpoint does not alter quiz_slots (criterion 9). Verify through
     * source inspection, as for generic endpoints.
     */
    public function test_source_never_touches_quiz_slots(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/external/update_quiz_settings.php');
        $this->assertStringNotContainsString('quiz_slots', $source);
        $this->assertStringNotContainsString('mod_quiz\\structure', $source);
    }

    /**
     * No direct quiz-table writes (ADR 0016).
     */
    public function test_source_never_writes_the_quiz_table_directly(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/external/update_quiz_settings.php');
        $this->assertStringNotContainsString("update_record('quiz'", $source);
        $this->assertStringContainsString('update_moduleinfo(', $source);
    }

    /**
     * Drift blocks update_quiz_settings and advises contacting administration (#399).
     */
    public function test_drift_blocks_update_quiz_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance([
            'course' => $course->id,
        ]);

        \local_coursepilot\write_gate::all_statuses();
        set_config('driftviolations_quiz', json_encode(['Spalte "grade" fehlt.']), 'local_coursepilot');

        try {
            $this->patch($quiz->cmid, ['intro' => 'Neue Beschreibung']);
            $this->fail('execute() should have thrown because of drift.');
        } catch (\moodle_exception $e) {
            // write_gate_test.php checks exact wording against the language pack.
            $this->assertSame('modnamedriftlocked', $e->errorcode);
        }
    }

    /**
     * Attempt limits in quiz patches require confirmation (#583).
     */
    public function test_attempt_limit_needs_confirmation(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        try {
            update_quiz_settings::execute($quiz->cmid, json_encode(['attempts' => 1]));
            $this->fail('attempts=1 should have required confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
        }

        update_quiz_settings::execute($quiz->cmid, json_encode(['attempts' => 1]), '', -1.0, ['attempts']);
        $this->assertSame(1, (int) $DB->get_field('quiz', 'attempts', ['id' => $quiz->id]));
    }

    /**
     * Switching to final-test itself confirms the preset’s attempt limit (#583).
     */
    public function test_switching_to_final_test_mode_needs_no_extra_confirmation(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);

        $this->patch($quiz->cmid, [], 'final-test');

        $this->assertSame(2, (int) $DB->get_field('quiz', 'attempts', ['id' => $quiz->id]));
    }

}
