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
 * Connection grants and actual revocations appear in Moodle's security log.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection::class)]
#[CoversClass(event\connection_granted::class)]
#[CoversClass(event\connection_revoked::class)]
final class connection_event_test extends \advanced_testcase {
    /**
     * Consent grants one connection; rotation and refused exchanges grant none.
     */
    public function test_grant_is_logged_once_without_logging_rotation_or_secrets(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $sink = $this->redirectEvents();
        $tokens = $this->issue_pair((int) $user->id);
        connection::authenticate_access_token($tokens['access_token']);
        $id = connection::current_connection_id();
        $this->assertCount(1, $sink->get_events());
        $event = $sink->get_events()[0];
        $this->assert_connection_event($event, 'connection_granted', (int) $id, (int) $user->id);
        $this->assertSame((int) $user->id, $event->userid);
        $data = json_encode($event->get_data());
        $this->assertIsString($data);
        $this->assertStringNotContainsString($tokens['access_token'], $data);
        $this->assertStringNotContainsString($tokens['refresh_token'], $data);
        $rotated = connection::rotate_refresh_token($tokens['refresh_token'], 'client');
        $this->assertNotNull($rotated);
        $this->assertNull(connection::exchange_code('unknown', 'client', 'https://client.example/cb', 'abc'));
        $this->assertCount(1, $sink->get_events());
    }

    /**
     * A rejected owner and an already revoked connection produce no revocation event.
     */
    public function test_revocation_logs_the_actor_owner_and_stable_connection_once(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $tokens = $this->issue_pair((int) $user->id);
        connection::authenticate_access_token($tokens['access_token']);
        $id = connection::current_connection_id();
        $tokenid = (int) array_key_first(connection::active_tokens_for_user((int) $user->id));
        $sink = $this->redirectEvents();
        $this->assertFalse(connection::revoke_token($tokenid, (int) $USER->id));
        $this->assertCount(0, $sink->get_events());
        $this->assertTrue(connection::revoke_token($tokenid, (int) $user->id));
        $this->assertCount(1, $sink->get_events());
        $event = $sink->get_events()[0];
        $this->assert_connection_event($event, 'connection_revoked', (int) $id, (int) $user->id);
        $this->assertSame((int) $USER->id, (int) $event->userid);
        $this->assertFalse(connection::revoke_token($tokenid, (int) $user->id));
        $this->assertCount(1, $sink->get_events());
        $this->assertNull(connection::authenticate_access_token($tokens['access_token']));
    }

    /**
     * Proven refresh replay logs the connection's security revocation, not each generation.
     */
    public function test_refresh_replay_logs_one_actual_connection_revocation(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $tokens = $this->issue_pair((int) $user->id);
        connection::authenticate_access_token($tokens['access_token']);
        $id = connection::current_connection_id();
        $rotated = connection::rotate_refresh_token($tokens['refresh_token'], 'client');
        $sink = $this->redirectEvents();
        $this->assertNull(connection::rotate_refresh_token($tokens['refresh_token'], 'client'));
        $this->assertCount(1, $sink->get_events());
        $this->assert_connection_event($sink->get_events()[0], 'connection_revoked', (int) $id, (int) $user->id);
        $this->assertNull(connection::rotate_refresh_token($tokens['refresh_token'], 'client'));
        $this->assertCount(1, $sink->get_events());
        $this->assertNull(connection::authenticate_access_token($rotated['access_token']));
    }

    /**
     * Bulk revocation keeps its token count and logs each changed connection once.
     */
    public function test_bulk_revocation_logs_only_the_connections_it_revokes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $one = $this->issue_pair((int) $first->id);
        $two = $this->issue_pair((int) $second->id);
        $sink = $this->redirectEvents();
        $this->assertSame(2, connection::revoke_all_tokens());
        $events = $sink->get_events();
        $this->assertCount(2, $events);
        $owners = array_map(static fn($event) => $event->relateduserid, $events);
        sort($owners);
        $this->assertSame([(int) $first->id, (int) $second->id], $owners);
        foreach ($events as $event) {
            $this->assert_connection_event($event, 'connection_revoked', (int) $event->objectid, (int) $event->relateduserid);
        }
        $this->assertSame(0, connection::revoke_all_tokens());
        $this->assertCount(2, $sink->get_events());
        $this->assertNull(connection::authenticate_access_token($one['access_token']));
        $this->assertNull(connection::authenticate_access_token($two['access_token']));
    }

    /**
     * Create a real consent code and exchange it through the connection interface.
     *
     * @param int $userid Consenting owner.
     * @return mixed[] Issued token pair.
     */
    private function issue_pair(int $userid): array {
        $code = connection::issue_code(
            'client',
            $userid,
            'https://client.example/cb',
            'ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0'
        );
        $tokens = connection::exchange_code($code, 'client', 'https://client.example/cb', 'abc');
        $this->assertNotNull($tokens);
        return $tokens;
    }

    /**
     * Assert security-log identity and the native event's display/restore contract.
     *
     * @param \core\event\base $event Captured event.
     * @param string $name Event class suffix.
     * @param int $id Stable connection id.
     * @param int $owner Connection owner.
     */
    private function assert_connection_event(\core\event\base $event, string $name, int $id, int $owner): void {
        $this->assertSame('\\local_coursepilot\\event\\' . $name, $event->eventname);
        $this->assertSame($id, (int) $event->objectid);
        $this->assertSame($owner, (int) $event->relateduserid);
        $this->assertSame(\context_system::instance()->id, $event->contextid);
        $this->assertSame('local_coursepilot_oauth_grant', $event->objecttable);
        $this->assertSame(get_string('event_' . $name, 'local_coursepilot'), $event::get_name());
        $this->assertStringContainsString((string) $id, $event->get_description());
        $this->assertSame(\core\event\base::NOT_MAPPED, $event::get_objectid_mapping());
    }
}
