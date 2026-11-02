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

namespace local_coursepilot\quiz;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Only differing parts of a quiz arrangement are written back, and every differing part is (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(arrangement::class)]
final class arrangement_restore_contract_test extends \advanced_testcase {
    /**
     * Creates a quiz with two questions on one page each.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Quiz, question 1, question 2.
     */
    private function quiz_with_two_questions(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $generator->create_instance(['course' => $course->id]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question1 = $generator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage 1']);
        $question2 = $generator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage 2']);
        quiz_add_quiz_question($question1->id, $quiz);
        quiz_add_quiz_question($question2->id, $quiz);
        return [$quiz, $question1, $question2];
    }

    /**
     * Fresh structure of a quiz.
     *
     * @param \stdClass $quiz Quiz.
     * @return \mod_quiz\structure
     */
    private function structure(\stdClass $quiz): \mod_quiz\structure {
        return \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($quiz->id));
    }

    /**
     * Slot rows in order.
     *
     * @param \stdClass $quiz Quiz.
     * @return \stdClass[]
     */
    private function slots(\stdClass $quiz): array {
        global $DB;
        return array_values($DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot'));
    }

    public function test_restoring_an_unchanged_arrangement_writes_no_slot_or_section_setting(): void {
        [$quiz] = $this->quiz_with_two_questions();
        $target = arrangement::capture((int) $quiz->id);
        $sink = $this->redirectEvents();

        arrangement::restore((int) $quiz->id, $target);

        $names = array_map(static fn($e) => get_class($e), $sink->get_events());
        $this->assertSame([], array_values(array_intersect($names, [
            \mod_quiz\event\slot_requireprevious_updated::class,
            \mod_quiz\event\slot_mark_updated::class,
            \mod_quiz\event\slot_displaynumber_updated::class,
            \mod_quiz\event\section_shuffle_updated::class,
            \mod_quiz\event\section_title_updated::class,
        ])));
    }

    public function test_a_maxmark_difference_of_exactly_the_tolerance_is_not_restored(): void {
        global $DB;
        [$quiz] = $this->quiz_with_two_questions();
        $slotid = (int) $this->slots($quiz)[0]->id;
        $DB->set_field('quiz_slots', 'maxmark', 0.0000002, ['id' => $slotid]);
        $target = arrangement::capture((int) $quiz->id);
        $target['slots'][0]['maxmark'] = 0.0000001;
        $sink = $this->redirectEvents();

        arrangement::restore((int) $quiz->id, $target);

        $names = array_map(static fn($e) => get_class($e), $sink->get_events());
        $this->assertNotContains(\mod_quiz\event\slot_mark_updated::class, $names);
        $this->assertEqualsWithDelta(0.0000002, (float) $DB->get_field('quiz_slots', 'maxmark', ['id' => $slotid]), 1e-12);
    }

    public function test_a_maxmark_difference_above_the_tolerance_is_restored(): void {
        global $DB;
        [$quiz] = $this->quiz_with_two_questions();
        $slotid = (int) $this->slots($quiz)[0]->id;
        $target = arrangement::capture((int) $quiz->id);
        $DB->set_field('quiz_slots', 'maxmark', 3.5, ['id' => $slotid]);

        arrangement::restore((int) $quiz->id, $target);

        $this->assertEqualsWithDelta(
            (float) $target['slots'][0]['maxmark'],
            (float) $DB->get_field('quiz_slots', 'maxmark', ['id' => $slotid]),
            1e-9
        );
    }

    public function test_a_stored_require_previous_value_above_one_is_restored_as_a_flag(): void {
        global $DB;
        [$quiz] = $this->quiz_with_two_questions();
        $slotid = (int) $this->slots($quiz)[1]->id;
        $DB->set_field('quiz_slots', 'requireprevious', 2, ['id' => $slotid]);
        $target = arrangement::capture((int) $quiz->id);
        $DB->set_field('quiz_slots', 'requireprevious', 0, ['id' => $slotid]);

        arrangement::restore((int) $quiz->id, $target);

        $this->assertSame('1', (string) $DB->get_field('quiz_slots', 'requireprevious', ['id' => $slotid]));
    }

    public function test_an_emptied_section_heading_is_restored(): void {
        global $DB;
        [$quiz] = $this->quiz_with_two_questions();
        $section = array_values($this->structure($quiz)->get_sections())[0];
        $this->structure($quiz)->set_section_heading($section->id, 'Teil A');
        $target = arrangement::capture((int) $quiz->id);
        $this->structure($quiz)->set_section_heading($section->id, '');

        arrangement::restore((int) $quiz->id, $target);

        $this->assertSame('Teil A', $DB->get_field('quiz_sections', 'heading', ['id' => $section->id]));
    }

    public function test_two_slots_that_shared_a_page_share_it_again(): void {
        [$quiz] = $this->quiz_with_two_questions();
        $slots = $this->slots($quiz);
        $this->structure($quiz)->update_page_break($slots[1]->id, \mod_quiz\repaginate::LINK);
        $target = arrangement::capture((int) $quiz->id);
        $this->assertSame($target['slots'][0]['page'], $target['slots'][1]['page']);
        $this->structure($quiz)->update_page_break($slots[1]->id, \mod_quiz\repaginate::UNLINK);

        arrangement::restore((int) $quiz->id, $target);

        $after = arrangement::capture((int) $quiz->id);
        $this->assertSame($after['slots'][0]['page'], $after['slots'][1]['page']);
    }

    public function test_a_pinned_question_version_is_restored(): void {
        global $DB;
        [$quiz, $question1] = $this->quiz_with_two_questions();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $generator->update_question($question1, null, ['name' => 'Frage 1 neu']);
        $slots = $this->slots($quiz);
        $this->structure($quiz)->update_slot_version($slots[0]->id, 1);
        $target = arrangement::capture((int) $quiz->id);
        $this->assertSame(1, $target['slots'][0]['version']);
        $this->structure($quiz)->update_slot_version($slots[0]->id, 2);

        arrangement::restore((int) $quiz->id, $target);

        $this->assertSame(
            '1',
            $DB->get_field('question_references', 'version', [
                'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slots[0]->id,
            ])
        );
    }

    public function test_restored_feedback_replaces_the_quiz_rows_and_leaves_other_quizzes_alone(): void {
        global $DB;
        [$quiz] = $this->quiz_with_two_questions();
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $other = $generator->create_instance([
            'course' => $quiz->course,
        ]);
        $row = static fn(int $quizid, string $text, float $max): \stdClass => (object) [
            'quizid' => $quizid, 'feedbacktext' => $text, 'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 0, 'maxgrade' => $max,
        ];
        $DB->insert_record('quiz_feedback', $row((int) $other->id, 'Fremd', 10));
        $DB->insert_record('quiz_feedback', $row((int) $quiz->id, 'Ziel', 50));
        $target = arrangement::capture((int) $quiz->id);
        $DB->delete_records('quiz_feedback', ['quizid' => $quiz->id]);
        $DB->insert_record('quiz_feedback', $row((int) $quiz->id, 'Alt', 20));
        $DB->insert_record('quiz_feedback', $row((int) $quiz->id, 'Alt 2', 30));

        arrangement::restore((int) $quiz->id, $target);

        $this->assertSame(['Ziel'], array_values($DB->get_fieldset_select(
            'quiz_feedback',
            'feedbacktext',
            'quizid = ?',
            [$quiz->id]
        )));
        $this->assertSame(['Fremd'], array_values($DB->get_fieldset_select(
            'quiz_feedback',
            'feedbacktext',
            'quizid = ?',
            [$other->id]
        )));
    }
}
