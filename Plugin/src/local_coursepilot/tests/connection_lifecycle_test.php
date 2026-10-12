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
 * Connection lifecycle through the real public interface and database.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection::class)]
#[CoversClass(\local_coursepilot\storage_anchor::class)]
final class connection_lifecycle_test extends \advanced_testcase {
    /**
     * Register a test client and return it with a PKCE verifier/challenge pair.
     * Shared setup for authorization/token tests below.
     *
     * @return array{clientid: string, redirecturi: string, verifier: string, challenge: string}
     */
    private function registered_client_with_pkce(): array {
        $response = oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Testclient',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]), '192.0.2.1');
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return [
            'clientid' => $response['body']['client_id'],
            'redirecturi' => 'https://claude.ai/api/mcp/auth_callback',
            'verifier' => $verifier,
            'challenge' => $challenge,
        ];
    }

    /**
     * Complete round-trip: issue and redeem a code with the correct verifier.
     * Return a one-hour access token and a refresh token.
     */
    public function test_exchange_code_returns_tokens_with_correct_ttls(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $tokens = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $this->assertNotNull($tokens);
        $this->assertNotEmpty($tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(connection::ACCESS_TOKEN_TTL, $tokens['expires_in']);
        $this->assertSame(3600, $tokens['expires_in']);

        global $DB;
        $stored = $DB->get_record('local_coursepilot_oauth_token', ['clientid' => $fixture['clientid']], '*', MUST_EXIST);
        $this->assertSame(hash('sha256', $tokens['access_token']), $stored->accesstokenhash);
        $this->assertSame(hash('sha256', $tokens['refresh_token']), $stored->refreshtokenhash);
        $this->assertNotSame($tokens['access_token'], $stored->accesstokenhash);
        $this->assertNotSame($tokens['refresh_token'], $stored->refreshtokenhash);
    }

    /**
     * Redeem an authorization code exactly once. A second redemption fails.
     */
    public function test_exchange_code_can_only_be_used_once(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $first = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        $second = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $this->assertNotNull($first);
        $this->assertNull($second);
    }

    /**
     * An expired code fails regardless of used and remains untouched.
     * Do not claim a row that is already invalid.
     */
    public function test_exchange_code_rejects_expired_code(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        $this->setUser($this->getDataGenerator()->create_user());
        global $USER;
        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $DB->set_field('local_coursepilot_oauth_code', 'expires', time() - 1, ['code' => $code]);

        $result = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $this->assertNull($result);
        $this->assertSame(0, (int) $DB->get_field('local_coursepilot_oauth_code', 'used', ['code' => $code]));
    }

    /**
     * Two real database connections redeem the same code through the public lifecycle interface.
     */
    public function test_exchange_code_at_most_one_of_two_separate_connections_wins_the_claim(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $fixture = $this->registered_client_with_pkce();
        $this->setUser($this->getDataGenerator()->create_user());
        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $originaldb = $DB;
        $cfg = $DB->export_dbconfig();
        $db2 = \moodle_database::get_driver_instance($cfg->dbtype, $cfg->dblibrary);
        $db2->connect($cfg->dbhost, $cfg->dbuser, $cfg->dbpass, $cfg->dbname, $cfg->prefix, (array) ($cfg->dboptions ?? []));
        try {
            $first = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
            $DB = $db2;
            $second = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
            $this->assertNotNull($first);
            $this->assertNull($second);
            $this->assertSame((int) $USER->id, connection::authenticate_access_token($first['access_token']));
        } finally {
            $DB = $originaldb;
            $db2->dispose();
        }
    }

    /**
     * A wrong PKCE verifier fails without consuming the code.
     */
    public function test_exchange_code_rejects_wrong_code_verifier(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $result = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], 'falscher-verifier');

