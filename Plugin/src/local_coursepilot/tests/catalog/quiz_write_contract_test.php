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

use core_external\external_api;
use local_coursepilot\external\update_quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Quiz defaults, complete change reports and exact grading boundaries at the native write seam.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(quiz_write::class)]
#[CoversClass(\local_coursepilot\external\create_quiz::class)]
#[CoversClass(update_quiz_settings::class)]
final class quiz_write_contract_test extends \advanced_testcase {
    /**
     * Creation rejects a passing grade above the maximum before persisting an activity.
     */
    public function test_creation_rejects_a_passing_grade_above_the_maximum(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $fields = [
            'name' => 'Invalid passing grade', 'intro' => '', 'subnet' => '', 'browsersecurity' => '-',
            'preferredbehaviour' => 'deferredfeedback', 'gradepass' => 2,
        ];
        try {
            \local_coursepilot\external\create_quiz::execute($course->id, 0, json_encode($fields), '', 1);
            $this->fail('A passing grade above the maximum must be refused before creation.');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidquizgradepass', $error->errorcode);
        }
        $this->assertSame(0, $DB->count_records('quiz', ['course' => $course->id]));
    }

    /**
     * A zero-point quiz cannot acquire a positive passing grade during the same update.
     */
    public function test_zero_maximum_rejects_a_positive_passing_grade_without_changing_the_quiz(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        try {
            update_quiz_settings::execute($quiz->cmid, '{"gradepass":1}', '', 0);
            $this->fail('A passing grade above the requested maximum must be refused.');
        } catch (\moodle_exception $error) {
            $this->assertSame('invalidquizgradepass', $error->errorcode);
        }
        $this->assertEquals(100, $DB->get_field('quiz', 'grade', ['id' => $quiz->id], MUST_EXIST));
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id]);
        $this->assertEquals(0, $item->gradepass);
    }

    /**
     * An unrelated text edit preserves a teacher's non-default review choices and password.
     */
    public function test_text_edit_preserves_review_choices_and_password(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'quizpassword' => 'Private / marker',
            'attemptimmediately' => 0, 'attemptopen' => 0, 'attemptclosed' => 1,
            'correctnessopen' => 0, 'rightanswerimmediately' => 1, 'generalfeedbackclosed' => 0,
        ]);
        $before = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $this->assertSame('Private / marker', $before->password);
        $result = update_quiz_settings::execute($quiz->cmid, '{"intro":"Changed description"}');
        $result = external_api::clean_returnvalue(update_quiz_settings::execute_returns(), $result);
        $this->assertSame(['intro'], array_column($result['changes'], 'field'));
        $after = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);
        $preserved = ['password', 'reviewattempt', 'reviewcorrectness', 'reviewmaxmarks', 'reviewmarks',
            'reviewspecificfeedback', 'reviewgeneralfeedback', 'reviewrightanswer', 'reviewoverallfeedback'];
        foreach ($preserved as $field) {
            $this->assertSame($before->{$field}, $after->{$field}, $field);
        }
    }

    /**
     * A comma-decimal site keeps the exact passing grade during an unrelated edit.
     */
    public function test_localised_text_edit_preserves_the_passing_grade(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10]);
        update_quiz_settings::execute($quiz->cmid, '{"gradepass":1.25}');
        $previousroot = $CFG->langlocalroot;
        $localroot = make_request_directory();
        mkdir($localroot . '/en_local');
        file_put_contents($localroot . '/en_local/langconfig.php', '<?php $string["decsep"] = ",";');
        $previouslanguage = force_current_language('en');
        try {
            $CFG->langlocalroot = $localroot;
            get_string_manager(true)->reset_caches(true);
            $this->assertSame('1,25', format_float(1.25, 2));
            $result = update_quiz_settings::execute($quiz->cmid, '{"name":"Localised title"}');
            $result = external_api::clean_returnvalue(update_quiz_settings::execute_returns(), $result);
            $this->assertSame(['name'], array_column($result['changes'], 'field'));
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id]);
            $this->assertEquals(1.25, $item->gradepass);
            try {
                update_quiz_settings::execute($quiz->cmid, '{"gradepass":"2,75"}');
                $this->fail('The published grading contract requires a JSON number in every locale.');
            } catch (\moodle_exception $error) {
                $this->assertSame('invalidquizgradepass', $error->errorcode);
            }
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz', 'iteminstance' => $quiz->id]);
            $this->assertEquals(1.25, $item->gradepass);
        } finally {
            $CFG->langlocalroot = $previousroot;
            get_string_manager(true)->reset_caches(true);
            force_current_language($previouslanguage);
        }
    }

    /**
     * A failed nested native write leaves rollback ownership with its caller.
     */
    public function test_nested_native_failure_preserves_the_callers_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/quiz:addinstance', CAP_PROHIBIT, $roleid, \context_course::instance($course->id)->id, true);
        $transaction = $DB->start_delegated_transaction();
        try {
            $marker = $this->getDataGenerator()->create_course(['shortname' => 'Caller transaction marker']);
            try {
                quiz_write::create($course->id, 0, [
                    'name' => 'Refused quiz', 'intro' => '', 'subnet' => '', 'browsersecurity' => '-',
                    'preferredbehaviour' => 'deferredfeedback',
                ]);
                $this->fail('The native module restriction must reject creation.');
            } catch (\moodle_exception $error) {
                $this->assertSame('moduledisable', $error->errorcode);
            }
            $this->assertTrue($DB->is_transaction_started());
            $this->assertTrue($DB->record_exists('course', ['id' => $marker->id]));
            $this->assertFalse($DB->record_exists('quiz', ['course' => $course->id]));
        } finally {
            $DB->force_transaction_rollback();
        }
        $this->assertFalse($DB->record_exists('course', ['id' => $marker->id]));
    }

    /**
     * Native clients can omit the grade: creation uses the configured maximum and updates preserve it.
     */
    public function test_native_default_grade_uses_configuration_and_preserves_existing_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        set_config('maximumgrade', 7, 'quiz');
        $created = quiz_write::create($course->id, 0, [
            'name' => 'Configured maximum', 'intro' => '', 'subnet' => '', 'browsersecurity' => '-',
            'preferredbehaviour' => 'deferredfeedback',
        ]);
        $cm = get_coursemodule_from_id('quiz', $created['cmid'], 0, false, MUST_EXIST);
        $this->assertEquals(7, $DB->get_field('quiz', 'grade', ['id' => $cm->instance], MUST_EXIST));
        $updated = quiz_write::update($cm, ['name' => 'Preserved maximum']);
        $this->assertSame($created['cmid'], $updated['cmid']);
        $this->assertEquals(7, $DB->get_field('quiz', 'grade', ['id' => $cm->instance], MUST_EXIST));
        $this->assertSame('Preserved maximum', $DB->get_field('quiz', 'name', ['id' => $cm->instance], MUST_EXIST));
    }

    /**
     * Zero is a real maximum; reapplying it or a value within tolerance reports no grading change.
     */
    public function test_zero_grade_and_tolerance_keep_accurate_changes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 100]);
        $zero = update_quiz_settings::execute($quiz->cmid, '{}', '', 0);
        $zero = external_api::clean_returnvalue(update_quiz_settings::execute_returns(), $zero);
        $this->assertEquals(0, $DB->get_field('quiz', 'grade', ['id' => $quiz->id], MUST_EXIST));
        $this->assertSame(['grade'], array_column($zero['changes'], 'field'));
        $this->assertSame([
            'Existing attempt grades and overall feedback boundaries were rescaled proportionally to the new grade.',
        ], $zero['side_effects']);
        foreach ([0.0, 0.00001] as $grade) {
            $again = update_quiz_settings::execute($quiz->cmid, '{}', '', $grade);
            $again = external_api::clean_returnvalue(update_quiz_settings::execute_returns(), $again);
            $this->assertSame([], $again['changes']);
            $this->assertSame([], $again['side_effects']);
            $this->assertSame('No change: the patch already matched the current state.', $again['message']);
            $this->assertEquals(0, $DB->get_field('quiz', 'grade', ['id' => $quiz->id], MUST_EXIST));
        }
    }

    /**
     * A client receives every changed field and both effects, scoped to the selected quiz.
     */
    public function test_combined_feedback_grade_and_calendar_change_reports_every_effect(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 3]);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'grade' => 100, 'name' => 'Before / Übung',
        ]);
        update_quiz_settings::execute($quiz->cmid, json_encode([
            'feedbacktext' => ['Old / Übung', 'Old / Retry'], 'feedbackboundaries' => [50],
        ]));
        $patch = [
            'name' => 'After / Übung', 'timeopen' => 2000000000,
            'feedbacktext' => ['Pass / Üben', 'Retry / Üben'], 'feedbackboundaries' => [25],
        ];
        $result = update_quiz_settings::execute($quiz->cmid, json_encode($patch), '', 50);
        $result = external_api::clean_returnvalue(update_quiz_settings::execute_returns(), $result);
        $effects = [
            'The calendar event for the quiz was updated.',
            'Existing attempt grades and overall feedback boundaries were rescaled proportionally to the new grade.',
        ];
        $this->assertSame($effects, $result['side_effects']);
        $this->assertSame(['name', 'timeopen', 'grade', 'feedbacktext'], array_column($result['changes'], 'field'));
        $changes = array_column($result['changes'], null, 'field');
        $this->assertSame([
            'field' => 'feedbacktext',
            'before_json' => '["Old / Übung","Old / Retry"]',
            'after_json' => '["Pass / Üben","Retry / Üben"]',
        ], $changes['feedbacktext']);
        $this->assertStringStartsWith('Changed: "name" from "Before / Übung" to "After / Übung"', $result['message']);
        $this->assertStringEndsWith('. ' . implode(' ', $effects), $result['message']);
        $this->assertEquals(50, $DB->get_field('quiz', 'grade', ['id' => $quiz->id], MUST_EXIST));
        $this->assertEquals(3, $DB->get_field('quiz', 'grade', ['id' => $other->id], MUST_EXIST));
        $again = update_quiz_settings::execute($quiz->cmid, json_encode($patch), '', 50);
        $again = external_api::clean_returnvalue(update_quiz_settings::execute_returns(), $again);
        $this->assertSame([], $again['changes']);
        $this->assertSame([$effects[0]], $again['side_effects']);
        $this->assertSame('No change: the patch already matched the current state.', $again['message']);
    }
}
