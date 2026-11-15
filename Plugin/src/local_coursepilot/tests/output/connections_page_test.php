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
 * Template data preparation for connections.php (#552, Spec 0023 part 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connections_page::class)]
final class connections_page_test extends \advanced_testcase {
    public function test_empty_flag_is_true_without_tokens(): void {
        $this->resetAfterTest();
        $data = connections_page::page_data([], ['context_area' => [], 'material_store' => []], new \moodle_url('/'));
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

        $data = connections_page::page_data([$token], ['context_area' => [], 'material_store' => []], new \moodle_url('/'));

        $this->assertFalse($data['empty']);
        $this->assertSame('client-abc', $data['rows'][0]['clientname']);
        $this->assertStringContainsString('revoke=42', $data['rows'][0]['revokeurl']);
    }
    public function test_response_displays_only_the_logged_in_users_connections(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $response = connections_page::response(0);
        $this->assertSame('local_coursepilot/connections', $response->template);
        $this->assertTrue($response->data['empty']);
        $this->assertNull($response->redirecturl);
    }
    public function test_revocation_rejects_foreign_connections_and_requires_a_session_key(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $other = $this->getDataGenerator()->create_user();
        $ids = [];
        foreach ([(int) $USER->id, (int) $other->id] as $userid) {
            $verifier = str_repeat('v', 43);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $code = \local_coursepilot\connection::issue_code('client', $userid, 'https://client.example/cb', $challenge);
            \local_coursepilot\connection::exchange_code($code, 'client', 'https://client.example/cb', $verifier);
            $ids[] = (int) array_key_first(\local_coursepilot\connection::active_tokens_for_user($userid));
        }
        $this->assertCount(1, connections_page::response(0)->data['rows']);
        $_POST['sesskey'] = 'invalid';
        try {
            connections_page::response($ids[0]);
            $this->fail('Revocation without a session key must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidsesskey', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertCount(1, \local_coursepilot\connection::active_tokens_for_user((int) $USER->id));
        $_POST['sesskey'] = sesskey();
        try {
            $this->assertNotNull(connections_page::response($ids[1])->redirecturl);
            $this->assertCount(1, \local_coursepilot\connection::active_tokens_for_user((int) $other->id));
            $this->assertNotNull(connections_page::response($ids[0])->redirecturl);
            $this->assertTrue(connections_page::response(0)->data['empty']);
        } finally {
            unset($_POST['sesskey']);
        }
    }

    public function test_response_denies_users_without_remote_access(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('REMOTE_ACCESS_NOT_GRANTED');
        connections_page::response(0);
    }
}