        $this->assertNull($result);
    }

    /**
     * A redirect_uri mismatch during redemption fails (RFC 6749 §4.1.3).
     */
    public function test_exchange_code_rejects_redirect_uri_mismatch(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $result = connection::exchange_code($code, $fixture['clientid'], 'https://andere.example/callback', $fixture['verifier']);

        $this->assertNull($result);
    }

    /**
     * Refresh rotation issues a new token and invalidates the old one.
     * A second redemption of the old token fails.
     */
    public function test_rotate_refresh_token_issues_new_pair_and_revokes_old(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $original = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $rotated = connection::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);
        $this->assertNotNull($rotated);
        $this->assertNotSame($original['refresh_token'], $rotated['refresh_token']);
        $this->assertNotSame($original['access_token'], $rotated['access_token']);
        $this->assertSame(connection::REFRESH_TOKEN_TTL, 30 * 24 * 3600);

        // The old refresh token is invalid.
        $reuse = connection::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);
        $this->assertNull($reuse);
    }

    /**
     * An expired refresh token fails regardless of revoked and remains untouched.
     */
    public function test_rotate_refresh_token_rejects_expired_token(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());
        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $original = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        $hash = hash('sha256', $original['refresh_token']);
        $DB->set_field('local_coursepilot_oauth_token', 'refreshexpires', time() - 1, ['refreshtokenhash' => $hash]);

        $result = connection::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);

        $this->assertNull($result);
        $this->assertSame(0, (int) $DB->get_field('local_coursepilot_oauth_token', 'revoked', ['refreshtokenhash' => $hash]));
    }

    /**
     * Claim and token issuance share a database transaction. A forced issuance error rolls back the claim rather than
     * permanently consuming the code without delivering a token pair. Add a
     * temporary NOT NULL token-table column without a default: issue_token_pair()
     * does not supply it, so the insert reliably fails. finally removes the
     * column regardless of the test outcome.
     */
    public function test_exchange_code_rolls_back_the_claim_when_token_issuance_fails(): void {
        global $DB;

        $this->resetAfterTest();
        // Exercise the endpoint transaction outside PHPUnit's enclosing PostgreSQL transaction.
        $this->preventResetByRollback();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());
        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursepilot_oauth_token');
        $field = new \xmldb_field('forcedfailure574', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');
        $dbman->add_field($table, $field);
        $sink = $this->redirectEvents();

        try {
            $threw = false;
            try {
                connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
            } catch (\dml_exception $e) {
                $threw = true;
            }

            $this->assertTrue($threw, 'issue_token_pair() must fail because the required column is missing.');
            $stored = $DB->get_record('local_coursepilot_oauth_code', ['code' => $code]);
            $this->assertNotFalse($stored, 'Rollback must restore the original code.');
            $this->assertSame(0, (int) $stored->used, 'A rolled-back claim must not leave the code consumed.');
            $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_token'), 'No partially issued token pair may remain.');
            $this->assertCount(0, $sink->get_events(), 'A failed exchange must not log a granted connection.');

            // After rollback, the code can be redeemed normally once the forced
            // failure is removed (drop the column in finally below).
        } finally {
            $dbman->drop_field($table, $field);
        }

        $tokens = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        $this->assertNotNull($tokens, 'After removing the failure, the rolled-back code must be redeemable.');
    }

    /**
     * A different client cannot redeem another client's refresh token.
     */
    public function test_rotate_refresh_token_rejects_client_mismatch(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $tokens = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $result = connection::rotate_refresh_token($tokens['refresh_token'], 'ein-anderer-client');

        $this->assertNull($result);
    }

    /**
     * An unknown refresh token fails without throwing.
     */
    public function test_rotate_refresh_token_rejects_unknown_token(): void {
        $this->resetAfterTest();

        $this->assertNull(connection::rotate_refresh_token('nie-ausgestellt', 'irgendein-client'));
    }

    /**
     * handle_token() owns oauth/token.php's decision logic. Exercise a complete
     * authorization-code round-trip without a running web server.
     */
    public function test_handle_token_exchanges_authorization_code(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());
        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $response = connection::handle_token('POST', [
            'grant_type' => 'authorization_code',
            'client_id' => $fixture['clientid'],
            'code' => $code,
            'redirect_uri' => $fixture['redirecturi'],
            'code_verifier' => $fixture['verifier'],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertNotEmpty($response['body']['access_token']);
        $this->assertSame('no-store', $response['headers']['Cache-Control']);
    }

    /**
     * Only POST is allowed.
     */
    public function test_handle_token_rejects_non_post_method(): void {
        $response = connection::handle_token('GET', []);

        $this->assertSame(405, $response['status']);
        $this->assertSame('invalid_request', $response['body']['error']);
    }

    /**
     * A null body from a parse failure returns an error without a PHP warning.
     */
    public function test_handle_token_rejects_missing_body(): void {
        $response = connection::handle_token('POST', null);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_request', $response['body']['error']);
    }

    /**
     * An unknown client returns invalid_client, not invalid_grant.
     */
    public function test_handle_token_rejects_unknown_client(): void {
        $this->resetAfterTest();

        $response = connection::handle_token('POST', [
            'grant_type' => 'authorization_code',
            'client_id' => 'unknown',
            'code' => 'x',
            'redirect_uri' => 'https://x.example/cb',
            'code_verifier' => 'x',
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_client', $response['body']['error']);
    }

    /**
     * An unknown grant_type returns unsupported_grant_type.
     */
    public function test_handle_token_rejects_unsupported_grant_type(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();

        $response = connection::handle_token('POST', [
            'grant_type' => 'client_credentials',
            'client_id' => $fixture['clientid'],
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('unsupported_grant_type', $response['body']['error']);
    }

    /**
     * handle_token() also rotates refresh tokens (RFC 6749: grant_type=refresh_token).
     */
    public function test_handle_token_rotates_refresh_token(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());
        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $original = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $response = connection::handle_token('POST', [
            'grant_type' => 'refresh_token',
            'client_id' => $fixture['clientid'],
            'refresh_token' => $original['refresh_token'],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertNotSame($original['refresh_token'], $response['body']['refresh_token']);
    }

    /**
     * Mass revocation invalidates all active tokens across users/clients and
     * returns their count.
     */
    public function test_revoke_all_tokens_invalidates_every_active_token(): void {
        $this->resetAfterTest();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $tokena = $this->issue_token((int) $usera->id);
        $tokenb = $this->issue_token((int) $userb->id, 'other-client');

        $count = connection::revoke_all_tokens();

        $this->assertSame(2, $count);
        $this->assertNull(connection::authenticate_access_token($tokena->accesstoken));
        $this->assertNull(connection::authenticate_access_token($tokenb->accesstoken));
    }

    /**
     * A second mass revocation changes nothing and returns zero without error.
     */
    public function test_revoke_all_tokens_is_idempotent(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->issue_token((int) $user->id);

        connection::revoke_all_tokens();
        $second = connection::revoke_all_tokens();

        $this->assertSame(0, $second);
    }

    /**
     * Admin revocation without an owner filter revokes any user's token.
     */
    public function test_revoke_token_without_owner_filter_revokes_any_token(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_token((int) $user->id);

        $result = connection::revoke_token($token->id);

        $this->assertTrue($result);
        $this->assertNull(connection::authenticate_access_token($token->accesstoken));
    }

    /**
     * Self-management with a matching owner can revoke its token. Subsequent
     * access fails.
     */
    public function test_revoke_token_with_matching_owner_succeeds(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_token((int) $user->id);

        $result = connection::revoke_token($token->id, (int) $user->id);

        $this->assertTrue($result);
        $this->assertNull(connection::authenticate_access_token($token->accesstoken));
    }

    /**
     * An owner filter rejects foreign tokens. A user cannot revoke another
     * user's connection; it stays valid.
     */
    public function test_revoke_token_rejects_foreign_token_when_owner_filter_set(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $token = $this->issue_token((int) $owner->id);

        $result = connection::revoke_token($token->id, (int) $stranger->id);

        $this->assertFalse($result);
        $this->assertSame((int) $owner->id, connection::authenticate_access_token($token->accesstoken));
    }

    /**
     * An unknown token ID returns false without throwing.
     */
    public function test_revoke_token_returns_false_for_unknown_id(): void {
        $this->resetAfterTest();

        $this->assertFalse(connection::revoke_token(999999));
    }

    /**
     * Self-management returns only the user's own active connections.
     */
    public function test_active_tokens_for_user_never_returns_foreign_tokens(): void {
        $this->resetAfterTest();
        $persona = $this->getDataGenerator()->create_user();
        $personb = $this->getDataGenerator()->create_user();
        $this->issue_token((int) $persona->id);
        $this->issue_token((int) $personb->id);

        $tokens = connection::active_tokens_for_user((int) $persona->id);

        $this->assertCount(1, $tokens);
        foreach ($tokens as $token) {
            $this->assertSame((int) $persona->id, (int) $token->userid);
        }
    }

    /**
     * Revoked tokens disappear from connection self-management.
     */
    public function test_active_tokens_for_user_excludes_revoked_tokens(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_token((int) $user->id);
        connection::revoke_token($token->id);

        $tokens = connection::active_tokens_for_user((int) $user->id);

        $this->assertCount(0, $tokens);
    }

    /**
     * Admin overview returns all users' active connections with display data.
     */
    public function test_active_tokens_returns_connections_across_all_users(): void {
        $this->resetAfterTest();
        $persona = $this->getDataGenerator()->create_user();
        $personb = $this->getDataGenerator()->create_user();
        $this->issue_token((int) $persona->id);
        $this->issue_token((int) $personb->id);

        $tokens = connection::active_tokens();

        $this->assertCount(2, $tokens);
        $userids = array_map(static fn($token) => (int) $token->userid, $tokens);
        $this->assertContains((int) $persona->id, $userids);
        $this->assertContains((int) $personb->id, $userids);
    }

    /**
     * Storage location belongs to the anchor's pointer file (storage_anchor),
     * not a token. Rotation, a second client's connection and mass revocation
     * change neither storage location.
     */
    public function test_storage_location_survives_rotation_second_client_and_mass_revocation(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        global $USER;

        storage_anchor::write_pointer_document([
            'context_area' => 'mein-ort',
            'materialordner' => 'mein-material',
        ]);
        $assertlocationunchanged = function (): void {
            $this->assertSame('/mein-ort/', context_files::resolve_directory(''));
            $this->assertSame('/mein-material/', storage_anchor::resolve_pointer_location(material_files::area())->path);
        };
        $assertlocationunchanged();

        // Token rotation.
        $fixture = $this->registered_client_with_pkce();
        $code = connection::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $tokens = connection::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        connection::rotate_refresh_token($tokens['refresh_token'], $fixture['clientid']);
        $assertlocationunchanged();

        // A second connection for another client.
        $this->issue_token((int) $USER->id, 'ein-zweiter-client');
        $assertlocationunchanged();

        // Mass revocation of all connections.
        connection::revoke_all_tokens();
        $assertlocationunchanged();
    }

    /**
     * Persist a token row directly: the simplest token fixture without a full
     * DCR/PKCE round-trip, as in dispatcher_test::issue_access_token().
     *
     * @param int $userid
     * @param string $clientid
     * @return \stdClass Complete token row including its ID.
     */
    private function issue_token(int $userid, string $clientid = 'test-client'): \stdClass {
        global $DB;

        $accesstoken = bin2hex(random_bytes(32));
        $refreshtoken = bin2hex(random_bytes(32));
        $record = new \stdClass();
        $record->accesstokenhash = hash('sha256', $accesstoken);
        $record->refreshtokenhash = hash('sha256', $refreshtoken);
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->expires = time() + connection::ACCESS_TOKEN_TTL;
        $record->refreshexpires = time() + connection::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = time();
        $record->connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $record->userid, 'clientid' => $record->clientid, 'revoked' => $record->revoked,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => $record->timecreated,
        ]);
        $record->id = $DB->insert_record('local_coursepilot_oauth_token', $record);
        $record->accesstoken = $accesstoken;
        $record->refreshtoken = $refreshtoken;
        return $record;
    }
}
