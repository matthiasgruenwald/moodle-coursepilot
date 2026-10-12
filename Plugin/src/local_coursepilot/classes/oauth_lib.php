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
 * OAuth discovery, client registration, CIMD lookup and consent request validation.
 * Connection lifetime, token exchange and access decisions belong to connection.
 * HTTP handlers return responses without writing headers or ending the request.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class oauth_lib {
    /** @var string Database table for DCR/CIMD-registered clients. */
    private const CLIENT_TABLE = 'local_coursepilot_oauth_client';

    /** @var int Maximum decoded CIMD response size: 1 MiB, enforced while receiving. */
    private const CIMD_MAX_BYTES = 1048576;

    /** @var int Maximum CIMD client_id URL length, the clientid column size (#643). */
    public const CIMD_MAX_URI_LENGTH = 255;

    /** @var int Default first-time CIMD fetches per window for the whole site (setting oauthcimdsitelimit). */
    public const CIMD_SITE_LIMIT = 100;

    /** @var int Default first-time CIMD fetches per window for one source (setting oauthcimdsourcelimit). */
    public const CIMD_SOURCE_LIMIT = 20;

    /** @var int Default CIMD fetch budget window in seconds (setting oauthcimdwindow). */
    public const CIMD_WINDOW = 3600;

    /** @var int Fixed window of the CIMD negative cache: a failed URL is not fetched again until it ends (#643). */
    public const CIMD_NEGATIVE_WINDOW = 600;

    /** @var int Maximum raw DCR request body; larger requests are rejected before decoding (#642). */
    public const REGISTRATION_MAX_BODY_BYTES = 16384;

    /** @var int Maximum length of one registered redirect URI (#642). */
    public const REGISTRATION_MAX_URI_LENGTH = 2048;

    /** @var int Maximum number of redirect URIs per registration (#642). */
    public const REGISTRATION_MAX_REDIRECT_URIS = 10;

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
        $error = self::registration_error($metadata);
        if ($error !== null) {
            return $error;
        }
        $redirecturis = $metadata['redirect_uris'];

        $authmethod = $metadata['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($authmethod, ['none', 'client_secret_post'], true)) {
            $authmethod = 'none';
        }
        $clientname = \core_text::substr(clean_param($metadata['client_name'] ?? '', PARAM_TEXT), 0, 255) ?: null;
        $clientsecret = $authmethod === 'client_secret_post' ? bin2hex(random_bytes(32)) : null;

        $record = self::persist_client(bin2hex(random_bytes(24)), $clientname, $redirecturis, $authmethod, $clientsecret, 'dcr');
        return self::client_registration_response($record);
    }

    /**
     * Validate DCR metadata without side effects, so invalid requests are
     * rejected before they consume budget (#642).
     *
     * @param mixed[] $metadata
     * @return mixed[]|null RFC 7591 error, or null when valid.
     */
    private static function registration_error(array $metadata): ?array {
        $redirecturis = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirecturis) || empty($redirecturis)) {
            return ['error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris is required.'];
        }
        if (count($redirecturis) > self::REGISTRATION_MAX_REDIRECT_URIS) {
            return ['error' => 'invalid_client_metadata', 'error_description' => 'Too many redirect_uris.'];
        }
        foreach ($redirecturis as $uri) {
            if (is_string($uri) && strlen($uri) > self::REGISTRATION_MAX_URI_LENGTH) {
                return ['error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uri is too long.'];
            }
            if (!self::is_allowed_redirect_uri($uri)) {
                return [
                    'error' => 'invalid_redirect_uri',
                    'error_description' => 'redirect_uri must use https or a loopback address (http://127.0.0.1 / '
                        . 'http://localhost).',
                ];
            }
        }
        return null;
    }

    /**
     * Persist a client: the shared final step for DCR (register_client()) and
     * CIMD (cache_cimd_client()), differing only in origin and some field values.
     *
     * @param string $clientid
     * @param string|null $clientname
     * @param mixed[] $redirecturis
     * @param string $tokenendpointauthmethod
     * @param string|null $clientsecret
     * @param string $source 'dcr' or 'cimd'.
     * @return \stdClass
     */
    private static function persist_client(
        string $clientid,
        ?string $clientname,
        array $redirecturis,
        string $tokenendpointauthmethod,
        ?string $clientsecret,
        string $source
    ): \stdClass {
        global $DB;

        $record = new \stdClass();
        $record->clientid = $clientid;
        $record->clientname = $clientname;
        $record->redirecturis = json_encode(array_values($redirecturis));
        $record->tokenendpointauthmethod = $tokenendpointauthmethod;
        $record->clientsecret = $clientsecret;
        $record->source = $source;
        $record->timecreated = time();
        $record->id = $DB->insert_record(self::CLIENT_TABLE, $record);
        return $record;
    }

    /**
     * Registration handler for oauth/register.php; errors are always JSON
     * (RFC 7591, section 3.2.2). Size is checked before decoding and metadata
     * before the budget (#642); any rejection persists no client.
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
        $error = self::registration_error($body);
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
        $response = [
            'client_id' => $record->clientid,
            'client_name' => $record->clientname,
            'redirect_uris' => json_decode($record->redirecturis, true),
            'token_endpoint_auth_method' => $record->tokenendpointauthmethod,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ];
        if ($record->clientsecret !== null) {
            $response['client_secret'] = $record->clientsecret;
        }
        return $response;
    }

    /**
     * Require HTTPS redirects, except loopback for local CLI clients
     * (RFC 8252, adopted by OAuth 2.1 for native apps).
     *
     * @param mixed $uri
     * @return bool
     */
    public static function is_allowed_redirect_uri($uri): bool {
        if (!is_string($uri) || $uri === '') {
            return false;
        }
        $parts = parse_url($uri);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        if ($parts['scheme'] === 'https') {
            return true;
        }
        if ($parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
            return true;
        }
        return false;
    }

    /**
     * Find a client by client_id. When it is absent locally and looks like a
     * URL, fetch and cache its CIMD document: the second registration route
     * without DCR (#291, #335). Otherwise each new CIMD connection would create
     * another DCR client.
     *
     * Stored clients consume no budget. Before a first fetch (#643), reject
     * long URLs and negative-cache entries, then consume the site/source budget
     * (scope cimd). Rejection starts no network work. A failed fetch creates a
     * cimdfail entry lasting CIMD_NEGATIVE_WINDOW; their count per window is
     * capped by the site fetch limit.
     *
     * @param string $clientid
     * @param int $retryafter Set to the seconds until the CIMD budget window
     *        ends when the budget refused the fetch, otherwise 0.
     * @return \stdClass|null
     */
    public static function get_client(string $clientid, int &$retryafter = 0): ?\stdClass {
        global $DB;

        $retryafter = 0;
        $record = $DB->get_record(self::CLIENT_TABLE, ['clientid' => $clientid]);
        if ($record) {
            return $record;
        }
        if (
            !self::looks_like_cimd_url($clientid) || strlen($clientid) > self::CIMD_MAX_URI_LENGTH
                || oauth_budget::active('cimdfail', $clientid)
        ) {
            return null;
        }
        $sitelimit = oauth_budget::setting('oauthcimdsitelimit', self::CIMD_SITE_LIMIT);
        $retryafter = oauth_budget::consume(
            'cimd',
            oauth_budget::request_source(),
            $sitelimit,
            oauth_budget::setting('oauthcimdsourcelimit', self::CIMD_SOURCE_LIMIT),
            oauth_budget::setting('oauthcimdwindow', self::CIMD_WINDOW)
        );
        if ($retryafter > 0) {
            return null;
        }
        $client = self::fetch_and_cache_cimd_client($clientid);
        if ($client === null) {
            oauth_budget::consume('cimdfail', $clientid, $sitelimit, 1, self::CIMD_NEGATIVE_WINDOW);
        }
        return $client;
    }

    /**
     * Whether client_id is an HTTPS URL and thus a CIMD candidate.
     *
     * @param string $clientid
     * @return bool
     */
    protected static function looks_like_cimd_url(string $clientid): bool {
        $parts = parse_url($clientid);
        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
    }

    /**
     * Fetch public HTTPS metadata using Moodle's host/port policy and CA trust.
     * Verify the peer and hostname, refuse redirects, and retain at most 1 MiB
     * of decoded response data within five seconds. No storage credentials are
     * attached. Only a complete, successful response reaches metadata validation
     * and persistence in {@see cache_cimd_client()}.
     *
     * @param string $url
     * @return \stdClass|null
     */
    protected static function fetch_and_cache_cimd_client(string $url): ?\stdClass {
        $curl = new \curl();
        $body = '';
        $curl->get($url, [], [
            'CURLOPT_TIMEOUT' => 5,
            'CURLOPT_FOLLOWLOCATION' => false,
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_WRITEFUNCTION' => static function ($handle, string $chunk) use (&$body): int {
                $length = strlen($chunk);
                if (strlen($body) + $length > self::CIMD_MAX_BYTES) {
                    return 0; // Abort the transfer before retaining an oversized chunk.
                }
                $body .= $chunk;
                return $length;
            },
        ]);
        $info = $curl->get_info();
        if ($curl->get_errno() !== 0 || ($info['http_code'] ?? 0) !== 200) {
            return null;
        }
        $metadata = json_decode($body, true);
        if (!is_array($metadata)) {
            return null;
        }
        return self::cache_cimd_client($url, $metadata);
    }

    /**
     * Validate decoded CIMD metadata and, when valid, persist a client whose
     * clientid is the URL itself.
     *
     * ponytail: no cache refresh. A cached CIMD client retains its first values;
     * add refresh only if changing documents prove necessary in practice.
     *
     * @param string $url client_id (the CIMD URL).
     * @param mixed[] $metadata Decoded CIMD metadata.
     * @return \stdClass|null null for invalid or missing redirect_uris.
     */
    public static function cache_cimd_client(string $url, array $metadata): ?\stdClass {
        // Same redirect URI count/length limits as DCR (#643).
        if (self::registration_error($metadata) !== null) {
            return null;
        }

        $clientname = \core_text::substr(clean_param($metadata['client_name'] ?? '', PARAM_TEXT), 0, 255) ?: null;
        try {
            return self::persist_client($url, $clientname, $metadata['redirect_uris'], 'none', null, 'cimd');
        } catch (\dml_write_exception $e) {
            // A parallel first lookup of the same URL stored it first (#643).
            global $DB;
            return $DB->get_record(self::CLIENT_TABLE, ['clientid' => $url], '*', MUST_EXIST);
        }
    }

    /**
     * Validate authorization parameters (#336): response_type, mandatory
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
        $responsetype = $params['response_type'] ?? '';
        $clientid = (string) ($params['client_id'] ?? '');
        $redirecturi = (string) ($params['redirect_uri'] ?? '');
        $codechallenge = (string) ($params['code_challenge'] ?? '');
        $codechallengemethod = (string) ($params['code_challenge_method'] ?? '');

        if ($responsetype !== 'code' || $clientid === '' || $redirecturi === '' || $codechallenge === '') {
            return [
                'error' => 'invalid_request',
                'error_description' => 'response_type=code, client_id, redirect_uri and code_challenge are required.',
            ];
        }
        if ($codechallengemethod !== 'S256') {
            // OAuth 2.1 requires PKCE for every client and permits only S256.
            return [
                'error' => 'invalid_request',
                'error_description' => 'PKCE is required; only code_challenge_method=S256 is accepted.',
            ];
        }

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
     * Match redirect_uris registered for the client exactly
     * (RFC 6749 §3.1.2.3: no prefix comparison).
     *
     * @param \stdClass $client
     * @param string $redirecturi
     * @return bool
     */
    public static function redirect_uri_matches(\stdClass $client, string $redirecturi): bool {
        $registered = json_decode($client->redirecturis, true) ?? [];
        return in_array($redirecturi, $registered, true);
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
     * a client error response without an authorization code or token (#336).
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
     * Valid empty JWKS (#336). jwks_uri is declared only because the OIDC
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
