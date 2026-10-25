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
use local_coursepilot\tests\learner_locks_fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Evaluation, rendering and confirmation of learner locks.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(learner_locks::class)]
final class learner_locks_contract_test extends \basic_testcase {
    use caught_throwable;

    /**
     * Conditions with values and whether they match.
     *
     * @return array<string, array{array<string, mixed>, mixed, bool}>
     */
    public static function matching(): array {
        $equals = ['op' => 'equals', 'value' => 'locked'];
        $number = ['op' => 'equals', 'value' => 1];
        $greater = ['op' => 'greater', 'value' => '1.5'];
        return [
            'null never locks' => [['op' => 'not_equals', 'value' => 'x'], null, false],
            'equal text' => [$equals, 'locked', true],
            'other text' => [$equals, 'open', false],
            'text compared as text' => [['op' => 'equals', 'value' => 'a'], 'A', false],
            'true equals 1' => [$number, true, true],
            'false does not equal 1' => [$number, false, false],
            'numeric string equals number' => [$number, '1.0', true],
            'number against non numeric text' => [$number, 'one', false],
            'non numeric condition against zero' => [['op' => 'equals', 'value' => 'abc'], 0, false],
            'non numeric value against numeric condition' => [['op' => 'equals', 'value' => 0], 'abc', false],
            'numeric text only compares as text with a non numeric partner' => [['op' => 'equals', 'value' => '1'], 'x1', false],
            'not equals true' => [['op' => 'not_equals', 'value' => 1], 0, true],
            'not equals false' => [['op' => 'not_equals', 'value' => 1], '1', false],
            'greater above' => [$greater, '2', true],
            'greater at the border' => [$greater, 1.5, false],
            'greater with text' => [$greater, 'big', false],
            'greater compares as number not text' => [['op' => 'greater', 'value' => '9'], '10', true],
            'nonzero number' => [['op' => 'nonzero'], 3, true],
            'nonzero zero' => [['op' => 'nonzero'], '0', false],
            'nonzero fraction' => [['op' => 'nonzero'], '0.5', true],
            'nonzero text' => [['op' => 'nonzero'], 'abc', false],
            'unknown operator' => [['op' => 'whatever', 'value' => 1], 1, false],
        ];
    }

    /**
     * Matches a value against a condition.
     *
     * @param array<string, mixed> $condition
     * @param mixed $value
     * @param bool $expected
     */
    #[DataProvider('matching')]
    public function test_matches(array $condition, mixed $value, bool $expected): void {
        $this->assertSame($expected, learner_locks::matches($condition, $value));
    }

    public function test_the_confirm_parameter_describes_the_lock_rule(): void {
        $parameter = learner_locks::confirm_parameter();

        $this->assertSame(
            'Learner locks this call sets on purpose. A learner lock is a setting after which a learner needs an '
                . 'action by the teacher to continue or resubmit. A call that would set an unconfirmed lock is '
                . 'rejected with each lock and its reason, and nothing is written. Name a lock here only when the '
                . 'teacher explicitly asked for it; otherwise leave the field out or choose an open value.',
            $parameter->desc
        );
        $this->assertSame([], $parameter->default);
        $this->assertSame(VALUE_DEFAULT, $parameter->required);
    }

    public function test_find_names_each_lock_with_value_and_reason_and_marks_form_defaults(): void {
        $found = learner_locks::find(
            learner_locks_fixture::class,
            ['mode' => 'locked', 'attempts' => 3, 'title' => 'Größe/x'],
            ['password' => 5]
        );

        $this->assertSame([
            ['id' => 'password',
                'detail' => '"password" = 5 (form default, not named in this call): A password blocks learners without it.'],
            ['id' => 'attempts', 'detail' => '"attempts" = 3: Few attempts, unless teacher resets.'],
            ['id' => 'mode', 'detail' => '"mode" = "locked": Locked mode.'],
            ['id' => 'title', 'detail' => '"title" = "Größe/x": Special.'],
        ], $found);
    }

