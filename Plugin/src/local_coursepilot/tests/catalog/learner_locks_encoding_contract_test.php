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

/**
 * Readable JSON, boolean comparison and list shape of learner locks (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(learner_locks::class)]
final class learner_locks_encoding_contract_test extends \basic_testcase {
    use caught_throwable;

    public function test_false_equals_zero_in_both_directions(): void {
        $this->assertTrue(learner_locks::matches(['op' => 'equals', 'value' => 0], false));
        $this->assertTrue(learner_locks::matches(['op' => 'equals', 'value' => false], 0));
        $this->assertTrue(learner_locks::matches(['op' => 'equals', 'value' => false], '0'));
        $this->assertFalse(learner_locks::matches(['op' => 'equals', 'value' => false], 1));
    }

    public function test_stored_values_and_conditions_stay_readable_json(): void {
        $existing = learner_locks::existing(learner_locks_fixture::class, ['title' => 'Größe/x']);

        $this->assertSame([['field' => 'title', 'value_json' => '"Größe/x"', 'reason' => 'Special.']], $existing);
        $this->assertSame(
            '{"op":"equals","value":"Größe/x","reason":"Special."}',
            learner_locks::condition_json(learner_locks_fixture::class, 'title')
        );
    }

    public function test_the_ids_of_open_locks_stay_readable_json(): void {
        $e = $this->caught(static fn() => learner_locks::assert_confirmed(
            'quiz',
            [['id' => 'Größe/x', 'detail' => 'Detail']],
            []
        ));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('["Größe/x"]', $e->a['ids']);
    }

    public function test_mode_confirmation_returns_a_plain_list_without_duplicates(): void {
        $this->assertSame(
            ['a', 'b', 'c'],
            learner_locks::confirmed_with_mode(['a', 'b'], ['a' => 1, 'c' => 2], [])
        );
    }
}
