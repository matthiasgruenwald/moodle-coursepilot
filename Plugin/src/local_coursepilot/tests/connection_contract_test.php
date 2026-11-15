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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact stored values, transaction hygiene and lookup scoping of the connection lifecycle (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection::class)]
final class connection_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Runs a call while every update of the grant table fails inside the database.
     *
     * @param callable $call The call under test.
     * @return \Throwable|null What the call threw.
     */
    private function caught_with_failing_grant_updates(callable $call): ?\Throwable {
        global $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL failure trigger fixture required.');
        }
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $key = 'cp674c' . bin2hex(random_bytes(8));
        $ddl->query("CREATE TRIGGER $key BEFORE UPDATE ON " . $DB->get_prefix() . 'local_coursepilot_oauth_grant
            FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Synthetic grant failure\'');
        try {
            return $this->caught($call);
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback(); // Clean up the deliberately broken red fixture.
            }
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }
    }


    /**
     * Runs a call while updates of the authorization code table change nothing, as if another claimant had won.
     *
     * @param callable $call The call under test.
     * @return mixed What the call returned.
     */
    private function with_ineffective_code_updates(callable $call): mixed {
        global $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL trigger fixture required.');
        }
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $key = 'cp674d' . bin2hex(random_bytes(8));
        $ddl->query("CREATE TRIGGER $key BEFORE UPDATE ON " . $DB->get_prefix() . 'local_coursepilot_oauth_code
            FOR EACH ROW SET NEW.code = OLD.code, NEW.used = OLD.used');
        try {
            return $call();
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }
    }

    /**
     * Inserts a grant with one token and returns both rows' IDs.
     *
     * @param int $userid Owner.
     * @param int $revoked Grant state.
     * @param string $statehash Grant state hash.
     * @return array{grant: int, token: int}
     */
    private function connection_row(int $userid, int $revoked = 0, string $statehash = 'seed'): array {
        global $DB;
        $grant = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $userid, 'clientid' => 'contract-client', 'revoked' => $revoked,
            'statehash' => $statehash, 'timecreated' => time(),
        ]);
        $token = $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'connectionid' => $grant, 'accesstokenhash' => bin2hex(random_bytes(32)),
            'refreshtokenhash' => bin2hex(random_bytes(32)), 'clientid' => 'contract-client', 'userid' => $userid,
            'expires' => time() + 600, 'refreshexpires' => time() + 6000, 'revoked' => $revoked, 'timecreated' => time(),
        ]);
        return ['grant' => $grant, 'token' => $token];
    }

    public function test_authorization_codes_are_sixty_four_hex_characters(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $code = connection::issue_code('client', (int) $user->id, 'https://example.org/cb', 'challenge');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $code);
    }

    public function test_a_code_is_still_valid_in_the_second_it_expires(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = connection::issue_code('client', (int) $user->id, 'https://example.org/cb', $challenge);
        // Start early in a wall-clock second so the whole exchange stays inside it.
        usleep((int) max(0, (1.0 - (microtime(true) - floor(microtime(true)))) * 1000000) + 20000);
        $now = time();
        $DB->set_field('local_coursepilot_oauth_code', 'expires', $now, ['code' => $code]);

        $tokens = connection::exchange_code($code, 'client', 'https://example.org/cb', $verifier);

        $this->assertSame($now, time(), 'The exchange must run inside one second.');
        $this->assertIsArray($tokens);
    }

    public function test_a_non_post_token_request_names_the_allowed_method(): void {
        $response = connection::handle_token('GET', []);

        $this->assertSame(405, $response['status']);
        $this->assertSame(['Allow' => 'POST'], $response['headers']);
        $this->assertSame(['error' => 'invalid_request'], $response['body']);
    }

    public function test_a_legacy_ticket_token_is_judged_by_its_own_connection(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $revoked = $this->connection_row((int) $user->id, 1);
        $active = $this->connection_row((int) $user->id, 0);
        $this->getDataGenerator()->create_user();

        $this->assertSame(
            'workbenchticketconnectionrevoked',
            connection::access_error((int) $user->id, null, $revoked['token'])
        );
        $this->assertNotSame(
            'workbenchticketconnectionrevoked',
            connection::access_error((int) $user->id, null, $active['token'])
        );
    }

    public function test_an_unknown_legacy_ticket_token_is_refused_without_warnings(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->connection_row((int) $user->id);
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $result = connection::access_error((int) $user->id, null, 987654);
        } finally {
            restore_error_handler();
        }

        $this->assertSame('workbenchticketconnectionrevoked', $result);
        $this->assertSame([], $warnings);
    }

    public function test_a_legacy_token_of_another_owner_does_not_count(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $row = $this->connection_row((int) $owner->id);

        $this->assertSame(
            'workbenchticketconnectionrevoked',
            connection::access_error((int) $other->id, null, $row['token'])
        );
    }

    public function test_without_a_known_connection_only_the_users_own_active_grant_counts(): void {
        $this->resetAfterTest();
        $withgrant = $this->getDataGenerator()->create_user();
        $revokedonly = $this->getDataGenerator()->create_user();
        $without = $this->getDataGenerator()->create_user();
        $this->connection_row((int) $withgrant->id);
        $this->connection_row((int) $revokedonly->id, 1);

        $this->assertSame('workbenchticketconnectionrevoked', connection::access_error((int) $without->id));
        $this->assertSame('workbenchticketconnectionrevoked', connection::access_error((int) $revokedonly->id));
        $this->assertNotSame('workbenchticketconnectionrevoked', connection::access_error((int) $withgrant->id));
    }

    public function test_mass_revocation_stores_exact_state_commits_and_leaves_revoked_grants_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $active = $this->connection_row((int) $user->id);
        $old = $this->connection_row((int) $user->id, 1, 'oldmarker');

        $count = connection::revoke_all_tokens();

        $this->assertSame(1, $count);
        $this->assertFalse($DB->is_transaction_started(), 'The revocation transaction must be committed.');
        $grant = $DB->get_record('local_coursepilot_oauth_grant', ['id' => $active['grant']], '*', MUST_EXIST);
        $this->assertSame('1', $grant->revoked);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $grant->statehash);
        $this->assertSame('1', $DB->get_field('local_coursepilot_oauth_token', 'revoked', ['id' => $active['token']]));
        $this->assertSame('oldmarker', $DB->get_field('local_coursepilot_oauth_grant', 'statehash', ['id' => $old['grant']]));
    }

    public function test_a_failing_mass_revocation_is_rolled_back_and_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $row = $this->connection_row((int) $user->id);
        $e = $this->caught_with_failing_grant_updates(static fn() => connection::revoke_all_tokens());

        $this->assertInstanceOf(\dml_exception::class, $e);
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame('0', $DB->get_field('local_coursepilot_oauth_grant', 'revoked', ['id' => $row['grant']]));
        $this->assertSame('0', $DB->get_field('local_coursepilot_oauth_token', 'revoked', ['id' => $row['token']]));
    }

    public function test_revoking_a_token_stores_exact_flags_events_and_commits(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $row = $this->connection_row((int) $user->id);
        $this->connection_row((int) $user->id);
        $sink = $this->redirectEvents();

        $this->assertTrue(connection::revoke_token($row['token']));

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame('1', $DB->get_field('local_coursepilot_oauth_grant', 'revoked', ['id' => $row['grant']]));
        $this->assertSame('1', $DB->get_field('local_coursepilot_oauth_token', 'revoked', ['id' => $row['token']]));
        $events = array_values(array_filter(
            $sink->get_events(),
            static fn($e) => $e instanceof event\connection_revoked
        ));
        $this->assertCount(1, $events);
        $this->assertSame($row['grant'], $events[0]->objectid);
        $this->assertSame((int) $user->id, $events[0]->relateduserid);
    }

    public function test_an_unknown_token_id_is_refused_even_when_other_tokens_exist(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $row = $this->connection_row((int) $user->id);
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $result = connection::revoke_token($row['token'] + 1000);
        } finally {
            restore_error_handler();
        }

        $this->assertFalse($result);
        $this->assertSame([], $warnings);
        $this->assertSame('0', $DB->get_field('local_coursepilot_oauth_grant', 'revoked', ['id' => $row['grant']]));
    }

    public function test_revoking_an_already_revoked_connection_commits_and_reports_false(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $row = $this->connection_row((int) $user->id, 1);

        $this->assertFalse(connection::revoke_token($row['token']));
        $this->assertFalse($DB->is_transaction_started());
    }

    public function test_a_failing_token_revocation_is_rolled_back_and_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $row = $this->connection_row((int) $user->id);
        $e = $this->caught_with_failing_grant_updates(static fn() => connection::revoke_token($row['token']));

        $this->assertInstanceOf(\dml_exception::class, $e);
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame('0', $DB->get_field('local_coursepilot_oauth_grant', 'revoked', ['id' => $row['grant']]));
    }

    public function test_the_admin_overview_carries_the_user_name_fields_and_email(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'Lovelace']);
        $this->connection_row((int) $user->id);

        $rows = array_values(connection::active_tokens());

        $this->assertCount(1, $rows);
        $this->assertSame('Ada', $rows[0]->firstname);
        $this->assertSame('Lovelace', $rows[0]->lastname);
        $this->assertSame($user->email, $rows[0]->email);
        $this->assertSame('Ada Lovelace', fullname($rows[0]));
    }

    public function test_a_lost_code_claim_is_refused_and_leaves_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = connection::issue_code('client', (int) $user->id, 'https://example.org/cb', $challenge);

        $tokens = $this->with_ineffective_code_updates(
            fn() => connection::exchange_code($code, 'client', 'https://example.org/cb', $verifier)
        );

        $this->assertNull($tokens);
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_token'));
    }

    public function test_refreshing_with_a_replayed_token_revokes_the_connection_and_leaves_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $user = $this->getDataGenerator()->create_user();
        $row = $this->connection_row((int) $user->id);
        $refresh = bin2hex(random_bytes(32));
        $DB->set_field('local_coursepilot_oauth_token', 'refreshtokenhash', hash('sha256', $refresh), ['id' => $row['token']]);
        $DB->set_field('local_coursepilot_oauth_token', 'revoked', 1, ['id' => $row['token']]);

        $this->assertNull(connection::rotate_refresh_token($refresh, 'contract-client'));

        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame('1', $DB->get_field('local_coursepilot_oauth_grant', 'revoked', ['id' => $row['grant']]));
    }

    public function test_an_array_as_refresh_token_is_an_invalid_grant_and_no_fatal_error(): void {
        $this->resetAfterTest();
        $registered = oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Testclient',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]), '192.0.2.1');
        $messages = [];
        set_error_handler(static function (int $errno, string $message) use (&$messages): bool {
            $messages[] = $message;
            return true;
        });
        try {
            $response = $this->succeeded(fn() => connection::handle_token('POST', [
                'client_id' => $registered['body']['client_id'], 'grant_type' => 'refresh_token', 'refresh_token' => ['x'],
            ]));
        } finally {
            restore_error_handler();
        }

        $this->assertSame(400, $response['status']);
        $this->assertSame(['error' => 'invalid_grant'], $response['body']);
    }
}
