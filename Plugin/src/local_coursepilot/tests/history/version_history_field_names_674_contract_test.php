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
namespace local_coursepilot\history;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The history summary names at most four changed fields and counts the rest exactly (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(version_history::class)]
final class version_history_field_names_674_contract_test extends \advanced_testcase {
    /**
     * Field lists around the four-name limit with the exact text the summary shows.
     *
     * @return array<string, array{string[], string}>
     */
    public static function field_lists(): array {
        return [
            'one field' => [['a'], 'a'],
            'exactly four fields name all of them' => [['a', 'b', 'c', 'd'], 'a, b, c, d'],
            'five fields count one more' => [['a', 'b', 'c', 'd', 'e'], 'a, b, c, d and 1 more fields'],
            'seven fields count three more' => [
                ['a', 'b', 'c', 'd', 'e', 'f', 'g'], 'a, b, c, d and 3 more fields',
            ],
        ];
    }

    /**
     * Names the first four fields and counts the remaining ones.
     *
     * @param string[] $fields Changed field names.
     * @param string $expected Text shown in the summary.
     */
    #[DataProvider('field_lists')]
    public function test_the_summary_names_four_fields_and_counts_the_rest(array $fields, string $expected): void {
        $this->resetAfterTest();

        $method = new \ReflectionMethod(version_history::class, 'field_names');

        $this->assertSame($expected, $method->invoke(null, $fields, 'en'));
    }
}
