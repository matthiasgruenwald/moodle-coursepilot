<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\output;

use local_coursepilot\oauth_lib;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Template data preparation for admin/connections.php (#552, Spec 0023 part 5).
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

    public function test_row_includes_person_and_storagelocation_lines(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $tokenid = $this->issue_token((int) $user->id);

        // Use actual oauth_lib::active_tokens() rows to catch missing name fields
        // required by fullname(), which caused the notice in #578.
        $data = admin_connections_page::page_data(oauth_lib::active_tokens());

        $this->assertDebuggingNotCalled();
        $this->assertFalse($data['empty']);
        $row = $data['rows'][0];
        $this->assertStringContainsString(fullname($user), $row['person']);
        $this->assertStringContainsString($user->email, $row['person']);
        $this->assertStringContainsString('revoke=' . $tokenid, $row['revokeurl']);
        $this->assertNotEmpty($row['storagelocationlines']);
    }

    /**
     * Issue an active token for $userid through the regular OAuth flow.
     *
     * @param int $userid
     * @return int Token row ID.
     */
    private function issue_token(int $userid): int {
        $registration = oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Claude Desktop',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]), '192.0.2.1');
        $clientid = $registration['body']['client_id'];
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = oauth_lib::issue_code($clientid, $userid, 'https://claude.ai/api/mcp/auth_callback', $challenge);
        $this->assertNotNull(oauth_lib::exchange_code($code, $clientid, 'https://claude.ai/api/mcp/auth_callback', $verifier));
        return (int) array_key_first(oauth_lib::active_tokens_for_user($userid));
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