    public function test_a_named_field_wins_over_its_default_and_unset_fields_do_not_lock(): void {
        $this->assertSame(
            [],
            learner_locks::find(learner_locks_fixture::class, ['visible' => 1], ['visible' => 0, 'mode' => null])
        );
        $found = learner_locks::find(learner_locks_fixture::class, [], ['visible' => 0]);
        $this->assertSame(['visible'], array_column($found, 'id'));
    }

    public function test_find_changed_skips_values_that_repeat_the_current_state(): void {
        $before = ['attempts' => 3, 'mode' => 'open', 'password' => 5];

        $same = learner_locks::find_changed(learner_locks_fixture::class, ['attempts' => '3', 'mode' => 'locked'], $before);
        $this->assertSame(['mode'], array_column($same, 'id'));

        $all = learner_locks::find_changed(learner_locks_fixture::class, ['attempts' => 4, 'password' => 6], $before);
        $this->assertSame(['password', 'attempts'], array_column($all, 'id'));

        $this->assertSame([], learner_locks::find_changed(learner_locks_fixture::class, ['attempts' => 3], $before));
    }

    public function test_a_value_is_only_unchanged_when_both_sides_are_scalars(): void {
        // An empty text equals "no value" when both are cast to text, but a missing value is no current state.
        $found = learner_locks::find_changed(learner_locks_fixture::class, ['visible' => ''], []);

        $this->assertSame(['visible'], array_column($found, 'id'));
    }

    public function test_existing_locks_use_the_form_name_first_and_the_alias_column_second(): void {
        $direct = learner_locks::existing(
            learner_locks_fixture::class,
            ['password' => 7, 'storedpassword' => '0', 'attempts' => 5]
        );
        $this->assertSame([
            ['field' => 'password', 'value_json' => '7', 'reason' => 'A password blocks learners without it.'],
            ['field' => 'attempts', 'value_json' => '5', 'reason' => 'Few attempts, unless teacher resets.'],
        ], $direct);

        $viaalias = learner_locks::existing(learner_locks_fixture::class, ['storedpassword' => 1]);
        $this->assertSame(
            [['field' => 'password', 'value_json' => '1', 'reason' => 'A password blocks learners without it.']],
            $viaalias
        );
        $this->assertSame([], learner_locks::existing(learner_locks_fixture::class, []));
    }

    public function test_condition_json_is_the_readable_condition_or_null(): void {
        $this->assertSame(
            '{"op":"equals","value":"locked","reason":"Locked mode."}',
            learner_locks::condition_json(learner_locks_fixture::class, 'mode')
        );
        $this->assertSame('null', learner_locks::condition_json(learner_locks_fixture::class, 'name'));
    }

    public function test_a_selected_mode_confirms_the_locks_of_its_own_bundle_only(): void {
        $confirmed = learner_locks::confirmed_with_mode(['password'], ['mode' => 'locked', 'attempts' => 2], ['attempts' => 5]);

        $this->assertSame(['password', 'mode'], $confirmed);
        $this->assertSame(['mode'], learner_locks::confirmed_with_mode(['mode'], ['mode' => 'locked'], []));
    }

    public function test_unconfirmed_locks_are_rejected_together_and_confirmed_ones_pass(): void {
        $found = [
            ['id' => 'a', 'detail' => 'Detail A'],
            ['id' => 'b', 'detail' => 'Detail B'],
            ['id' => 'c', 'detail' => 'Detail C'],
        ];

        $this->assertNull($this->caught(fn() => learner_locks::assert_confirmed('quiz', $found, ['a', 'b', 'c'])));
        $e = $this->caught(fn() => learner_locks::assert_confirmed('quiz', $found, ['b']));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
        $this->assertSame([
            'modname' => 'quiz',
            'locks' => 'Detail A | Detail C',
            'ids' => '["a","c"]',
            'parameter' => 'confirm_learner_locks',
        ], $e->a);
    }
}
