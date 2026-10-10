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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Lineage report after cloning (Spec 0017 §7.5, ticket #422): reports for each
 * question of a quiz whether an own copy was made or the reference
 * still points to the source course - read only, nothing is written.
 *
 * A mixed state is not produced here via a real
 * cross-course clone (clone_activity, #421) but
 * directly: a question category in the target course (= "own copy" after a
 * clone) and a question category in a second, foreign course (= "shared
 * reference", exactly the state Moodle's backup/restore leaves behind for a
 * category outside the backup scope). The endpoint under test compares only
 * the course of the question category with the course of the quiz - this
 * state covers exactly that, regardless of whether it was produced by a
 * real clone or directly.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(report_clone_lineage::class)]
#[CoversClass(\local_coursepilot\question_suspect_gate::class)]
final class report_clone_lineage_test extends \advanced_testcase {
    /**
     * Provides course with quiz and teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass} Course, quiz.
     */
    private function course_with_quiz_and_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $quiz];
    }

    /**
     * Returns question_categories row.
     *
     * @param \stdClass $course Course whose module context carries the category.
     * @return \stdClass question_categories row
     */
    private function category_in_own_qbank(\stdClass $course): \stdClass {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        return $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question_category(['contextid' => $qbankcontext->id]);
    }

    /**
     * Adds question to quiz.
     *
     * @param \stdClass $quiz The quiz.
     * @param \stdClass $category The category.
     * @param string $name The name.
     */
    private function add_question_to_quiz(\stdClass $quiz, \stdClass $category, string $name): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => $name]);
        quiz_add_quiz_question((int) $question->id, $quiz);
    }

    /**
     * Acceptance criterion: a mixed state reports own copy and shared
     * reference correctly, per question.
     */
    public function test_reports_own_copy_and_shared_reference_for_mixed_bank(): void {
        $this->resetAfterTest();
        [$course, $quiz] = $this->course_with_quiz_and_teacher();
        $foreigncourse = $this->getDataGenerator()->create_course();

        $owncategory = $this->category_in_own_qbank($course);
        $sharedcategory = $this->category_in_own_qbank($foreigncourse);

        $this->add_question_to_quiz($quiz, $owncategory, 'Eigene Kopie');
        $this->add_question_to_quiz($quiz, $sharedcategory, 'Geteilte Referenz');

        $result = report_clone_lineage::execute((int) $quiz->cmid);
        $result = external_api::clean_returnvalue(report_clone_lineage::execute_returns(), $result);

        $this->assertCount(2, $result['questions']);
        $bystatus = [];
        foreach ($result['questions'] as $entry) {
            $bystatus[$entry['name']] = $entry;
        }

        $this->assertSame('own_copy', $bystatus['Eigene Kopie']['status']);
        $this->assertSame(0, $bystatus['Eigene Kopie']['source_course_id']);

        $this->assertSame('shared_reference', $bystatus['Geteilte Referenz']['status']);
        $this->assertSame((int) $foreigncourse->id, $bystatus['Geteilte Referenz']['source_course_id']);

        $this->assertStringContainsString('created as their own copy', $result['message']);
        $this->assertStringContainsString('shared reference', $result['message']);
    }

    /**
     * The suspect-case envelope (T3) is NOT carried along: this
     * tool writes nothing and therefore cannot trigger a gate -
     * five constantly empty fields in every response would be mere noise
     * (#424 follow-up 4).
     */
    public function test_does_not_carry_the_suspect_gate_envelope(): void {
        $this->resetAfterTest();
        [$course, $quiz] = $this->course_with_quiz_and_teacher();
        $category = $this->category_in_own_qbank($course);
        $this->add_question_to_quiz($quiz, $category, 'Frage');

        $result = report_clone_lineage::execute((int) $quiz->cmid);
        $result = external_api::clean_returnvalue(report_clone_lineage::execute_returns(), $result);

        $this->assertSame(['cmid', 'questions', 'message'], array_keys($result));
        foreach (array_keys(\local_coursepilot\question_suspect_gate::empty_result()) as $field) {
            $this->assertArrayNotHasKey($field, $result);
        }
    }

    /**
     * Acceptance criterion: the check is a pure read - after the call the
     * question inventory is unchanged (no new idnumber, no
     * new/changed question_references row).
     */
    public function test_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz] = $this->course_with_quiz_and_teacher();
        $foreigncourse = $this->getDataGenerator()->create_course();
        $owncategory = $this->category_in_own_qbank($course);
        $sharedcategory = $this->category_in_own_qbank($foreigncourse);
        $this->add_question_to_quiz($quiz, $owncategory, 'Eigene Kopie');
        $this->add_question_to_quiz($quiz, $sharedcategory, 'Geteilte Referenz');

        $beforeentries = $DB->get_records('question_bank_entries');
        $beforereferences = $DB->get_records('question_references');
        $beforequestions = $DB->get_records('question');

        report_clone_lineage::execute((int) $quiz->cmid);

        $this->assertEquals($beforeentries, $DB->get_records('question_bank_entries'));
        $this->assertEquals($beforereferences, $DB->get_records('question_references'));
        $this->assertEquals($beforequestions, $DB->get_records('question'));
    }

    /**
     * An empty quiz reports that instead of silently returning an
     * empty list.
     */
    public function test_empty_quiz_reports_no_questions(): void {
        $this->resetAfterTest();
        [, $quiz] = $this->course_with_quiz_and_teacher();

        $result = report_clone_lineage::execute((int) $quiz->cmid);
        $result = external_api::clean_returnvalue(report_clone_lineage::execute_returns(), $result);

        $this->assertSame([], $result['questions']);
        $this->assertStringContainsString('no questions', $result['message']);
    }

    /**
     * A cmid that is not a quiz is rejected clearly instead of failing with
     * an internal error.
     */
    public function test_rejects_non_quiz_cmid(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_quiz_and_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->expectException(\invalid_parameter_exception::class);
        report_clone_lineage::execute((int) $page->cmid);
    }
}
