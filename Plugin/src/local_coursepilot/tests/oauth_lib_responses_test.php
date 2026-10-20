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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The exact documents and HTTP answers of the OAuth discovery, registration and redirect helpers
 * (RFC 8414, 9728, 7591): what an MCP client reads is the contract.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(oauth_lib::class)]
final class oauth_lib_responses_test extends \advanced_testcase {
    /** @var string Issuer root. */
    private const ROOT = 'https://cp.example';

    /**
     * The authorization server metadata lists every endpoint and capability, exactly.
     */
    public function test_authorization_server_metadata_is_exact(): void {
        $this->assertSame([
            'issuer' => 'https://cp.example/local/coursepilot/oauth.php',
            'authorization_endpoint' => 'https://cp.example/local/coursepilot/oauth/authorize.php',
            'token_endpoint' => 'https://cp.example/local/coursepilot/oauth/token.php',
            'registration_endpoint' => 'https://cp.example/local/coursepilot/oauth/register.php',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'scopes_supported' => ['coursepilot.read'],
            'jwks_uri' => 'https://cp.example/local/coursepilot/oauth/jwks.php',
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ], oauth_lib::authorization_server_metadata(self::ROOT));
    }

    /**
     * The protected resource metadata points at the MCP endpoint and its authorization server, exactly.
     */
    public function test_protected_resource_metadata_is_exact(): void {
        $this->assertSame([
            'resource' => 'https://cp.example/local/coursepilot/mcp.php',
            'authorization_servers' => ['https://cp.example/local/coursepilot/oauth.php'],
            'scopes_supported' => ['coursepilot.read'],
            'bearer_methods_supported' => ['header'],
        ], oauth_lib::protected_resource_metadata(self::ROOT));
    }

    /**
     * Discovery answers all three known names uncached, and anything else with an uncached-free JSON 404.
     *
     * @return array<string, array{string}>
     */
    public static function known_paths(): array {
        return [
            'empty' => [''],
            'openid' => ['.well-known/openid-configuration'],
            'oauth' => ['.well-known/oauth-authorization-server'],
        ];
    }

    /**
     * A known discovery path serves the metadata with a no-store header.
     *
     * @param string $path
     */
    #[DataProvider('known_paths')]
    public function test_known_discovery_paths_serve_metadata_without_caching(string $path): void {
        $this->assertSame([
            'status' => 200,
            'headers' => ['Cache-Control' => 'no-store'],
            'body' => oauth_lib::authorization_server_metadata(self::ROOT),
        ], oauth_lib::handle_discovery(self::ROOT, $path));
    }

    /**
     * An unknown path is a JSON 404 naming the path, without extra headers.
     */
    public function test_unknown_discovery_path_is_a_plain_json_404(): void {
        $this->assertSame(
            ['status' => 404, 'headers' => [], 'body' => ['error' => 'not_found', 'path_info' => 'x']],
            oauth_lib::handle_discovery(self::ROOT, 'x')
        );
    }

    /**
     * A wrong method is a 405 naming the allowed method.
     */
    public function test_registration_with_wrong_method_names_the_allowed_one(): void {
        $this->assertSame([
            'status' => 405,
            'headers' => ['Allow' => 'POST'],
            'body' => ['error' => 'invalid_request', 'error_description' => 'Only POST is allowed.'],
        ], oauth_lib::handle_registration('GET', '{}', '192.0.2.1'));
    }

    /**
     * A body of exactly the limit is read, one byte more is refused as too large.
     */
    public function test_registration_body_limit_is_inclusive(): void {
        $this->resetAfterTest();
        $prefix = '{"client_name":"';
        $suffix = '"}';
        $atlimit = $prefix . str_repeat('a', oauth_lib::REGISTRATION_MAX_BODY_BYTES - strlen($prefix . $suffix)) . $suffix;
        $over = $prefix . str_repeat('a', oauth_lib::REGISTRATION_MAX_BODY_BYTES + 1 - strlen($prefix . $suffix)) . $suffix;

        $this->assertSame(oauth_lib::REGISTRATION_MAX_BODY_BYTES, strlen($atlimit));
        $this->assertNotSame(413, oauth_lib::handle_registration('POST', $atlimit, '192.0.2.1')['status']);
        $this->assertSame([
            'status' => 413,
            'headers' => [],
            'body' => ['error' => 'invalid_client_metadata', 'error_description' => 'Registration request is too large.'],
        ], oauth_lib::handle_registration('POST', $over, '192.0.2.1'));
    }

