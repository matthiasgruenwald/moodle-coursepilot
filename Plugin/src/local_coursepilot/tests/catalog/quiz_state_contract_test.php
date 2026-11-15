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

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Exact value types of the quiz state readers and the grade origin boundaries (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(quiz::class)]
final class quiz_state_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Creates a quiz with one question of the given type.
     *
     * @param string $qtype Question type.
     * @param mixed[] $quizrecord Extra quiz settings.
     * @return \stdClass Quiz module (with cmid).
     */
    private function quiz_with_question(string $qtype, array $quizrecord = []): \stdClass {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id] + $quizrecord);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $generator->create_question($qtype, null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        return $quiz;
    }

    public function test_the_state_reports_decimal_grades_without_trailing_zeros(): void {
        $quiz = $this->quiz_with_question('truefalse', ['grade' => 10, 'attempts' => 3, 'timelimit' => 600, 'grademethod' => 4]);

        $state = quiz::state((int) $quiz->id, (int) $quiz->cmid, true);

        $settings = array_column($state['settings'], 'value', 'name');
        $this->assertSame('10', $settings['grade']);
        $this->assertSame('3', $settings['attempts']);
        $this->assertSame('4', $settings['grademethod']);
        $this->assertSame('600', $settings['timelimit']);
    }

    public function test_the_effective_state_has_the_catalog_types_and_form_names(): void {
        global $DB;
        $quiz = $this->quiz_with_question('truefalse', ['quizpassword' => 'geheim']);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, 0, false, MUST_EXIST);
        $DB->set_field('course_modules', 'idnumber', null, ['id' => $cm->id]);
        $cm = get_coursemodule_from_id('quiz', $quiz->cmid, 0, false, MUST_EXIST);
        $instance = $DB->get_record('quiz', ['id' => $quiz->id], '*', MUST_EXIST);

        $state = quiz::effective_state($cm, $instance);

        $this->assertSame('geheim', $state['quizpassword']);
        $this->assertSame(1, $state['visible']);
        $this->assertSame(1, $state['visibleoncoursepage']);
        $this->assertSame(0, $state['groupmode']);
        $this->assertSame(0, $state['groupingid']);
        $this->assertSame('', $state['idnumber']);
    }

    public function test_grade_origin_looks_only_at_the_named_quiz(): void {
        global $DB;
        $quiz = $this->quiz_with_question('essay');
        $this->assertSame(learner_locks::GRADE_TEACHER, quiz::grade_origin((int) $quiz->id));

        // Slots of a quiz that does not exist must never count for the generic, unnamed case.
        foreach ([0, 1] as $foreignid) {
            $DB->set_field('quiz_slots', 'quizid', $foreignid, ['quizid' => $quiz->id]);
            $this->assertSame(learner_locks::GRADE_AUTOMATIC, quiz::grade_origin(), 'orphan slots of quiz ' . $foreignid);
            $this->assertSame(learner_locks::GRADE_AUTOMATIC, quiz::grade_origin(0), 'orphan slots of quiz ' . $foreignid);
            $DB->set_field('quiz_slots', 'quizid', $quiz->id, ['quizid' => $foreignid]);
        }
    }

    public function test_a_question_type_that_is_no_longer_installed_does_not_break_the_grade_origin(): void {
        global $DB;
        $quiz = $this->quiz_with_question('truefalse');
        $DB->set_field('question', 'qtype', 'removedqtype', ['qtype' => 'truefalse']);

        $this->assertSame(
            learner_locks::GRADE_AUTOMATIC,
            $this->succeeded(fn() => quiz::grade_origin((int) $quiz->id))
        );
    }
}
