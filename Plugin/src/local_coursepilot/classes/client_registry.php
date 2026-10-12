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
 * Register OAuth clients, resolve budgeted CIMD metadata and verify exact redirects.
 * CIMD lookup caches the first valid document as required by the registration protocol.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class client_registry {
    /** @var string Database table for DCR/CIMD-registered clients. */
    private const CLIENT_TABLE = 'local_coursepilot_oauth_client';

    /** @var int Maximum CIMD client_id URL length, the clientid column size. */
    public const CIMD_MAX_URI_LENGTH = 255;

    /** @var int Default first-time CIMD fetches per window for the whole site (setting oauthcimdsitelimit). */
    public const CIMD_SITE_LIMIT = 100;

    /** @var int Default first-time CIMD fetches per window for one source (setting oauthcimdsourcelimit). */
    public const CIMD_SOURCE_LIMIT = 20;

    /** @var int Default CIMD fetch budget window in seconds (setting oauthcimdwindow). */
    public const CIMD_WINDOW = 3600;

    /** @var int Fixed window of the CIMD negative cache: a failed URL is not fetched again until it ends. */
    public const CIMD_NEGATIVE_WINDOW = 600;

    /** @var int Maximum length of one registered redirect URI. */
    public const REGISTRATION_MAX_URI_LENGTH = 2048;

    /** @var int Maximum number of redirect URIs per registration. */
    public const REGISTRATION_MAX_REDIRECT_URIS = 10;

    /** @var cimd_fetch_port Unmanaged metadata source. */
    private cimd_fetch_port $fetcher;

    /**
     * Create a registry using the public HTTPS metadata adapter by default.
     *
     * @param cimd_fetch_port $fetcher Unmanaged metadata source.
     */
    public function __construct(cimd_fetch_port $fetcher = new cimd_http_fetcher()) {
        $this->fetcher = $fetcher;
    }

    /**
     * Register a client through DCR (RFC 7591).
     *
     * @param mixed[] $metadata Decoded JSON registration body.
     * @return mixed[] On error: ['error' => ..., 'error_description' => ...].
     *               On success: complete client record including client_id.
     */
    public function register_client(array $metadata): array {
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

        $record = $this->persist_client(bin2hex(random_bytes(24)), $clientname, $redirecturis, $authmethod, $clientsecret, 'dcr');
        return self::client_registration_response($record);
    }

    /**
     * Validate DCR metadata without side effects, so invalid requests are
     * rejected before they consume budget.
     *
     * @param mixed[] $metadata
     * @return mixed[]|null RFC 7591 error, or null when valid.
     */
    public static function registration_error(array $metadata): ?array {
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
    private function persist_client(
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
        return $parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost', '::1'], true);
    }

    /**
     * Find a client by client_id. When it is absent locally and looks like a
     * URL, fetch and cache its CIMD document: the second registration route
     * without DCR. Otherwise each new CIMD connection would create
     * another DCR client.
     *
     * Stored clients consume no budget. Before a first fetch, reject
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
    public function get_client(string $clientid, int &$retryafter = 0): ?\stdClass {
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
        $metadata = $this->fetcher->fetch($clientid);
        $client = $metadata === null ? null : $this->cache_cimd_client($clientid, $metadata);
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
    private static function looks_like_cimd_url(string $clientid): bool {
        $parts = parse_url($clientid);
        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']);
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
    public function cache_cimd_client(string $url, array $metadata): ?\stdClass {
        // Same redirect URI count/length limits as DCR.
        if (self::registration_error($metadata) !== null) {
            return null;
        }

        $clientname = \core_text::substr(clean_param($metadata['client_name'] ?? '', PARAM_TEXT), 0, 255) ?: null;
        try {
            return $this->persist_client($url, $clientname, $metadata['redirect_uris'], 'none', null, 'cimd');
        } catch (\dml_write_exception $e) {
            // A parallel first lookup of the same URL stored it first.
            global $DB;
            return $DB->get_record(self::CLIENT_TABLE, ['clientid' => $url], '*', MUST_EXIST);
        }
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
}
