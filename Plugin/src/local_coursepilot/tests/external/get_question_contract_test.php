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
 * Exact content, types, lookup and ordering of checks of get_question.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_question::class)]
final class get_question_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var int Category id. */
    private int $categoryid;

    /**
     * Teacher with a bank and one category.
     */
    private function setup_bank(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = ensure_question_bank::execute($course->id, 'Bank');
        // A decoy in another category, created first, so that unconstrained look-ups would find it instead.
        $decoycategory = (int) ensure_question_category::execute('Decoy category', (int) $bank['topcategoryid'])['id'];
        create_mc_question::execute($decoycategory, 'Decoy', 'Decoy text', 'single', [
            ['answer' => 'Yes', 'fraction' => 1], ['answer' => 'No', 'fraction' => 0],
        ]);
        $this->categoryid = (int) ensure_question_category::execute('Category', (int) $bank['topcategoryid'])['id'];
    }

    /**
     * Creates a multiple-choice question from a fraction list.
     *
     * @param string $name
     * @param float[] $fractions
     * @param bool $single
     * @return \stdClass
     */
    private function mc(string $name, array $fractions, bool $single = true): \stdClass {
        $answers = [];
        foreach ($fractions as $i => $fraction) {
            $answers[] = ['answer' => 'A' . $i, 'fraction' => $fraction, 'feedback' => 'F' . $i];
        }
        $created = create_mc_question::execute(
            $this->categoryid,
            $name,
            'Text of ' . $name,
            $single ? 'single' : 'multiple',
            $answers
        );
        return (object) ['id' => $created['questionid'], 'entry' => $created['questionbankentryid']];
    }

    public function test_full_response_has_exact_values_and_declared_types(): void {
        global $DB;
        $this->setup_bank();
        $this->mc('Decoy', [0, 1]);
        $question = $this->mc('Target', [0, 1, 0]);

        $result = get_question::execute($this->categoryid, 'Target');

        $this->assert_declared_types($result, get_question::execute_returns());
        $answerids = array_keys($DB->get_records('question_answers', ['question' => $question->id], 'id'));
        $this->assertSame([
            'questionid' => $question->id,
            'questionbankentryid' => $question->entry,
            'categoryid' => $this->categoryid,
            'version' => 1,
            'name' => 'Target',
            'questiontext' => 'Text of Target',
            'generalfeedback' => '',
            'qtype' => 'multichoice',
            'defaultmark' => 1.0,
            'answers' => [
                ['id' => (int) $answerids[0], 'answer' => 'A0', 'fraction' => 0.0, 'feedback' => 'F0', 'correct' => false],
                ['id' => (int) $answerids[1], 'answer' => 'A1', 'fraction' => 1.0, 'feedback' => 'F1', 'correct' => true],
                ['id' => (int) $answerids[2], 'answer' => 'A2', 'fraction' => 0.0, 'feedback' => 'F2', 'correct' => false],
            ],
            'correctindex' => 1,
            'selectionmode' => 'single',
        ], $result);
    }

    public function test_partial_credit_counts_as_correct_but_not_as_the_full_answer(): void {
        $this->setup_bank();
        $this->mc('Multiple', [0.5, 0.5, 0], false);

        $result = get_question::execute($this->categoryid, 'Multiple');

        $this->assertSame([true, true, false], array_column($result['answers'], 'correct'));
        $this->assertSame(-1, $result['correctindex']);
        $this->assertSame('multiple', $result['selectionmode']);
    }

    public function test_the_latest_version_is_returned_for_any_version_id(): void {
        $this->setup_bank();
        $first = $this->mc('Versioned', [1, 0]);
        $second = update_mc_question::execute($first->id, '{"questiontext":"Second text","defaultmark":2.5}');

        $byid = get_question::execute($this->categoryid, '', $first->id);
        $byname = get_question::execute($this->categoryid, 'Versioned');

        $this->assertSame($byid, $byname);
        $this->assertSame(2, $byid['version']);
        $this->assertSame($second['questionid'], $byid['questionid']);
        $this->assertSame('Second text', $byid['questiontext']);
        $this->assertSame(2.5, $byid['defaultmark']);
    }

    public function test_a_lookup_by_id_ignores_the_name_and_other_questions(): void {
        $this->setup_bank();
        $this->mc('Other', [1, 0]);
        $wanted = $this->mc('Wanted', [0, 1]);

        $result = get_question::execute($this->categoryid, 'Other', $wanted->id);

        $this->assertSame('Wanted', $result['name']);
    }

    public function test_missing_lookup_arguments_and_unknown_questions_are_refused_with_their_reason(): void {
        $this->setup_bank();

        $none = $this->caught(fn() => get_question::execute($this->categoryid));
        $this->assertInstanceOf(\invalid_parameter_exception::class, $none);
        $this->assertSame('Either name or questionid must be specified.', $none->debuginfo);

        foreach ([['Nobody', 0], ['', 987654]] as [$name, $id]) {
            $e = $this->caught(fn() => get_question::execute($this->categoryid, $name, $id));
            $this->assertInstanceOf(\moodle_exception::class, $e);
            $this->assertSame('notfound', $e->errorcode);
            $this->assertSame('No question found for the given criteria.', $e->debuginfo);
        }
    }

    public function test_the_context_restriction_is_checked_before_the_lookup(): void {
        $this->setup_bank();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $e = $this->caught(fn() => get_question::execute($this->categoryid, 'Nobody'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    public function test_each_capability_is_checked_before_the_lookup(): void {
        global $DB;
        $this->setup_bank();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);

        foreach (['local/coursepilot:use', 'moodle/question:viewall'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => get_question::execute($this->categoryid, 'Nobody'));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_INHERIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }
}