    /**
     * The deepest structure that decodes under the sixteen-level limit is accepted, one level more is invalid JSON.
     */
    public function test_registration_json_depth_limit_is_enforced(): void {
        $this->resetAfterTest();
        $nested = static fn (int $depth): string => str_repeat('{"a":', $depth - 1) . '{}' . str_repeat('}', $depth - 1);

        $ok = oauth_lib::handle_registration('POST', $nested(15), '192.0.2.1');
        $deep = oauth_lib::handle_registration('POST', $nested(16), '192.0.2.1');

        $this->assertNotSame('Invalid JSON.', $ok['body']['error_description']);
        $this->assertSame([
            'status' => 400,
            'headers' => [],
            'body' => ['error' => 'invalid_client_metadata', 'error_description' => 'Invalid JSON.'],
        ], $deep);
    }

    /**
     * An exhausted budget answers 429 with the wait time as a string header; success is uncached with a 201.
     */
    public function test_registration_success_is_uncached_and_exhaustion_sends_retry_after_as_text(): void {
        $this->resetAfterTest();
        set_config('oauthregistersourcelimit', 1, 'local_coursepilot');
        $body = json_encode(['client_name' => 'Zeta', 'redirect_uris' => ['https://client.example/cb']]);

        $created = oauth_lib::handle_registration('POST', $body, '192.0.2.9');
        $refused = oauth_lib::handle_registration('POST', $body, '192.0.2.9');

        $this->assertSame(201, $created['status']);
        $this->assertSame(['Cache-Control' => 'no-store'], $created['headers']);
        $this->assertSame('Zeta', $created['body']['client_name']);
        $this->assertSame(429, $refused['status']);
        $this->assertSame(['Retry-After'], array_keys($refused['headers']));
        $this->assertSame((string) (int) $refused['headers']['Retry-After'], $refused['headers']['Retry-After']);
        $this->assertSame([
            'error' => 'temporarily_unavailable',
            'error_description' => 'Registration budget exhausted, retry later.',
        ], $refused['body']);
    }

    /**
     * Redirect parameters leave out null and empty values; with nothing left the URI stays as it is.
     */
    public function test_redirect_url_drops_empty_parameters(): void {
        $this->assertSame(
            'https://c.example/cb?code=abc&state=0',
            html_entity_decode(oauth_lib::build_redirect_url(
                'https://c.example/cb',
                ['code' => 'abc', 'error' => null, 'x' => '', 'state' => '0']
            ))
        );
        $this->assertSame(
            'https://c.example/cb?q=1&code=abc',
            oauth_lib::build_redirect_url('https://c.example/cb?q=1', ['code' => 'abc'])
        );
        $this->assertSame(
            'https://c.example/cb',
            oauth_lib::build_redirect_url('https://c.example/cb', ['error' => null, 'state' => ''])
        );
    }

    /**
     * The public registration helpers can be used directly: registering a client and describing a stored one.
     */
    public function test_registration_helpers_are_public_and_agree(): void {
        $this->resetAfterTest();

        $registered = oauth_lib::register_client(['client_name' => 'Direkt', 'redirect_uris' => ['https://client.example/cb']]);
        $client = oauth_lib::get_client($registered['client_id']);
        $described = oauth_lib::client_registration_response($client);

        $this->assertSame('Direkt', $registered['client_name']);
        $this->assertSame($registered, $described);
        $this->assertTrue(oauth_lib::redirect_uri_matches($client, 'https://client.example/cb'));
        $this->assertFalse(oauth_lib::redirect_uri_matches($client, 'https://client.example/other'));
    }
}
