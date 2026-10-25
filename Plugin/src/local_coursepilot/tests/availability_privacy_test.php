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

namespace local_coursepilot;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Masking of profile conditions in availability JSON before it reaches an AI (#341).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(availability_privacy::class)]
final class availability_privacy_test extends \advanced_testcase {
    public function test_empty_and_unparseable_input_yield_an_empty_string(): void {
        $this->assertSame('', availability_privacy::sanitize(''));
        $this->assertSame('', availability_privacy::sanitize('{not json'));
    }

    public function test_profile_condition_value_is_masked_and_other_fields_are_kept(): void {
        $json = json_encode(['op' => '&', 'c' => [
            ['type' => 'profile', 'sf' => 'email', 'op' => 'isequalto', 'v' => 'x@example.test'],
            ['type' => 'grade', 'id' => 5, 'min' => 50],
        ], 'showc' => [true, true]]);

        $result = json_decode(availability_privacy::sanitize($json), true);

        $this->assertSame('***', $result['c'][0]['v']);
        $this->assertSame('email', $result['c'][0]['sf']);
        $this->assertSame(['type' => 'grade', 'id' => 5, 'min' => 50], $result['c'][1]);
    }

    public function test_nested_condition_groups_are_masked_recursively(): void {
        $json = json_encode(['op' => '|', 'c' => [
            ['op' => '&', 'c' => [['type' => 'profile', 'cf' => 'secret', 'v' => 'geheim']]],
        ]]);

        $result = json_decode(availability_privacy::sanitize($json), true);

        $this->assertSame('***', $result['c'][0]['c'][0]['v']);
    }

    public function test_document_without_condition_list_is_returned_unchanged(): void {
        $this->assertSame('{"op":"&"}', availability_privacy::sanitize('{"op":"&"}'));
    }

    public function test_document_without_condition_list_keeps_all_of_its_keys(): void {
        $this->assertSame(
            '{"op":"&","show":true,"id":5}',
            availability_privacy::sanitize('{"op":"&","show":true,"id":5}')
        );
    }
}
