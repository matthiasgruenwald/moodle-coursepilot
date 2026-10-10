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
 * Dedicated quiz creation tool (Spec 0015 §5, issue #398).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(create_quiz::class)]
final class create_quiz_test extends \advanced_testcase {
    /**
     * Provides course with editing teacher.
     *
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
     * Minimum required fields without form defaults (see create_quiz class
     * documentation) needed to create a quiz.
     *
     * @return array<string, mixed>
     */
    private function minimal_fields(): array {
        return [
            'name' => 'Mein Test',
            'intro' => 'Beschreibung',
            'subnet' => '',
            'browsersecurity' => '-',
        ];
    }

    /**
     * Like {@see self::minimal_fields()}, with preferredbehaviour for tests
     * without a mode preset, which otherwise supplies that field.
     *
     * @return array<string, mixed>
     */
    private function minimal_fields_without_mode(): array {
        return array_merge($this->minimal_fields(), ['preferredbehaviour' => 'deferredfeedback']);
    }

    /**
     * Creates the create quiz test.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param mixed[] $felder
     * @param string $mode
     * @param float $grade
     * @param string[] $confirmlearnerlocks Explicitly confirmed learner restrictions (#583).
     * @return mixed[]
     */
    private function create(
        int $courseid,
        int $sectionnum,
        array $felder,
        string $mode = '',
        float $grade = -1.0,
        array $confirmlearnerlocks = []
    ): array {
        return external_api::clean_returnvalue(
            create_quiz::execute_returns(),
            create_quiz::execute($courseid, $sectionnum, json_encode($felder), $mode, $grade, $confirmlearnerlocks)
        );
    }

    /**
     * Returns raw quiz table row.
     *
     * @param int $cmid
     * @return \stdClass Raw quiz table row.
     */
    private function raw_quiz(int $cmid): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        return $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /**
     * All three modes create quizzes with their documented presets
     * (acceptance criterion 1).
     */
    public function test_quiz_can_be_created_with_each_mode(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $expectations = [
            'mini-check' => ['preferredbehaviour' => 'immediatefeedback', 'grademethod' => 1, 'attempts' => 0],
            'progress-check' => ['preferredbehaviour' => 'deferredcbm', 'grademethod' => 1, 'delay1' => 300],
            'final-test' => ['preferredbehaviour' => 'deferredfeedback', 'grademethod' => 2, 'attempts' => 2],
        ];

        foreach ($expectations as $mode => $expected) {
            $result = $this->create($course->id, 0, $this->minimal_fields(), $mode);
            $quiz = $this->raw_quiz($result['cmid']);
            foreach ($expected as $field => $value) {
                $this->assertEquals($value, $quiz->{$field}, "mode={$mode} field={$field}");
            }
            $this->assertStringContainsString('created', $result['message']);
        }
    }

    /**
     * Mode presets preserve explicitly supplied fields (criterion 3).
     */
    public function test_bundle_does_not_override_explicitly_named_fields(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $felder = $this->minimal_fields();
        $felder['attempts'] = 7; // The mini-check bundle otherwise sets it to 0.

        $result = $this->create($course->id, 0, $felder, 'mini-check', -1.0, ['attempts']);
        $quiz = $this->raw_quiz($result['cmid']);
        $this->assertEquals(7, $quiz->attempts);
    }

    /**
     * grade uses its dedicated parameter; reject attempts to set it through
     * fields_json (criterion 4).
     */
    public function test_grade_via_felder_json_is_blocked(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $felder = $this->minimal_fields();
        $felder['grade'] = 50;

        try {
            $this->create($course->id, 0, $felder);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('grade', $e->getMessage());
        }
    }

    /**
     * Set grade through its dedicated parameter.
     */
    public function test_grade_parameter_sets_the_maximum_grade(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, $this->minimal_fields_without_mode(), '', 42.0);
        $quiz = $this->raw_quiz($result['cmid']);
        $this->assertEqualsWithDelta(42.0, (float) $quiz->grade, 0.0001);
    }

    /**
     * Reject missing mandatory fields without form defaults (name here),
     * identify the field and create nothing.
     */
    public function test_required_field_without_default_fails(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $felder = $this->minimal_fields();
        unset($felder['name']);

        try {
            $this->create($course->id, 0, $felder);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('name', $e->getMessage());
        }
    }

    /**
     * Reject unknown field names without creating a quiz.
     */
    public function test_unknown_field_fails_and_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $before = $DB->count_records('quiz', ['course' => $course->id]);

        $felder = $this->minimal_fields();
        $felder['gibtsnicht'] = 'x';

        try {
            $this->create($course->id, 0, $felder);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('gibtsnicht', $e->getMessage());
        }

        $this->assertEquals($before, $DB->count_records('quiz', ['course' => $course->id]));
    }

    /**
     * Reject invalid values without creating a quiz.
     */
    public function test_invalid_value_fails_and_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $before = $DB->count_records('quiz', ['course' => $course->id]);
        $felder = $this->minimal_fields();
        $felder['navmethod'] = 'diagonal';

        try {
            $this->create($course->id, 0, $felder);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('navmethod', $e->getMessage());
        }

        $this->assertEquals($before, $DB->count_records('quiz', ['course' => $course->id]));
    }

    /**
     * Supply overall feedback during creation.
     */
    public function test_overall_feedback_can_be_set_on_create(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $felder = $this->minimal_fields_without_mode();
        $felder['feedbacktext'] = ['Bestanden', 'Nicht bestanden'];
        $felder['feedbackboundaries'] = [50];

        $result = $this->create($course->id, 0, $felder, '', 100.0);
        $quiz = $this->raw_quiz($result['cmid']);
        $records = array_values($DB->get_records('quiz_feedback', ['quizid' => $quiz->id], 'mingrade DESC'));
        $this->assertCount(2, $records);
        $this->assertSame('Bestanden', $records[0]->feedbacktext);
        $this->assertSame('Nicht bestanden', $records[1]->feedbacktext);
        $this->assertEqualsWithDelta(50.0, (float) $records[0]->mingrade, 0.0001);
    }

    /**
     * Require moodle/course:manageactivities in the course context.
     */
    public function test_create_without_native_capability_fails(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        create_quiz::execute($course->id, 0, json_encode($this->minimal_fields_without_mode()));
    }

    /**
     * Creation records a history entry through course_module_created (#385).
     */
    public function test_create_creates_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, $this->minimal_fields_without_mode());
        $this->assertGreaterThan(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $result['cmid']]));
    }

    /**
     * Do not write directly to the quiz table (ADR 0016). Only add_moduleinfo()
     * and Moodle’s grade calculator may write.
     */
    public function test_source_never_writes_the_quiz_table_directly(): void {
        $source = file_get_contents(__DIR__ . '/../../classes/external/create_quiz.php');
        $this->assertStringNotContainsString('$DB->update_record', $source);
        $this->assertStringNotContainsString("insert_record('quiz'", $source);
        $this->assertStringContainsString('add_moduleinfo(', $source);
    }

    /**
     * Drift blocks create_quiz and asks the teacher to notify administration
     * under the same write-gate policy (#399).
     */
    public function test_drift_blocks_create_quiz(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        \local_coursepilot\write_gate::all_statuses();
        set_config('driftviolations_quiz', json_encode(['Spalte "grade" fehlt.']), 'local_coursepilot');

        try {
            $this->create($course->id, 0, $this->minimal_fields(), 'mini-check');
            $this->fail('execute() should have thrown because of drift.');
        } catch (\moodle_exception $e) {
            // Note: write_gate_test.php checks exact wording against the language pack.
            $this->assertSame('modnamedriftlocked', $e->errorcode);
        }
    }

    /**
     * final-test allows two attempts; selecting it confirms this learner
     * restriction. Attempt limits without a mode still need confirmation (#583).
     */
    public function test_chosen_mode_confirms_its_own_locks(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->create($course->id, 0, $this->minimal_fields(), 'final-test');
        $this->assertEquals(2, $this->raw_quiz($result['cmid'])->attempts);

        try {
            $this->create($course->id, 0, $this->minimal_fields_without_mode() + ['attempts' => 2]);
            $this->fail('Attempt limit without a mode should have required confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
            $this->assertStringContainsString('attempts', $e->getMessage());
        }
    }
}
