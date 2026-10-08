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
 * OAuth discovery and DCR/CIMD registration (#335), following the handler
 * seam from #334: PHPUnit exercises handlers without a running web server.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(oauth_lib::class)]
#[CoversClass(\local_coursepilot\storage_anchor::class)]
final class oauth_lib_test extends \advanced_testcase {

    private const WWWROOT = 'https://coursepilot.example';

    /**
     * Both known discovery names return the same real metadata.
     * registration_endpoint identifies an implemented endpoint.
     */
    public function test_discovery_serves_both_wellknown_names(): void {
        $oidc = oauth_lib::handle_discovery(self::WWWROOT, '.well-known/openid-configuration');
        $oauth = oauth_lib::handle_discovery(self::WWWROOT, '.well-known/oauth-authorization-server');

        $this->assertSame(200, $oidc['status']);
        $this->assertSame(200, $oauth['status']);
        $this->assertSame($oidc['body'], $oauth['body']);
        $this->assertSame(
            self::WWWROOT . '/local/coursepilot/oauth/register.php',
            $oidc['body']['registration_endpoint']
        );
    }

    /**
     * Unknown PATH_INFO under oauth.php returns JSON 404 without a rewrite.
     */
    public function test_discovery_rejects_unknown_path(): void {
        $response = oauth_lib::handle_discovery(self::WWWROOT, 'nonsense');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['body']['error']);
    }

    /**
     * Protected-resource metadata is identical at both addresses.
     * oauth/protected-resource.php and dispatcher::handle() (PATH_INFO on mcp.php)
     * call the same source.
     */
    public function test_protected_resource_metadata_is_stable_across_both_addresses(): void {
        global $CFG;

        $this->resetAfterTest();

        $direct = oauth_lib::protected_resource_metadata($CFG->wwwroot);
        $viadispatcher = dispatcher::handle(
            null,
            null,
            ['origin' => null, 'pathinfo' => '.well-known/oauth-protected-resource', 'method' => 'POST']
        );

        $this->assertSame($direct['resource'], $viadispatcher['body']['resource']);
        $this->assertSame($direct['authorization_servers'], $viadispatcher['body']['authorization_servers']);
    }

    /**
     * Valid registration persists a client and returns its ID and metadata (RFC 7591).
     */
    public function test_register_client_creates_client_and_returns_metadata(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Testclient',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]), '192.0.2.1');

        $this->assertSame(201, $response['status']);
        $this->assertNotEmpty($response['body']['client_id']);
        $this->assertSame('Testclient', $response['body']['client_name']);
        $this->assertSame(['https://claude.ai/api/mcp/auth_callback'], $response['body']['redirect_uris']);

        $client = oauth_lib::get_client($response['body']['client_id']);
        $this->assertNotNull($client, 'Client must be persisted in the database.');
        $this->assertSame('dcr', $client->source);
    }

    /**
     * Reject redirects using neither HTTPS nor loopback.
     */
    public function test_register_client_rejects_disallowed_redirect_uri(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', json_encode([
            'redirect_uris' => ['http://evil.example/callback'],
        ]), '192.0.2.1');

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_redirect_uri', $response['body']['error']);
    }

    /**
     * Allow loopback redirects for native/CLI clients (RFC 8252).
     */
    public function test_register_client_allows_loopback_redirect_uri(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', json_encode([
            'redirect_uris' => ['http://127.0.0.1:51000/callback'],
        ]), '192.0.2.1');

        $this->assertSame(201, $response['status']);
    }

    /**
     * Missing redirect_uris returns a registration error rather than a PHP warning.
     */
    public function test_register_client_requires_redirect_uris(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', json_encode([]), '192.0.2.1');

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_client_metadata', $response['body']['error']);
    }

    /**
     * Only POST is allowed. Other methods return registration errors as JSON, not HTML.
     */
    public function test_registration_rejects_non_post_method(): void {
        $response = oauth_lib::handle_registration('GET', json_encode([]), '192.0.2.1');

        $this->assertSame(405, $response['status']);
        $this->assertIsArray($response['body']);
    }

    /**
     * Invalid registration JSON returns an error rather than a PHP exception.
     */
    public function test_registration_rejects_invalid_json(): void {
        $response = oauth_lib::handle_registration('POST', '{not json', '192.0.2.1');

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_client_metadata', $response['body']['error']);
    }

    /**
     * Every handler error is a JSON-serializable array rather than HTML.
     */
    public function test_error_responses_are_json_not_html(): void {
        $this->resetAfterTest();

        $responses = [
            oauth_lib::handle_discovery(self::WWWROOT, 'nonsense'),
            oauth_lib::handle_registration('GET', json_encode([]), '192.0.2.1'),
            oauth_lib::handle_registration('POST', '{not json', '192.0.2.1'),
            oauth_lib::handle_registration('POST', json_encode(['redirect_uris' => ['not a uri']]), '192.0.2.1'),
        ];

        foreach ($responses as $response) {
            $encoded = json_encode($response['body']);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('<html', strtolower($encoded));
        }
    }

    /**
     * CIMD fetches an HTTPS client_id without a local DCR row as metadata.
     * An unknown client_id that is not a URL returns null without throwing.
     */
    public function test_get_client_returns_null_for_unknown_non_url_clientid(): void {
        $this->resetAfterTest();

        $this->assertNull(oauth_lib::get_client('not-a-registered-client'));
    }

    /**
     * Valid decoded CIMD metadata persists a client whose ID is the URL.
     * This seam is testable without network access (#335); fetching stays in
     * fetch_and_cache_cimd_client(), the thin shell around validation.
     */
    public function test_cache_cimd_client_persists_valid_metadata(): void {
        $this->resetAfterTest();

        $url = 'https://client.example/cimd.json';
        $record = oauth_lib::cache_cimd_client($url, [
            'client_name' => 'CIMD-Testclient',
            'redirect_uris' => ['https://client.example/callback'],
        ]);

        $this->assertNotNull($record);
        $this->assertSame($url, $record->clientid);
        $this->assertSame('cimd', $record->source);
        $this->assertSame(['https://client.example/callback'], json_decode($record->redirecturis, true));

        // get_client() subsequently finds the cached row directly in the database.
        $found = oauth_lib::get_client($url);
        $this->assertNotNull($found);
        $this->assertSame($url, $found->clientid);
    }

    /**
     * CIMD rejects disallowed redirects just as DCR does.
     */
    public function test_cache_cimd_client_rejects_disallowed_redirect_uri(): void {
        $this->resetAfterTest();

        $record = oauth_lib::cache_cimd_client('https://client.example/cimd.json', [
            'redirect_uris' => ['http://evil.example/callback'],
        ]);

        $this->assertNull($record);
    }

    /**
     * CIMD metadata without redirect_uris is invalid.
     */
    public function test_cache_cimd_client_rejects_missing_redirect_uris(): void {
        $this->resetAfterTest();

        $this->assertNull(oauth_lib::cache_cimd_client('https://client.example/cimd.json', []));
    }

    /**
     * Exercise is_allowed_redirect_uri() boundary cases directly.
     */
    public function test_is_allowed_redirect_uri_boundary_cases(): void {
        $this->assertTrue(oauth_lib::is_allowed_redirect_uri('https://claude.ai/callback'));
        $this->assertTrue(oauth_lib::is_allowed_redirect_uri('http://localhost:8080/cb'));
        $this->assertTrue(oauth_lib::is_allowed_redirect_uri('http://127.0.0.1/cb'));
        $this->assertFalse(oauth_lib::is_allowed_redirect_uri('http://evil.example/cb'));
        $this->assertFalse(oauth_lib::is_allowed_redirect_uri('not-a-uri'));
        $this->assertFalse(oauth_lib::is_allowed_redirect_uri(''));
        $this->assertFalse(oauth_lib::is_allowed_redirect_uri(123));
    }

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
     * Valid authorization returns the client without an error field.
     */
    public function test_validate_authorize_request_accepts_valid_request(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();

        $result = oauth_lib::validate_authorize_request([
            'response_type' => 'code',
            'client_id' => $fixture['clientid'],
            'redirect_uri' => $fixture['redirecturi'],
            'code_challenge' => $fixture['challenge'],
            'code_challenge_method' => 'S256',
        ]);

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($fixture['clientid'], $result['client']->clientid);
    }

    /**
     * PKCE is mandatory: reject a missing code_challenge (#336).
     */
    public function test_validate_authorize_request_rejects_missing_pkce(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();

        $result = oauth_lib::validate_authorize_request([
            'response_type' => 'code',
            'client_id' => $fixture['clientid'],
            'redirect_uri' => $fixture['redirecturi'],
            'code_challenge' => '',
            'code_challenge_method' => 'S256',
        ]);

        $this->assertSame('invalid_request', $result['error']);
        $this->assertSame('response_type=code, client_id, redirect_uri and code_challenge are required.', $result['error_description']);
    }

    /**
     * Accept only S256. plain and other methods cause errors, not warnings (#336).
     */
    public function test_validate_authorize_request_rejects_plain_code_challenge_method(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();

        $result = oauth_lib::validate_authorize_request([
            'response_type' => 'code',
            'client_id' => $fixture['clientid'],
            'redirect_uri' => $fixture['redirecturi'],
            'code_challenge' => $fixture['challenge'],
            'code_challenge_method' => 'plain',
        ]);

        $this->assertSame('invalid_request', $result['error']);
        $this->assertSame('PKCE is required; only code_challenge_method=S256 is accepted.', $result['error_description']);
    }

    /**
     * Reject unregistered redirects even when the client and PKCE are valid (#336).
     */
    public function test_validate_authorize_request_rejects_unregistered_redirect_uri(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();

        $result = oauth_lib::validate_authorize_request([
            'response_type' => 'code',
            'client_id' => $fixture['clientid'],
            'redirect_uri' => 'https://not-registered.example/callback',
            'code_challenge' => $fixture['challenge'],
            'code_challenge_method' => 'S256',
        ]);

        $this->assertSame('invalid_request', $result['error']);
        $this->assertSame('redirect_uri is not registered for this client.', $result['error_description']);
    }

    /**
     * An unknown client uses invalid_client, distinct from invalid_request
     * used for other parameter validation.
     */
    public function test_validate_authorize_request_rejects_unknown_client(): void {
        $this->resetAfterTest();

        $result = oauth_lib::validate_authorize_request([
            'response_type' => 'code',
            'client_id' => 'unknown-client',
            'redirect_uri' => 'https://claude.ai/callback',
            'code_challenge' => 'abc',
            'code_challenge_method' => 'S256',
        ]);

        $this->assertSame('invalid_client', $result['error']);
        $this->assertSame('Unknown client.', $result['error_description']);
    }

    /**
     * Consent denial redirects with error=access_denied and state, without
     * an authorization code (#336).
     */
    public function test_denial_redirect_url_carries_access_denied_and_state(): void {
        $url = oauth_lib::denial_redirect_url('https://claude.ai/callback', 'xyz');

        $this->assertStringStartsWith('https://claude.ai/callback?', $url);
        $this->assertStringContainsString('error=access_denied', $url);
        $this->assertStringContainsString('state=xyz', $url);
        parse_str(parse_url(html_entity_decode($url), PHP_URL_QUERY), $query);
        $this->assertSame('The teacher denied consent.', $query['error_description']);
        $this->assertStringNotContainsString('code=', $url);
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

        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $tokens = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $this->assertNotNull($tokens);
        $this->assertNotEmpty($tokens['access_token']);
        $this->assertNotEmpty($tokens['refresh_token']);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertSame(oauth_lib::ACCESS_TOKEN_TTL, $tokens['expires_in']);
        $this->assertSame(3600, $tokens['expires_in']);

        global $DB;
        $stored = $DB->get_record('local_coursepilot_oauth_token', ['clientid' => $fixture['clientid']], '*', MUST_EXIST);
        $this->assertSame(hash('sha256', $tokens['access_token']), $stored->accesstokenhash);
        $this->assertSame(hash('sha256', $tokens['refresh_token']), $stored->refreshtokenhash);
        $this->assertNotSame($tokens['access_token'], $stored->accesstokenhash);
        $this->assertNotSame($tokens['refresh_token'], $stored->refreshtokenhash);
    }

    /**
     * Redeem an authorization code exactly once. A second redemption fails (#336).
     */
    public function test_exchange_code_can_only_be_used_once(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $first = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        $second = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

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
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $DB->set_field('local_coursepilot_oauth_code', 'expires', time() - 1, ['code' => $code]);

        $result = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $this->assertNull($result);
        $this->assertSame(0, (int) $DB->get_field('local_coursepilot_oauth_code', 'used', ['code' => $code]));
    }

    /**
     * Prove claim safety using two SEPARATE database connections (#574,
     * acceptance criterion 1), rather than sequential calls in one process.
     * Moodle's pattern for another connection to the same test database is
     * moodle_database::get_driver_instance() plus connect(); see core's
     * lib/dml/tests/dml_test.php.
     *
     * Both connections read used=0 before either writes, establishing the
     * contested initial state. Each then runs the same CAS statement used by
     * oauth_lib::claim_row(). The previous separate-read/unconditional-update
     * implementation would allow BOTH connections to succeed; the new used=0
     * WHERE condition makes the database reject the second competing claim.
     */
    public function test_exchange_code_at_most_one_of_two_separate_connections_wins_the_claim(): void {
        global $DB;

        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        $this->setUser($this->getDataGenerator()->create_user());
        global $USER;
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $cfg = $DB->export_dbconfig();
        $db2 = \moodle_database::get_driver_instance($cfg->dbtype, $cfg->dblibrary);
        $db2->connect($cfg->dbhost, $cfg->dbuser, $cfg->dbpass, $cfg->dbname, $cfg->prefix, (array) ($cfg->dboptions ?? []));

        try {
            // Both connections see the same unclaimed state.
            $this->assertSame(0, (int) $DB->get_field('local_coursepilot_oauth_code', 'used', ['code' => $code]));
            $this->assertSame(0, (int) $db2->get_field('local_coursepilot_oauth_code', 'used', ['code' => $code]));

            $claima = hash('sha256', $code . '|a');
            $claimb = hash('sha256', $code . '|b');
            $DB->execute(
                'UPDATE {local_coursepilot_oauth_code} SET code = :claim, used = 1 WHERE code = :code AND used = 0',
                ['claim' => $claima, 'code' => $code]
            );
            $db2->execute(
                'UPDATE {local_coursepilot_oauth_code} SET code = :claim, used = 1 WHERE code = :code AND used = 0',
                ['claim' => $claimb, 'code' => $code]
            );

            $wona = $DB->record_exists('local_coursepilot_oauth_code', ['code' => $claima]);
            $wonb = $DB->record_exists('local_coursepilot_oauth_code', ['code' => $claimb]);
            $this->assertNotEquals($wona, $wonb, 'Exactly one of the competing connections must win the claim.');
        } finally {
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

        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $result = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], 'falscher-verifier');

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

        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $result = oauth_lib::exchange_code($code, $fixture['clientid'], 'https://andere.example/callback', $fixture['verifier']);

        $this->assertNull($result);
    }

    /**
     * Refresh rotation issues a new token and invalidates the old one.
     * A second redemption of the old token fails (#336).
     */
    public function test_rotate_refresh_token_issues_new_pair_and_revokes_old(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());

        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $original = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $rotated = oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);
        $this->assertNotNull($rotated);
        $this->assertNotSame($original['refresh_token'], $rotated['refresh_token']);
        $this->assertNotSame($original['access_token'], $rotated['access_token']);
        $this->assertSame(oauth_lib::REFRESH_TOKEN_TTL, 30 * 24 * 3600);

        // The old refresh token is invalid.
        $reuse = oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);
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
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $original = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        $hash = hash('sha256', $original['refresh_token']);
        $DB->set_field('local_coursepilot_oauth_token', 'refreshexpires', time() - 1, ['refreshtokenhash' => $hash]);

        $result = oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);

        $this->assertNull($result);
        $this->assertSame(0, (int) $DB->get_field('local_coursepilot_oauth_token', 'revoked', ['refreshtokenhash' => $hash]));
    }

    /**
     * Claim and token issuance share a database transaction (#574, acceptance
     * criterion 3). A forced issuance error rolls back the claim rather than
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
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_coursepilot_oauth_token');
        $field = new \xmldb_field('forcedfailure574', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null, 'id');
        $dbman->add_field($table, $field);

        try {
            $threw = false;
            try {
                oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
            } catch (\dml_exception $e) {
                $threw = true;
            }

            $this->assertTrue($threw, 'issue_token_pair() must fail because the required column is missing.');
            $stored = $DB->get_record('local_coursepilot_oauth_code', ['code' => $code]);
            $this->assertNotFalse($stored, 'Rollback must restore the original code.');
            $this->assertSame(0, (int) $stored->used, 'A rolled-back claim must not leave the code consumed.');
            $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_token'), 'No partially issued token pair may remain.');

            // After rollback, the code can be redeemed normally once the forced
            // failure is removed (drop the column in finally below).
        } finally {
            $dbman->drop_field($table, $field);
        }

        $tokens = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
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

        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $tokens = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $result = oauth_lib::rotate_refresh_token($tokens['refresh_token'], 'ein-anderer-client');

        $this->assertNull($result);
    }

    /**
     * An unknown refresh token fails without throwing.
     */
    public function test_rotate_refresh_token_rejects_unknown_token(): void {
        $this->resetAfterTest();

        $this->assertNull(oauth_lib::rotate_refresh_token('nie-ausgestellt', 'irgendein-client'));
    }

    /**
     * The key endpoint returns a valid, empty JWKS (#336).
     */
    public function test_jwks_document_is_valid_empty_keyset(): void {
        $document = oauth_lib::jwks_document();

        $this->assertSame(['keys' => []], $document);
        $this->assertIsString(json_encode($document));
    }

    /**
     * handle_token() owns oauth/token.php's decision logic. Exercise a complete
     * authorization-code round-trip without a running web server (#336 review).
     */
    public function test_handle_token_exchanges_authorization_code(): void {
        $this->resetAfterTest();
        $fixture = $this->registered_client_with_pkce();
        global $USER;
        $this->setUser($this->getDataGenerator()->create_user());
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);

        $response = oauth_lib::handle_token('POST', [
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
        $response = oauth_lib::handle_token('GET', []);

        $this->assertSame(405, $response['status']);
        $this->assertSame('invalid_request', $response['body']['error']);
    }

    /**
     * A null body from a parse failure returns an error without a PHP warning.
     */
    public function test_handle_token_rejects_missing_body(): void {
        $response = oauth_lib::handle_token('POST', null);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_request', $response['body']['error']);
    }

    /**
     * An unknown client returns invalid_client, not invalid_grant.
     */
    public function test_handle_token_rejects_unknown_client(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_token('POST', [
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

        $response = oauth_lib::handle_token('POST', [
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
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $original = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);

        $response = oauth_lib::handle_token('POST', [
            'grant_type' => 'refresh_token',
            'client_id' => $fixture['clientid'],
            'refresh_token' => $original['refresh_token'],
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertNotSame($original['refresh_token'], $response['body']['refresh_token']);
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

        $accesstoken = oauth_lib::random_token(32);
        $refreshtoken = oauth_lib::random_token(32);
        $record = new \stdClass();
        $record->accesstokenhash = hash('sha256', $accesstoken);
        $record->refreshtokenhash = hash('sha256', $refreshtoken);
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->expires = time() + oauth_lib::ACCESS_TOKEN_TTL;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
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

    /**
     * Mass revocation invalidates all active tokens across users/clients and
     * returns their count (#338).
     */
    public function test_revoke_all_tokens_invalidates_every_active_token(): void {
        $this->resetAfterTest();
        $usera = $this->getDataGenerator()->create_user();
        $userb = $this->getDataGenerator()->create_user();
        $tokena = $this->issue_token((int) $usera->id);
        $tokenb = $this->issue_token((int) $userb->id, 'other-client');

        $count = oauth_lib::revoke_all_tokens();

        $this->assertSame(2, $count);
        $this->assertNull(oauth_lib::authenticate_access_token($tokena->accesstoken));
        $this->assertNull(oauth_lib::authenticate_access_token($tokenb->accesstoken));
    }

    /**
     * A second mass revocation changes nothing and returns zero without error.
     */
    public function test_revoke_all_tokens_is_idempotent(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->issue_token((int) $user->id);

        oauth_lib::revoke_all_tokens();
        $second = oauth_lib::revoke_all_tokens();

        $this->assertSame(0, $second);
    }

    /**
     * Admin revocation without an owner filter revokes any user's token.
     */
    public function test_revoke_token_without_owner_filter_revokes_any_token(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_token((int) $user->id);

        $result = oauth_lib::revoke_token($token->id);

        $this->assertTrue($result);
        $this->assertNull(oauth_lib::authenticate_access_token($token->accesstoken));
    }

    /**
     * Self-management with a matching owner can revoke its token. Subsequent
     * access fails (#338 acceptance criterion).
     */
    public function test_revoke_token_with_matching_owner_succeeds(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_token((int) $user->id);

        $result = oauth_lib::revoke_token($token->id, (int) $user->id);

        $this->assertTrue($result);
        $this->assertNull(oauth_lib::authenticate_access_token($token->accesstoken));
    }

    /**
     * An owner filter rejects foreign tokens. A user cannot revoke another
     * user's connection; it stays valid (#338 acceptance criterion).
     */
    public function test_revoke_token_rejects_foreign_token_when_owner_filter_set(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $token = $this->issue_token((int) $owner->id);

        $result = oauth_lib::revoke_token($token->id, (int) $stranger->id);

        $this->assertFalse($result);
        $this->assertSame((int) $owner->id, oauth_lib::authenticate_access_token($token->accesstoken));
    }

    /**
     * An unknown token ID returns false without throwing.
     */
    public function test_revoke_token_returns_false_for_unknown_id(): void {
        $this->resetAfterTest();

        $this->assertFalse(oauth_lib::revoke_token(999999));
    }

    /**
     * Self-management returns only the user's own active connections (#338).
     */
    public function test_active_tokens_for_user_never_returns_foreign_tokens(): void {
        $this->resetAfterTest();
        $persona = $this->getDataGenerator()->create_user();
        $personb = $this->getDataGenerator()->create_user();
        $this->issue_token((int) $persona->id);
        $this->issue_token((int) $personb->id);

        $tokens = oauth_lib::active_tokens_for_user((int) $persona->id);

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
        oauth_lib::revoke_token($token->id);

        $tokens = oauth_lib::active_tokens_for_user((int) $user->id);

        $this->assertCount(0, $tokens);
    }

    /**
     * Admin overview returns all users' active connections with display data (#338).
     */
    public function test_active_tokens_returns_connections_across_all_users(): void {
        $this->resetAfterTest();
        $persona = $this->getDataGenerator()->create_user();
        $personb = $this->getDataGenerator()->create_user();
        $this->issue_token((int) $persona->id);
        $this->issue_token((int) $personb->id);

        $tokens = oauth_lib::active_tokens();

        $this->assertCount(2, $tokens);
        $userids = array_map(static fn($token) => (int) $token->userid, $tokens);
        $this->assertContains((int) $persona->id, $userids);
        $this->assertContains((int) $personb->id, $userids);
    }

    /**
     * Storage location belongs to the anchor's pointer file (storage_anchor),
     * not a token. Rotation, a second client's connection and mass revocation
     * change neither storage location. Proven in #446 but lost when location
     * selection moved to its own page (#494); restore coverage for #509 using
     * the current storage_anchor::write_pointer_document() path.
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
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $tokens = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        oauth_lib::rotate_refresh_token($tokens['refresh_token'], $fixture['clientid']);
        $assertlocationunchanged();

        // A second connection for another client.
        $this->issue_token((int) $USER->id, 'ein-zweiter-client');
        $assertlocationunchanged();

        // Mass revocation of all connections.
        oauth_lib::revoke_all_tokens();
        $assertlocationunchanged();
    }

}
