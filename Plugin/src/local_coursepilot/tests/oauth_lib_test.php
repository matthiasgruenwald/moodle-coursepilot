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
#[CoversClass(client_registry::class)]
final class oauth_lib_test extends \advanced_testcase {
    /**
     * Wwwroot.
     */
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

        // Note: get_client() subsequently finds the cached row directly in the database.
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
        $this->assertSame(
            'response_type=code, client_id, redirect_uri and code_challenge are required.',
            $result['error_description']
        );
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
     * The key endpoint returns a valid, empty JWKS (#336).
     */
    public function test_jwks_document_is_valid_empty_keyset(): void {
        $document = oauth_lib::jwks_document();

        $this->assertSame(['keys' => []], $document);
        $this->assertIsString(json_encode($document));
    }
}
