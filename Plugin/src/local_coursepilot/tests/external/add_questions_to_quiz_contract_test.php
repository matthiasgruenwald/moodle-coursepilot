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

/**
 * Exact response, messages and check order of add_questions_to_quiz.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(add_questions_to_quiz::class)]
final class add_questions_to_quiz_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var \stdClass The quiz receiving questions. */
    private \stdClass $quiz;

    /** @var \stdClass Question category. */
    private \stdClass $category;

    /** @var \core_question_generator Generator. */
    private \core_question_generator $generator;

    /**
     * Course with a decoy quiz first, the target quiz, a bank category and an editing teacher.
     */
    private function setup_quiz(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        /** @var \mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quizgenerator->create_instance(['course' => $this->course->id, 'name' => 'Decoy']);
        $this->quiz = $quizgenerator->create_instance(['course' => $this->course->id, 'name' => 'Target']);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        /** @var \core_question_generator $questiongen */
        $questiongen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->generator = $questiongen;
        $this->category = $this->generator->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * Creates a true/false question.
     *
     * @param string $name
     * @return \stdClass
     */
    private function question(string $name): \stdClass {
        return $this->generator->create_question('truefalse', null, ['category' => $this->category->id, 'name' => $name]);
    }

    /**
     * Bank entry id of a question.
     *
     * @param \stdClass $question
     * @return int
     */
    private function entry_of(\stdClass $question): int {
        global $DB;
        return (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id], MUST_EXIST);
    }

    public function test_response_is_exact_for_added_and_skipped_questions(): void {
        $this->setup_quiz();
        $a = $this->question('Frage A');
        $b = $this->question('Frage B');
        $c = $this->question('Frage C');
        quiz_add_quiz_question($b->id, $this->quiz);

        $result = add_questions_to_quiz::execute((int) $this->quiz->cmid, [$a->id, $b->id, $c->id]);

        $this->assert_declared_types($result, add_questions_to_quiz::execute_returns());
        $this->assertSame([
            'cmid' => (int) $this->quiz->cmid,
            'message' => '2 question(s) appended: "Frage A", "Frage C". 1 already present, skipped: "Frage B".',
            'appended' => [
                ['questionid' => (int) $a->id, 'questionbankentryid' => $this->entry_of($a), 'name' => 'Frage A', 'added' => true],
                ['questionid' => (int) $b->id, 'questionbankentryid' => $this->entry_of($b), 'name' => 'Frage B', 'added' => false],
                ['questionid' => (int) $c->id, 'questionbankentryid' => $this->entry_of($c), 'name' => 'Frage C', 'added' => true],
            ],
            'slots' => [
                [
                    'slot' => 1,
                    'questionbankentryid' => $this->entry_of($b),
                    'questionid' => (int) $b->id,
                    'version' => 1,
                    'name' => 'Frage B',
                ],
                [
                    'slot' => 2,
                    'questionbankentryid' => $this->entry_of($a),
                    'questionid' => (int) $a->id,
                    'version' => 1,
                    'name' => 'Frage A',
                ],
                [
                    'slot' => 3,
                    'questionbankentryid' => $this->entry_of($c),
                    'questionid' => (int) $c->id,
                    'version' => 1,
                    'name' => 'Frage C',
                ],
            ],
        ], $result);
    }

    public function test_message_for_only_skipped_questions_has_no_appended_part(): void {
        $this->setup_quiz();
        $a = $this->question('Frage A');
        quiz_add_quiz_question($a->id, $this->quiz);

        $result = add_questions_to_quiz::execute((int) $this->quiz->cmid, [$a->id]);

        $this->assertSame('1 already present, skipped: "Frage A".', $result['message']);
    }

    public function test_only_the_requested_quiz_changes(): void {
        global $DB;
        $this->setup_quiz();
        $a = $this->question('Frage A');
        $decoy = $DB->get_field('quiz', 'id', ['name' => 'Decoy']);

        add_questions_to_quiz::execute((int) $this->quiz->cmid, [$a->id]);

        $this->assertSame(0, $DB->count_records('quiz_slots', ['quizid' => $decoy]));
        $this->assertSame(1, $DB->count_records('quiz_slots', ['quizid' => $this->quiz->id]));
    }

    public function test_a_quiz_with_attempts_is_blocked_and_names_itself(): void {
        global $DB;
        $this->setup_quiz();
        $a = $this->question('Frage A');
        $student = $this->getDataGenerator()->create_user();
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $this->quiz->id, 'userid' => $student->id, 'attempt' => 1, 'uniqueid' => 987654,
            'layout' => '', 'currentpage' => 0, 'preview' => 0, 'state' => 'finished',
            'timestart' => time(), 'timefinish' => time(), 'timemodified' => time(),
            'timemodifiedoffline' => 0, 'timecheckstate' => null, 'sumgrades' => 0, 'gradednotificationsenttime' => null,
        ]);

        $e = $this->caught(fn() => add_questions_to_quiz::execute((int) $this->quiz->cmid, [$a->id]));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('addquestionstoquizblocked', $e->errorcode);
        $this->assertEquals(['quizid' => $this->quiz->id], $e->a);
        $this->assertSame(0, $DB->count_records('quiz_slots', ['quizid' => $this->quiz->id]));
    }

    public function test_an_empty_list_is_refused(): void {
        $this->setup_quiz();

        $e = $this->caught(fn() => add_questions_to_quiz::execute((int) $this->quiz->cmid, []));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('Specify at least one question.', $e->debuginfo);
    }

    public function test_a_write_creates_a_history_version_only_when_something_was_added(): void {
        global $DB;
        $this->setup_quiz();
        $a = $this->question('Frage A');
        quiz_add_quiz_question($a->id, $this->quiz);
        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $this->quiz->cmid]);

        add_questions_to_quiz::execute((int) $this->quiz->cmid, [$a->id]);
        $this->assertSame($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $this->quiz->cmid]));

        add_questions_to_quiz::execute((int) $this->quiz->cmid, [$this->question('Frage B')->id]);
        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $this->quiz->cmid]));
    }

    public function test_the_context_restriction_and_each_capability_are_checked_before_the_attempt_gate_and_the_list(): void {
        global $DB;
        $this->setup_quiz();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => add_questions_to_quiz::execute((int) $this->quiz->cmid, []));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'mod/quiz:manage'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => add_questions_to_quiz::execute((int) $this->quiz->cmid, []));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }

    public function test_the_right_to_use_each_question_is_checked(): void {
        global $DB;
        $this->setup_quiz();
        $a = $this->question('Frage A');
        $DB->set_field('question', 'createdby', $this->getDataGenerator()->create_user()->id, ['id' => $a->id]);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/question:useall', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);

        $e = $this->caught(fn() => add_questions_to_quiz::execute((int) $this->quiz->cmid, [$a->id]));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('nopermissions', $e->errorcode);
        $this->assertSame(0, $DB->count_records('quiz_slots', ['quizid' => $this->quiz->id]));
    }
}
