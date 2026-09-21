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

namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Template-Datenaufbereitung fuer connections.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connections_page::class)]
final class connections_page_test extends \advanced_testcase {

    public function test_empty_flag_is_true_without_tokens(): void {
        $this->resetAfterTest();
        $data = connections_page::page_data([], ['kontextbereich' => [], 'materialbestand' => []], new \moodle_url('/'));
        $this->assertTrue($data['empty']);
        $this->assertSame([], $data['rows']);
    }

    public function test_token_row_falls_back_to_clientid_without_clientname(): void {
        $this->resetAfterTest();
        $token = (object) [
            'id' => 42,
            'clientid' => 'client-abc',
            'clientname' => null,
            'timecreated' => time(),
            'expires' => time() + 3600,
        ];

        $data = connections_page::page_data([$token], ['kontextbereich' => [], 'materialbestand' => []], new \moodle_url('/'));

        $this->assertFalse($data['empty']);
        $this->assertSame('client-abc', $data['rows'][0]['clientname']);
        $this->assertStringContainsString('revoke=42', $data['rows'][0]['revokeurl']);
    }
}
