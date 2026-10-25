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
use mod_quiz\question\display_options;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact review decomposition, feedback conversion and boundary rules of the quiz write bridge (Spec 0015 §5, #396).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(quiz_write_bridge::class)]
final class quiz_write_bridge_contract_test extends \advanced_testcase {
    use caught_throwable;

    public function test_review_bitmasks_become_one_zero_or_one_per_type_and_timing(): void {
        $quiz = (object) [
            'reviewattempt' => (string) (display_options::DURING | display_options::AFTER_CLOSE),
            'reviewcorrectness' => display_options::IMMEDIATELY_AFTER | display_options::LATER_WHILE_OPEN,
            'reviewoverallfeedback' => display_options::DURING,
        ];

        $result = quiz_write_bridge::decompose_review_bitmasks($quiz);

        $this->assertCount(32, $result);
        $this->assertSame(1, $result['attemptduring']);
        $this->assertSame(0, $result['attemptimmediately']);
        $this->assertSame(0, $result['attemptopen']);
        $this->assertSame(1, $result['attemptclosed']);
        $this->assertSame([0, 1, 1, 0], [
            $result['correctnessduring'],
            $result['correctnessimmediately'],
            $result['correctnessopen'],
            $result['correctnessclosed'],
        ]);
        $this->assertSame(1, $result['overallfeedbackduring']);
        $this->assertSame(0, $result['marksduring']);
        $this->assertSame(array_fill(0, 32, true), array_map(static fn($v): bool => $v === 0 || $v === 1, array_values($result)));
    }

    public function test_feedback_is_read_in_descending_order_with_one_boundary_fewer_than_texts(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $other = $generator->create_instance(['course' => $course->id]);
        $quiz = $generator->create_instance(['course' => $course->id]);
        $DB->delete_records('quiz_feedback');
        foreach (
            [[$quiz, 'Low', 0, 5], [$quiz, 'High', 7.5, 10.01], [$quiz, 'Middle', 5, 7.5], [$other, 'Foreign', 0, 10.01]] as [
                $q,
                $text,
                $min,
                $max,
            ]
        ) {
            $DB->insert_record('quiz_feedback', (object) [
                'quizid' => $q->id,
                'feedbacktext' => $text,
                'feedbacktextformat' => FORMAT_HTML,
                'mingrade' => $min,
                'maxgrade' => $max,
            ]);
        }

        $feedback = quiz_write_bridge::read_feedback((int) $quiz->id);

        $this->assertSame(['High', 'Middle', 'Low'], $feedback['feedbacktext']);
        $this->assertSame([7.5, 5.0], $feedback['feedbackboundaries']);
        $this->assertSame(
            ['feedbacktext' => ['Foreign'], 'feedbackboundaries' => []],
            quiz_write_bridge::read_feedback((int) $other->id)
        );
    }

    public function test_feedback_pseudofields_become_editor_arrays_and_a_plain_boundary_list(): void {
        $info = new \stdClass();

        $texts = $this->decode('{"3":"A","7":5}');
        $bounds = $this->decode('{"2":"50%","9":25}');
        quiz_write_bridge::apply_feedback_pseudofields($info, $texts, $bounds);

        $this->assertSame([
            0 => ['text' => 'A', 'format' => FORMAT_HTML, 'itemid' => 0],
            1 => ['text' => '5', 'format' => FORMAT_HTML, 'itemid' => 0],
        ], $info->feedbacktext);
        $this->assertSame([0 => '50%', 1 => 25], $info->feedbackboundaries);
        $empty = new \stdClass();
        quiz_write_bridge::apply_feedback_pseudofields($empty, [], []);
        $this->assertSame([], $empty->feedbacktext);
        $this->assertSame([], $empty->feedbackboundaries);
    }

    /**
     * Decodes a JSON object into an untyped array.
     *
     * @param string $json
     * @return mixed[]
     */
    private function decode(string $json): array {
        return (array) json_decode($json, true);
    }

    /**
     * The default grade.
     *
     * @return float
     */
    private function grade(): float {
        return quiz_write_bridge::default_grade();
    }

    public function test_the_default_grade_is_the_positive_server_setting_or_ten(): void {
        $this->resetAfterTest();

        set_config('maximumgrade', '7.5', 'quiz');
        $this->assertSame(7.5, $this->grade());
        set_config('maximumgrade', '0', 'quiz');
        $this->assertEquals(10.0, $this->grade());
        set_config('maximumgrade', '-3', 'quiz');
        $this->assertEquals(10.0, $this->grade());
        unset_config('maximumgrade', 'quiz');
        $this->assertEquals(10.0, $this->grade());
    }

    /**
     * Violation messages of feedback rules.
     *
     * @param callable $call Call that must be refused.
     * @return string The message given to the violation.
     */
    private function violation(callable $call): string {
        $e = $this->caught($call);
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('combinationruleviolation', $e->errorcode);
        $this->assertSame('quiz', $e->a['modname']);
        return $e->a['message'];
    }

    /**
     * Checks feedback rules against a patch.
     *
     * @param mixed[] $texts
     * @param mixed[] $boundaries
     * @param float $grade
     */
    private function check(array $texts, array $boundaries, float $grade = 10.0): void {
        quiz_write_bridge::validate_combination_rules(
            [],
            ['feedbacktext' => $texts, 'feedbackboundaries' => $boundaries],
            $grade
        );
    }

    public function test_feedback_boundaries_must_lie_strictly_inside_the_grade_and_descend(): void {
        $this->assertSame(
            '"feedbackboundaries" must be between 0 and the maximum grade (10).',
            $this->violation(fn() => $this->check(['a', 'b'], [10]))
        );
        $this->assertSame(
            '"feedbackboundaries" must be between 0 and the maximum grade (10).',
            $this->violation(fn() => $this->check(['a', 'b'], [0]))
        );
        $this->assertSame(
            '"feedbackboundaries" must be sorted descending.',
            $this->violation(fn() => $this->check(['a', 'b', 'c'], [5, 5]))
        );
        $this->assertSame(
            '"feedbackboundaries" must be sorted descending.',
            $this->violation(fn() => $this->check(['a', 'b', 'c'], [3, 5]))
        );
        $this->succeeded(fn () => $this->check(['a', 'b', 'c'], [9.99, 0.01]));
        $this->succeeded(fn () => $this->check(['a', 'b', 'c', 'd'], [' 80 % ', '50%', 2]));
        $this->succeeded(fn () => $this->check(['a', 'b'], ['99.9%']));
        $this->addToAssertionCount(1);
    }

    public function test_percentages_resolve_against_the_grade_and_other_text_is_a_plain_number(): void {
        $resolve = new \ReflectionMethod(quiz_write_bridge::class, 'resolve_boundary');

        $this->assertSame(5.0, $this->succeeded(fn () => $resolve->invoke(null, '50%', 10.0)));
        $this->assertSame(2.5, $this->succeeded(fn () => $resolve->invoke(null, ' 25 % ', 10.0)));
        $this->assertSame(5.0, $this->succeeded(fn () => $resolve->invoke(null, 5, 10.0)));
        $this->assertSame(7.5, $this->succeeded(fn () => $resolve->invoke(null, '7.5', 10.0)));
        $this->assertSame(5.0, $this->succeeded(fn () => $resolve->invoke(null, '5%0', 10.0)));
        $this->assertSame(0.0, $this->succeeded(fn () => $resolve->invoke(null, '', 10.0)));
        $this->assertSame(0.0, $this->succeeded(fn () => $resolve->invoke(null, '%', 10.0)));
    }

    public function test_the_feedback_list_shape_is_checked_with_exact_counts(): void {
        $this->assertSame(
            '"feedbacktext" must contain at least one entry.',
            $this->violation(fn() => $this->check([], []))
        );
        $this->assertSame(
            '"feedbackboundaries" must have exactly one fewer entry than "feedbacktext" (2 text(s), 2 boundary/boundaries).',
            $this->violation(fn() => $this->check(['a', 'b'], [4, 3]))
        );
        $this->assertSame(
            '"feedbackboundaries" must have exactly one fewer entry than "feedbacktext" (3 text(s), 1 boundary/boundaries).',
            $this->violation(fn() => $this->check(['a', 'b', 'c'], [4]))
        );
        $this->check(['only'], []);
    }
}
