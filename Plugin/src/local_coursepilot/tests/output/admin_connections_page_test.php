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
 * Template-Datenaufbereitung fuer admin/connections.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(admin_connections_page::class)]
final class admin_connections_page_test extends \advanced_testcase {

    public function test_empty_flag_is_true_without_tokens(): void {
        $this->resetAfterTest();
        $data = admin_connections_page::page_data([]);
        $this->assertTrue($data['empty']);
        $this->assertSame([], $data['rows']);
    }

    public function test_row_includes_person_and_ablageort_lines(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $token = (object) [
            'id' => 7,
            'userid' => $user->id,
            'clientid' => 'client-abc',
            'clientname' => 'Claude Desktop',
            'timecreated' => time(),
            'expires' => time() + 3600,
            'firstname' => $user->firstname,
            'lastname' => $user->lastname,
            'email' => $user->email,
        ];

        $data = admin_connections_page::page_data([$token]);

        $this->assertFalse($data['empty']);
        $row = $data['rows'][0];
        $this->assertStringContainsString($user->email, $row['person']);
        $this->assertStringContainsString('revoke=7', $row['revokeurl']);
        $this->assertNotEmpty($row['ablageortlines']);
    }

    public function test_revokeall_onsubmit_embeds_confirm_text_as_json(): void {
        $this->resetAfterTest();
        $data = admin_connections_page::page_data([]);
        $this->assertStringStartsWith('return confirm(', $data['revokeallonsubmit']);
        $this->assertStringContainsString(
            get_string('connectionrevokeallconfirm', 'local_coursepilot'),
            $data['revokeallonsubmit']
        );
    }
}
