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
 * Exact lineage rows, statuses and messages of report_clone_lineage.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(report_clone_lineage::class)]
final class report_clone_lineage_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course of the quiz. */
    private \stdClass $course;

    /** @var \stdClass Other course holding the source bank. */
    private \stdClass $source;

    /** @var \stdClass The quiz. */
    private \stdClass $quiz;

    /** @var \core_question_generator Generator. */
    private \core_question_generator $generator;

    /**
     * Quiz in a course, plus a second course; teacher is editing teacher in both.
     */
    private function setup_quiz(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->source = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        foreach ([$this->course, $this->source] as $course) {
            $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        }
        $this->setUser($teacher);
        /** @var \mod_quiz_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $this->quiz = $modgen->create_instance(['course' => $this->course->id]);
        /** @var \core_question_generator $questiongen */
        $questiongen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->generator = $questiongen;
    }

    /**
     * Adds a named true/false question from the bank of a course to the quiz.
     *
     * @param \stdClass $course Course owning the bank.
     * @param string $name
     * @param string|null $idnumber
     * @return \stdClass
     */
    private function add(\stdClass $course, string $name, ?string $idnumber = null): \stdClass {
        global $DB;
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $category = $this->generator->create_question_category(['contextid' => \context_module::instance($qbank->cmid)->id]);
        $question = $this->generator->create_question('truefalse', null, ['category' => $category->id, 'name' => $name]);
        if ($idnumber !== null) {
            $entry = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
            $DB->set_field('question_bank_entries', 'idnumber', $idnumber, ['id' => $entry]);
        }
        quiz_add_quiz_question($question->id, $this->quiz);
        return $question;
    }

    public function test_rows_are_exact_in_slot_order_with_their_origin(): void {
        global $DB;
        $this->setup_quiz();
        $own = $this->add($this->course, 'Own question', 'own-1');
        $shared = $this->add($this->source, 'Shared question');

        $result = report_clone_lineage::execute((int) $this->quiz->cmid);

        // The cmid is passed on as the database value (a numeric string); clean_returnvalue() makes it an int.
        $this->assert_declared_types(
            array_replace($result, ['cmid' => (int) $result['cmid']]),
            report_clone_lineage::execute_returns()
        );
        $entry = static fn(\stdClass $q) => (int) $DB->get_field(
            'question_versions',
            'questionbankentryid',
            ['questionid' => $q->id]
        );
        $this->assertSame([
            'cmid' => $result['cmid'],
            'questions' => [
                [
                    'slot' => 1, 'questionbankentryid' => $entry($own), 'questionid' => (int) $own->id,
                    'name' => 'Own question', 'idnumber' => 'own-1', 'status' => 'own_copy', 'source_course_id' => 0,
                ],
                [
                    'slot' => 2, 'questionbankentryid' => $entry($shared), 'questionid' => (int) $shared->id,
                    'name' => 'Shared question', 'idnumber' => '', 'status' => 'shared_reference',
                    'source_course_id' => (int) $this->source->id,
                ],
            ],
            'message' => '1 question(s) created as their own copy, 1 question(s) still point to the source course '
                . '(shared reference).',
        ], $result);
        $this->assertEquals($this->quiz->cmid, $result['cmid']);
    }

    public function test_messages_for_no_all_own_and_all_shared_questions(): void {
        $this->setup_quiz();
        $this->assertSame('The quiz contains no questions.', report_clone_lineage::execute((int) $this->quiz->cmid)['message']);

        $this->add($this->course, 'Own');
        $this->assertSame(
            '1 question(s) created as their own copy.',
            report_clone_lineage::execute((int) $this->quiz->cmid)['message']
        );
    }

    public function test_all_shared_questions_are_reported_as_such(): void {
        $this->setup_quiz();
        $this->add($this->source, 'Shared A');
        $this->add($this->source, 'Shared B');

        $this->assertSame(
            '2 question(s) still point to the source course (shared reference) - '
                . 'a correction there also changes the question here.',
            report_clone_lineage::execute((int) $this->quiz->cmid)['message']
        );
    }

    public function test_the_newest_version_of_a_referenced_question_is_reported(): void {
        $this->setup_quiz();
        $question = $this->add($this->course, 'Old name');
        $newer = $this->generator->update_question($question, null, ['name' => 'New name']);

        $result = report_clone_lineage::execute((int) $this->quiz->cmid);

        $this->assertCount(1, $result['questions']);
        $this->assertSame((int) $newer->id, $result['questions'][0]['questionid']);
        $this->assertSame('New name', $result['questions'][0]['name']);
    }

    public function test_only_quizzes_are_accepted(): void {
        $this->setup_quiz();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);

        $e = $this->caught(fn() => report_clone_lineage::execute((int) $page->cmid));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('cmid must be a quiz (mod_quiz), got: "page".', $e->debuginfo);
    }

    public function test_the_context_restriction_and_each_capability_are_checked(): void {
        global $DB;
        $this->setup_quiz();
        $third = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($third->id));
        try {
            $restricted = $this->caught(fn() => report_clone_lineage::execute((int) $this->quiz->cmid));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'moodle/question:viewall'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => report_clone_lineage::execute((int) $this->quiz->cmid));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }

    public function test_questions_outside_any_course_are_shared_references_without_a_course(): void {
        global $DB;
        $this->setup_quiz();
        $system = $this->add($this->source, 'In the system context');
        $vanished = $this->add($this->source, 'In a vanished context');
        $categoryof = static function (\stdClass $question) use ($DB): int {
            $entry = $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id]);
            return (int) $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $entry]);
        };
        $DB->set_field('question_categories', 'contextid', SYSCONTEXTID, ['id' => $categoryof($system)]);
        $DB->set_field('question_categories', 'contextid', 987654, ['id' => $categoryof($vanished)]);

        $result = report_clone_lineage::execute((int) $this->quiz->cmid);

        $this->assertSame(['shared_reference', 'shared_reference'], array_column($result['questions'], 'status'));
        $this->assertSame([0, 0], array_column($result['questions'], 'source_course_id'));
    }
}
