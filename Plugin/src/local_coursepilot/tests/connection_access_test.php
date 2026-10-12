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
 * Connection lifecycle and shared access decisions at the public boundary.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection::class)]
final class connection_access_test extends \advanced_testcase {
    /**
     * A consent code creates a usable connection with the unchanged token contract.
     */
    public function test_code_exchange_creates_a_connection(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $code = connection::issue_code(
            'client',
            (int) $user->id,
            'https://client.example/cb',
            'ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0'
        );
        $tokens = connection::exchange_code($code, 'client', 'https://client.example/cb', 'abc');
        $this->assertNotNull($tokens);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(3600, $tokens['expires_in']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokens['access_token']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $tokens['refresh_token']);
        $this->assertSame((int) $user->id, connection::authenticate_access_token($tokens['access_token']));
        $this->assertNull(connection::exchange_code($code, 'client', 'https://client.example/cb', 'abc'));
    }

    /**
     * Access checks preserve the order: connection, active account, remote grant.
     */
    public function test_access_requires_an_active_owned_connection_account_and_remote_grant(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $userid = (int) $user->id;
        $this->assertSame('workbenchticketconnectionrevoked', connection::access_error($userid));
        $code = connection::issue_code(
            'client',
            $userid,
            'https://client.example/cb',
            'ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0'
        );
        $tokens = connection::exchange_code($code, 'client', 'https://client.example/cb', 'abc');
        connection::authenticate_access_token($tokens['access_token']);
        $id = connection::current_connection_id();
        $this->assertSame('remoteaccessnotgranted', connection::access_error($userid, $id));
        $this->assertSame('workbenchticketconnectionrevoked', connection::access_error(2, $id));
        $DB->set_field('user', 'suspended', 1, ['id' => $userid]);
        $this->assertSame('workbenchticketaccountinactive', connection::access_error($userid, $id));
        $DB->set_field('user', 'suspended', 0, ['id' => $userid]);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $this->getDataGenerator()->role_assign($roleid, $userid, \context_system::instance()->id);
        $this->assertNull(connection::access_error($userid, $id));
        $this->assertNull(connection::access_error($userid));
        $tokenid = (int) array_key_first(connection::active_tokens_for_user($userid));
        $this->assertNull(connection::access_error($userid, null, $tokenid));
        $rotated = connection::rotate_refresh_token($tokens['refresh_token'], 'client');
        $this->assertNull(connection::access_error($userid, null, $tokenid));
        $this->assertTrue(connection::revoke_token($tokenid, $userid));
        $this->assertSame('workbenchticketconnectionrevoked', connection::access_error($userid, $id));
        $this->assertSame('workbenchticketconnectionrevoked', connection::access_error($userid, null, $tokenid));
        $this->assertNull(connection::authenticate_access_token($rotated['access_token']));
    }


    /**
     * The connection exposes lifecycle operations, not token-row state or test resets.
     */
    public function test_unused_token_state_methods_are_removed(): void {
        $this->assertNotContains('current_token_id', get_class_methods(connection::class));
        $this->assertNotContains('reset_current_token_id', get_class_methods(connection::class));
        $this->assertNotContains('random_token', get_class_methods(oauth_lib::class));
    }

    /**
     * The emergency switch defaults to enabled and accepts only the explicit disabled setting.
     */
    public function test_emergency_switch_preserves_issued_connections(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $challenge = 'ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0';
        $code = connection::issue_code('client', (int) $user->id, 'https://client.example/cb', $challenge);
        $tokens = connection::exchange_code($code, 'client', 'https://client.example/cb', 'abc');
        $this->assertTrue(connection::remote_access_enabled());
        set_config('remoteaccessenabled', '0', 'local_coursepilot');
        $this->assertFalse(connection::remote_access_enabled());
        $this->assertSame((int) $user->id, connection::authenticate_access_token($tokens['access_token']));
        set_config('remoteaccessenabled', '1', 'local_coursepilot');
        $this->assertTrue(connection::remote_access_enabled());
    }
}
