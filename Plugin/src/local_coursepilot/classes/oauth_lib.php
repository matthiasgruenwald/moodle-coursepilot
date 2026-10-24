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

/**
 * OAuth discovery, registration HTTP handling and consent request validation.
 * Client registration, metadata lookup and redirect policy belong to client_registry.
 * Connection lifetime, token exchange and access decisions belong to connection.
 * HTTP handlers return responses without writing headers or ending the request.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class oauth_lib {
    /** @var int Maximum CIMD client_id URL length, the clientid column size. */
    public const CIMD_MAX_URI_LENGTH = client_registry::CIMD_MAX_URI_LENGTH;

    /** @var int Default first-time CIMD fetches per window for the whole site (setting oauthcimdsitelimit). */
    public const CIMD_SITE_LIMIT = client_registry::CIMD_SITE_LIMIT;

    /** @var int Default first-time CIMD fetches per window for one source (setting oauthcimdsourcelimit). */
    public const CIMD_SOURCE_LIMIT = client_registry::CIMD_SOURCE_LIMIT;

    /** @var int Default CIMD fetch budget window in seconds (setting oauthcimdwindow). */
    public const CIMD_WINDOW = client_registry::CIMD_WINDOW;

    /** @var int Fixed window of the CIMD negative cache: a failed URL is not fetched again until it ends. */
    public const CIMD_NEGATIVE_WINDOW = client_registry::CIMD_NEGATIVE_WINDOW;

    /** @var int Maximum raw DCR request body; larger requests are rejected before decoding. */
    public const REGISTRATION_MAX_BODY_BYTES = 16384;

    /** @var int Maximum length of one registered redirect URI. */
    public const REGISTRATION_MAX_URI_LENGTH = client_registry::REGISTRATION_MAX_URI_LENGTH;

    /** @var int Maximum number of redirect URIs per registration. */
    public const REGISTRATION_MAX_REDIRECT_URIS = client_registry::REGISTRATION_MAX_REDIRECT_URIS;

    /** @var int Default registrations per window for the whole site (setting oauthregistersitelimit). */
    public const REGISTRATION_SITE_LIMIT = 200;

    /** @var int Default registrations per window for one source (setting oauthregistersourcelimit). */
    public const REGISTRATION_SOURCE_LIMIT = 50;

    /** @var int Default registration budget window in seconds (setting oauthregisterwindow). */
    public const REGISTRATION_WINDOW = 3600;

    /**
     * Authorization server metadata (RFC 8414). A pure function of the issuer,
     * without global access, so it can be tested without bootstrapping Moodle.
     *
     * @param string $wwwroot
     * @return mixed[]
     */
    public static function authorization_server_metadata(string $wwwroot): array {
        return [
            // RFC 8414.
            'issuer' => $wwwroot . '/local/coursepilot/oauth.php',
            'authorization_endpoint' => $wwwroot . '/local/coursepilot/oauth/authorize.php',
            'token_endpoint' => $wwwroot . '/local/coursepilot/oauth/token.php',
            'registration_endpoint' => $wwwroot . '/local/coursepilot/oauth/register.php',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'scopes_supported' => ['coursepilot.read'],
            // OIDC-required fields imposed by the namespace even though this plugin
            // is not an OIDC provider (#302, item 2).
            'jwks_uri' => $wwwroot . '/local/coursepilot/oauth/jwks.php',
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    /**
     * Protected resource metadata (RFC 9728). Served at two URLs (#312, #335):
     * oauth/protected-resource.php linked from WWW-Authenticate, and PATH_INFO
     * on mcp.php for clients deriving the path from the resource URL instead
     * of reading that header. Both use this source.
     *
     * @param string $wwwroot
     * @return mixed[]
     */
    public static function protected_resource_metadata(string $wwwroot): array {
        return [
            'resource' => $wwwroot . '/local/coursepilot/mcp.php',
            'authorization_servers' => [$wwwroot . '/local/coursepilot/oauth.php'],
            'scopes_supported' => ['coursepilot.read'],
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * Discovery handler for oauth.php. Serves only the two known PATH_INFO
     * names (OAuth/OIDC namespaces, #302). Other paths return JSON 404, never HTML.
     *
     * @param string $wwwroot
     * @param string $pathinfo Already trimmed PATH_INFO value.
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    public static function handle_discovery(string $wwwroot, string $pathinfo): array {
        $known = ['', '.well-known/openid-configuration', '.well-known/oauth-authorization-server'];
        if (!in_array($pathinfo, $known, true)) {
            return self::result(404, [], ['error' => 'not_found', 'path_info' => $pathinfo]);
        }
        return self::result(200, ['Cache-Control' => 'no-store'], self::authorization_server_metadata($wwwroot));
    }

    /**
     * Register a client through DCR (RFC 7591).
     *
     * @param mixed[] $metadata Decoded JSON registration body.
     * @return mixed[] On error: ['error' => ..., 'error_description' => ...].
     *               On success: complete client record including client_id.
     */
    public static function register_client(array $metadata): array {
        return (new client_registry())->register_client($metadata);
    }

    /**
     * Registration handler for oauth/register.php; errors are always JSON
     * (RFC 7591, section 3.2.2). Size is checked before decoding and metadata
     * before the budget; any rejection persists no client.
     *
     * @param string $method
     * @param string $rawbody Raw request body, read at most one byte beyond
     *        {@see REGISTRATION_MAX_BODY_BYTES}.
     * @param string $source Trusted request source ({@see oauth_budget::request_source()}).
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    public static function handle_registration(string $method, string $rawbody, string $source): array {
        if ($method !== 'POST') {
            return self::result(405, ['Allow' => 'POST'], [
                'error' => 'invalid_request',
                'error_description' => 'Only POST is allowed.',
            ]);
        }
        if (strlen($rawbody) > self::REGISTRATION_MAX_BODY_BYTES) {
            return self::result(413, [], [
                'error' => 'invalid_client_metadata',
                'error_description' => 'Registration request is too large.',
            ]);
        }
        $body = json_decode($rawbody, true, 16);
        if (!is_array($body)) {
            return self::result(400, [], [
                'error' => 'invalid_client_metadata',
                'error_description' => 'Invalid JSON.',
            ]);
        }
        $error = client_registry::registration_error($body);
        if ($error !== null) {
            return self::result(400, [], $error);
        }

        $retryafter = oauth_budget::consume(
            'register',
            $source,
            oauth_budget::setting('oauthregistersitelimit', self::REGISTRATION_SITE_LIMIT),
            oauth_budget::setting('oauthregistersourcelimit', self::REGISTRATION_SOURCE_LIMIT),
            oauth_budget::setting('oauthregisterwindow', self::REGISTRATION_WINDOW)
        );
        if ($retryafter > 0) {
            return self::result(429, ['Retry-After' => (string) $retryafter], [
                'error' => 'temporarily_unavailable',
                'error_description' => 'Registration budget exhausted, retry later.',
            ]);
        }
        return self::result(201, ['Cache-Control' => 'no-store'], self::register_client($body));
    }

    /**
     * Convert a client row to the RFC 7591 response shape.
     *
     * @param \stdClass $record
     * @return mixed[]
     */
    public static function client_registration_response(\stdClass $record): array {
        return client_registry::client_registration_response($record);
    }

    /**
     * Require HTTPS redirects, except loopback for local CLI clients
     * (RFC 8252, adopted by OAuth 2.1 for native apps).
     *
     * @param mixed $uri
     * @return bool
     */
    public static function is_allowed_redirect_uri($uri): bool {
        return client_registry::is_allowed_redirect_uri($uri);
    }

    /**
     * Resolve a registered or CIMD client through the client registry.
     *
     * @param string $clientid
     * @param int $retryafter Seconds until the CIMD budget window ends, otherwise 0.
     * @return \stdClass|null
     */
    public static function get_client(string $clientid, int &$retryafter = 0): ?\stdClass {
        return (new client_registry())->get_client($clientid, $retryafter);
    }

    /**
     * Validate and cache decoded CIMD metadata through the client registry.
     *
     * @param string $url Public client identifier.
     * @param mixed[] $metadata Decoded metadata.
     * @return \stdClass|null Registered client or null for invalid metadata.
     */
    public static function cache_cimd_client(string $url, array $metadata): ?\stdClass {
        return (new client_registry())->cache_cimd_client($url, $metadata);
    }

    /**
     * Validate authorization parameters: response_type, mandatory
     * PKCE/S256, a known client and its registered redirect. No login, HTML or
     * HTTP response writes: PHPUnit can call this seam without a web server,
     * as with handle_discovery()/handle_registration(). oauth/authorize.php
     * calls it after login and renders either consent or an error page.
     *
     * @param mixed[] $params response_type, client_id, redirect_uri,
     *        code_challenge, code_challenge_method (all expected as strings).
     * @return array{error: string, error_description: string}|array{client: \stdClass}
     */
    public static function validate_authorize_request(array $params): array {
        $problem = self::authorize_shape_error($params);
        if ($problem !== null) {
            return $problem;
        }
        $clientid = (string) $params['client_id'];
        $redirecturi = (string) $params['redirect_uri'];

        $retryafter = 0;
        $client = self::get_client($clientid, $retryafter);
        if ($retryafter > 0) {
            return ['error' => 'temporarily_unavailable',
                'error_description' => 'Client metadata lookup budget exhausted, retry later.'];
        }
        if (!$client) {
            return ['error' => 'invalid_client', 'error_description' => 'Unknown client.'];
        }
        if (!self::redirect_uri_matches($client, $redirecturi)) {
            // Redirect only to verified target URIs (OAuth Security BCP).
            return ['error' => 'invalid_request', 'error_description' => 'redirect_uri is not registered for this client.'];
        }

        return ['client' => $client];
    }

    /**
     * Required authorize parameters and PKCE method.
     *
     * @param mixed[] $params Authorize request parameters.
     * @return array{error: string, error_description: string}|null Error response, null when well-formed.
     */
    private static function authorize_shape_error(array $params): ?array {
        if (
            ($params['response_type'] ?? '') !== 'code'
            || (string) ($params['client_id'] ?? '') === ''
            || (string) ($params['redirect_uri'] ?? '') === ''
            || (string) ($params['code_challenge'] ?? '') === ''
        ) {
            return [
                'error' => 'invalid_request',
                'error_description' => 'response_type=code, client_id, redirect_uri and code_challenge are required.',
            ];
        }
        if ((string) ($params['code_challenge_method'] ?? '') !== 'S256') {
            // OAuth 2.1 requires PKCE for every client and permits only S256.
            return [
                'error' => 'invalid_request',
                'error_description' => 'PKCE is required; only code_challenge_method=S256 is accepted.',
            ];
        }
        return null;
    }

    /**
     * Match redirect_uris registered for the client exactly
     * (RFC 6749 §3.1.2.3: no prefix comparison).
     *
     * @param \stdClass $client
     * @param string $redirecturi
     * @return bool
     */
    public static function redirect_uri_matches(\stdClass $client, string $redirecturi): bool {
        return client_registry::redirect_uri_matches($client, $redirecturi);
    }

    /**
     * Append query parameters to success redirects (code/state) and denial
     * redirects (error/error_description/state). Omit empty or absent values.
     *
     * @param string $redirecturi
     * @param mixed[] $params Type: array<string,?string>.
     * @return string
     */
    public static function build_redirect_url(string $redirecturi, array $params): string {
        $params = array_filter($params, static fn($value) => $value !== null && $value !== '');
        if (empty($params)) {
            return $redirecturi;
        }
        $separator = str_contains($redirecturi, '?') ? '&' : '?';
        return $redirecturi . $separator . http_build_query($params);
    }

    /**
     * Build the denial redirect (RFC 6749 §4.1.2.1: error=access_denied):
     * a client error response without an authorization code or token.
     *
     * @param string $redirecturi
     * @param string|null $state
     * @return string
     */
    public static function denial_redirect_url(string $redirecturi, ?string $state): string {
        return self::build_redirect_url($redirecturi, [
            'error' => 'access_denied',
            'error_description' => 'The teacher denied consent.',
            'state' => $state,
        ]);
    }

    /**
     * Valid empty JWKS. jwks_uri is declared only because the OIDC
     * namespace requires it (#302, item 2). Coursepilot is not an OIDC provider:
     * it issues no signed ID tokens, and access tokens are opaque database values.
     *
     * ponytail: no key material without a use. If real OIDC with signed ID tokens
     * becomes necessary, add RS256 keys here; Moodle already vendors firebase/php-jwt.
     *
     * @return array{keys: mixed[]}
     */
    public static function jwks_document(): array {
        return ['keys' => []];
    }

    /**
     * Provides result.
     *
     * @param int $status The status.
     * @param string[] $headers
     * @param mixed[] $body
     * @return array{status: int, headers: array<string, string>, body: mixed[]}
     */
    private static function result(int $status, array $headers, array $body): array {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
