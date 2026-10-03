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
 * OAuth-2.1-Discovery und Client-Registrierung (#335).
 *
 * Scope bewusst eng gehalten: Discovery-Metadaten (RFC 8414 + RFC 9728),
 * Dynamic Client Registration (RFC 7591) und Client ID Metadata Document
 * (CIMD) als zweiter Registrierungsweg. Authorize/Consent/Token-Ausgabe
 * folgen erst in #336 - diese Klasse legt keine Codes oder Access-Token an.
 *
 * Seam-Muster wie {@see dispatcher} (#334): jede handle_*-Methode ist eine
 * reine Funktion (Werte rein, Antwortwert raus, kein exit, keine HTTP-
 * Superglobals), per PHPUnit ohne laufenden Webserver pruefbar. Die duennen
 * PHP-Dateien unter oauth/ und oauth.php tun nur noch Ein-/Ausgabe.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class oauth_lib {

    /** @var string DB-Tabelle der per DCR/CIMD registrierten Clients. */
    private const CLIENT_TABLE = 'local_coursepilot_oauth_client';

    /** @var int Maximum decoded CIMD response size: 1 MiB, enforced while receiving. */
    private const CIMD_MAX_BYTES = 1048576;

    /** @var string DB-Tabelle der kurzlebigen, PKCE-gebundenen Autorisierungscodes. */
    private const CODE_TABLE = 'local_coursepilot_oauth_code';

    /** @var string DB-Tabelle der Access-/Refresh-Token. */
    private const TOKEN_TABLE = 'local_coursepilot_oauth_token';

    /** @var string Stable user/client connections, shared by every token generation. */
    private const GRANT_TABLE = 'local_coursepilot_oauth_grant';

    /** @var int|null Stable connection authenticated in this request. */
    private static ?int $currentconnectionid = null;

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

    /** @var int Lebensdauer eines Autorisierungscodes in Sekunden (RFC 6749 empfiehlt kurz). */
    private const CODE_TTL = 120;

    /** @var int Lebensdauer eines Zugriffstokens in Sekunden - 1 Stunde (#336). */
    public const ACCESS_TOKEN_TTL = 3600;

    /** @var int Lebensdauer eines Erneuerungstokens in Sekunden - 30 Tage (#336). */
    public const REFRESH_TOKEN_TTL = 30 * 24 * 3600;

    /**
     * @var int|null Datensatz-ID des zuletzt per {@see authenticate_access_token()}
     *      erfolgreich aufgeloesten Access-Tokens (#501) - die "ausstellende
     *      Verbindung" eines waehrend dieser Anfrage ausgestellten
     *      Werkbank-Downloadtickets ({@see \local_coursepilot\workbench_ticket::issue()}).
     *      Prozessweites Request-Gedaechtnis wie $USER, kein DB-Zustand -
     *      ponytail: keine Dependency-Injection-Kette durch dispatcher ->
     *      external_api::call_external_function() -> Werkzeug, nur damit ein
     *      einzelner Wert durchgereicht wird.
     */
    private static ?int $currenttokenid = null;

    /**
     * Autorisierungsserver-Metadaten (RFC 8414). Reine Funktion des
     * Ausstellers - kein globaler Zugriff, damit ohne Moodle-Bootstrap
     * pruefbar.
     *
     * @param string $wwwroot
     * @return array
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
            // OIDC-Pflichtfelder - erzwungen durch den Namensraum, obwohl das
            // Plugin kein OIDC-Provider ist (#302, Punkt 2).
            'jwks_uri' => $wwwroot . '/local/coursepilot/oauth/jwks.php',
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
        ];
    }

    /**
     * Metadaten der geschuetzten Ressource (RFC 9728). Wird unter zwei
     * Adressen ausgeliefert (#312, #335): oauth/protected-resource.php (aus
     * dem WWW-Authenticate-Header verlinkt) und als PATH_INFO auf mcp.php
     * selbst (von Clients, die den Header nie lesen und den Pfad aus der
     * Ressourcen-URL ableiten) - beide rufen diese eine Quelle auf.
     *
     * @param string $wwwroot
     * @return array
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
     * Discovery-Handler fuer oauth.php: bedient ausschliesslich die beiden
     * bekannten Namen unter PATH_INFO (OAuth- und OIDC-Namensraum, #302).
     * Alles andere ist ein Irrlaeufer und liefert 404 als JSON, nie HTML.
     *
     * @param string $wwwroot
     * @param string $pathinfo Bereits getrimmter PATH_INFO-Wert.
     * @return array{status: int, headers: array<string, string>, body: array}
     */
    public static function handle_discovery(string $wwwroot, string $pathinfo): array {
        $known = ['', '.well-known/openid-configuration', '.well-known/oauth-authorization-server'];
        if (!in_array($pathinfo, $known, true)) {
            return self::result(404, [], ['error' => 'not_found', 'path_info' => $pathinfo]);
        }
        return self::result(200, ['Cache-Control' => 'no-store'], self::authorization_server_metadata($wwwroot));
    }

    /**
     * Registriert einen Client per DCR (RFC 7591).
     *
     * @param array $metadata Decodierter JSON-Body der Registrierungsanfrage.
     * @return array Fehlerfall: ['error' => ..., 'error_description' => ...].
     *               Erfolg: vollstaendiger Client-Datensatz inkl. client_id.
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
        $clientsecret = $authmethod === 'client_secret_post' ? self::random_token(32) : null;

        $record = self::persist_client(self::random_token(24), $clientname, $redirecturis, $authmethod, $clientsecret, 'dcr');
        return self::client_registration_response($record);
    }

    /**
     * Validate DCR metadata without side effects, so invalid requests are
     * rejected before they consume budget (#642).
     *
     * @param array $metadata
     * @return array|null RFC 7591 error, or null when valid.
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
                    'error_description' => 'redirect_uri must use https or a loopback address (http://127.0.0.1 / http://localhost).',
                ];
            }
        }
        return null;
    }

    /**
     * Legt einen Client-Datensatz an - gemeinsamer letzter Schritt fuer DCR
     * (register_client()) und CIMD (cache_cimd_client()), die sich nur in
     * Herkunft und ein paar Feldwerten unterscheiden.
     *
     * @param string $clientid
     * @param string|null $clientname
     * @param array $redirecturis
     * @param string $tokenendpointauthmethod
     * @param string|null $clientsecret
     * @param string $source 'dcr' oder 'cimd'.
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
     * @return array{status: int, headers: array<string, string>, body: array}
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

        $retryafter = oauth_budget::consume('register', $source,
            oauth_budget::setting('oauthregistersitelimit', self::REGISTRATION_SITE_LIMIT),
            oauth_budget::setting('oauthregistersourcelimit', self::REGISTRATION_SOURCE_LIMIT),
            oauth_budget::setting('oauthregisterwindow', self::REGISTRATION_WINDOW));
        if ($retryafter > 0) {
            return self::result(429, ['Retry-After' => (string) $retryafter], [
                'error' => 'temporarily_unavailable',
                'error_description' => 'Registration budget exhausted, retry later.',
            ]);
        }
        return self::result(201, ['Cache-Control' => 'no-store'], self::register_client($body));
    }

    /**
     * Formt einen Client-Datensatz in die RFC-7591-Antwortform.
     *
     * @param \stdClass $record
     * @return array
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
     * https-Redirect-URIs sind Pflicht, ausser Loopback fuer lokale
     * CLI-Clients (RFC 8252, von OAuth 2.1 fuer native Apps uebernommen).
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
     * Holt einen Client per client_id. Fehlt er lokal und sieht die
     * client_id wie eine URL aus, wird sie als CIMD-Dokument abgerufen und
     * gecacht - der zweite, DCR-lose Registrierungsweg (#291, #335): sonst
     * legt jede Neuverbindung eines CIMD-Clients einen weiteren
     * DCR-Client an.
     *
     * @param string $clientid
     * @return \stdClass|null
     */
    public static function get_client(string $clientid): ?\stdClass {
        global $DB;

        $record = $DB->get_record(self::CLIENT_TABLE, ['clientid' => $clientid]);
        if ($record) {
            return $record;
        }
        if (!self::looks_like_cimd_url($clientid)) {
            return null;
        }
        return self::fetch_and_cache_cimd_client($clientid);
    }

    /**
     * Ist diese client_id eine https-URL (CIMD-Kandidat)?
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
     * Prueft ein bereits dekodiertes CIMD-Dokument und legt bei Gueltigkeit
     * einen Client-Datensatz an, dessen clientid die URL selbst ist.
     *
     * ponytail: kein Cache-Refresh-Mechanismus - ein einmal gecachter
     * CIMD-Client bleibt bei den Werten des ersten Abrufs. Nachziehen, falls
     * sich in der Praxis zeigt, dass CIMD-Dokumente sich aendern muessen.
     *
     * @param string $url Die client_id (= CIMD-URL).
     * @param array $metadata Dekodiertes CIMD-Dokument.
     * @return \stdClass|null null bei ungueltigen/fehlenden redirect_uris.
     */
    public static function cache_cimd_client(string $url, array $metadata): ?\stdClass {
        $redirecturis = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirecturis) || empty($redirecturis)) {
            return null;
        }
        foreach ($redirecturis as $uri) {
            if (!self::is_allowed_redirect_uri($uri)) {
                return null;
            }
        }

        $clientname = clean_param($metadata['client_name'] ?? '', PARAM_TEXT) ?: null;
        return self::persist_client($url, $clientname, $redirecturis, 'none', null, 'cimd');
    }

    /**
     * Prueft eine Autorisierungsanfrage rein logisch (#336): response_type,
     * PKCE/S256-Pflicht, bekannter Client, registriertes Umleitungsziel.
     * Reine Funktion - kein require_login(), kein HTML, damit ohne
     * laufenden Webserver per PHPUnit pruefbar (Schalenmuster wie
     * handle_discovery()/handle_registration()). oauth/authorize.php ruft
     * dies nach require_login() auf und rendert bei Erfolg den
     * Zustimmungsdialog, bei Fehler eine Fehlerseite.
     *
     * @param array $params response_type, client_id, redirect_uri,
     *        code_challenge, code_challenge_method (alle als string erwartet).
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
                'error_description' => 'response_type=code, client_id, redirect_uri und code_challenge sind Pflicht.',
            ];
        }
        if ($codechallengemethod !== 'S256') {
            // OAuth 2.1: PKCE ist fuer alle Clients Pflicht, nur S256 erlaubt.
            return [
                'error' => 'invalid_request',
                'error_description' => 'PKCE ist Pflicht, nur code_challenge_method=S256 wird akzeptiert.',
            ];
        }

        $client = self::get_client($clientid);
        if (!$client) {
            return ['error' => 'invalid_client', 'error_description' => 'Unbekannter Client.'];
        }
        if (!self::redirect_uri_matches($client, $redirecturi)) {
            // Kein Redirect zu einer unverifizierten Ziel-URI (OAuth Security BCP).
            return ['error' => 'invalid_request', 'error_description' => 'redirect_uri ist bei diesem Client nicht registriert.'];
        }

        return ['client' => $client];
    }

    /**
     * Exakter Abgleich gegen die bei der Registrierung hinterlegten
     * redirect_uris (RFC 6749, 3.1.2.3: kein Praefixvergleich).
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
     * Stellt einen Autorisierungscode aus - erst nach erfolgreicher
     * Zustimmung durch die Lehrkraft (oauth/authorize.php).
     *
     * @param string $clientid
     * @param int $userid
     * @param string $redirecturi Muss beim Einloesen exakt wiederkehren.
     * @param string $codechallenge PKCE-S256-Challenge des Clients.
     * @return string Der Autorisierungscode.
     */
    public static function issue_code(string $clientid, int $userid, string $redirecturi, string $codechallenge): string {
        global $DB;

        $code = self::random_token(32);
        $record = new \stdClass();
        $record->code = $code;
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->redirecturi = $redirecturi;
        $record->codechallenge = $codechallenge;
        $record->expires = time() + self::CODE_TTL;
        $record->used = 0;
        $DB->insert_record(self::CODE_TABLE, $record);

        return $code;
    }

    /**
     * Haengt Query-Parameter an ein Umleitungsziel an - fuer den
     * Erfolgs-Redirect (code, state) wie den Ablehnungs-Redirect (error,
     * error_description, state). Leere/fehlende Werte werden weggelassen.
     *
     * @param string $redirecturi
     * @param array<string, ?string> $params
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
     * Baut das Umleitungsziel fuer eine Ablehnung im Zustimmungsdialog
     * (RFC 6749, 4.1.2.1: error=access_denied) - eine saubere Fehlerantwort
     * an den Client, kein Autorisierungscode, kein Token (#336).
     *
     * @param string $redirecturi
     * @param string|null $state
     * @return string
     */
    public static function denial_redirect_url(string $redirecturi, ?string $state): string {
        return self::build_redirect_url($redirecturi, [
            'error' => 'access_denied',
            'error_description' => 'Die Lehrkraft hat die Zustimmung verweigert.',
            'state' => $state,
        ]);
    }

    /**
     * Tauscht einen Autorisierungscode gegen ein Token-Paar (RFC 6749
     * 4.1.3). Prueft Einmaligkeit, Ablauf, Client- und
     * Umleitungsziel-Bindung sowie den PKCE-Code-Verifier gegen die bei
     * issue_code() hinterlegte Challenge.
     *
     * @param string $code
     * @param string $clientid
     * @param string $redirecturi
     * @param string $codeverifier
     * @return array|null null bei jeglichem Fehler (RFC 6749: invalid_grant,
     *         kein Detailgrund nach aussen).
     */
    public static function exchange_code(string $code, string $clientid, string $redirecturi, string $codeverifier): ?array {
        global $DB;

        $record = $DB->get_record(self::CODE_TABLE, ['code' => $code]);
        if (!$record || (int) $record->used === 1 || $record->expires < time()) {
            return null;
        }
        if ($record->clientid !== $clientid || $record->redirecturi !== $redirecturi) {
            return null;
        }
        if (!self::verify_pkce($codeverifier, $record->codechallenge)) {
            return null;
        }

        // Anspruch (claim_row()) und Tokenausstellung als eine Datenbank-
        // grenze (#574): schlaegt die Ausstellung fehl, macht das Rollback
        // den Anspruch rueckgaengig statt den Code dauerhaft zu verbrennen.
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!self::claim_row(self::CODE_TABLE, 'code', 'used', $record->code)) {
                // Rivalisierender Anspruch war schneller - fuer den Aufrufer
                // ununterscheidbar von invalid_grant.
                $transaction->allow_commit();
                return null;
            }
            $tokens = self::issue_token_pair($clientid, (int) $record->userid);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();

        return $tokens;
    }

    /**
     * Erneuert ein Token-Paar per Refresh-Token, mit Rotation (#336): das
     * alte Refresh-Token wird entwertet, ein neues Paar ausgestellt - ein
     * Rechteentzug wirkt so spaetestens nach Ablauf des Zugriffstokens
     * (1 Stunde), nicht erst nach 30 Tagen.
     *
     * @param string $refreshtoken
     * @param string $clientid
     * @return array|null null bei ungueltigem/abgelaufenem/widerrufenem Token
     *         oder Client-Mismatch.
     */
    public static function rotate_refresh_token(string $refreshtoken, string $clientid): ?array {
        global $DB;

        $record = $DB->get_record(self::TOKEN_TABLE, ['refreshtokenhash' => self::token_hash($refreshtoken)]);
        if (!$record || $record->clientid !== $clientid) {
            return null;
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            // UPDATE locks the shared grant until commit. Revocation uses the same
            // row, so it either prevents issuance or invalidates its successor.
            if (empty($record->connectionid) || !self::lock_connection((int) $record->connectionid)) {
                $transaction->allow_commit();
                return null;
            }
            $current = $DB->get_record(self::TOKEN_TABLE, ['id' => $record->id]);
            if ($current && (int) $current->revoked === 1) {
                // A consumed hash proves replay even after its old expiry. Check
                // under the grant lock so concurrent rotation cannot escape it.
                self::revoke_locked_connection((int) $record->connectionid);
                $transaction->allow_commit();
                return null;
            }
            if (!$current || $current->refreshexpires < time()) {
                $transaction->allow_commit();
                return null;
            }
            // Preserve the consumed refresh hash and its proven family identity.
            $DB->set_field(self::TOKEN_TABLE, 'revoked', 1, ['id' => $record->id]);
            $tokens = self::issue_token_pair($clientid, (int) $record->userid, (int) $record->connectionid);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();

        return $tokens;
    }

    /**
     * Claim an authorization code exactly once using an unguessable CAS marker.
     * Refresh generations retain their hashes and serialize on the stable grant.
     * The column names are fixed at the sole caller, never user input.
     *
     * @param string $table
     * @param string $column Unique code column.
     * @param string $flagcolumn Consumption flag.
     * @param string $value Original code.
     * @return bool Whether this transaction claimed the code.
     */
    private static function claim_row(string $table, string $column, string $flagcolumn, string $value): bool {
        global $DB;

        $claim = hash('sha256', $value . '|' . self::random_token(16));
        $DB->execute(
            "UPDATE {{$table}} SET {$column} = :claim, {$flagcolumn} = 1 WHERE {$column} = :value AND {$flagcolumn} = 0",
            ['claim' => $claim, 'value' => $value]
        );
        return $DB->record_exists($table, [$column => $claim]);
    }

    /**
     * PKCE-S256-Verifikation (RFC 7636, 4.6): BASE64URL(SHA256(verifier))
     * muss der bei der Autorisierungsanfrage hinterlegten Challenge
     * entsprechen. hash_equals() gegen Timing-Angriffe.
     *
     * @param string $codeverifier
     * @param string $codechallenge
     * @return bool
     */
    private static function verify_pkce(string $codeverifier, string $codechallenge): bool {
        $computed = rtrim(strtr(base64_encode(hash('sha256', $codeverifier, true)), '+/', '-_'), '=');
        return hash_equals($codechallenge, $computed);
    }

    /**
     * Legt ein neues Access-/Refresh-Token-Paar an und liefert die
     * RFC-6749-Antwortform. Gemeinsamer letzter Schritt fuer exchange_code()
     * und rotate_refresh_token().
     *
     * @param string $clientid
     * @param int $userid
     * @param int|null $connectionid Existing grant on rotation, otherwise a new authorisation.
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    private static function issue_token_pair(string $clientid, int $userid, ?int $connectionid = null): array {
        global $DB;

        $now = time();
        if ($connectionid === null) {
            $connectionid = (int) $DB->insert_record(self::GRANT_TABLE, (object) [
                'userid' => $userid, 'clientid' => $clientid, 'revoked' => 0,
                'statehash' => self::random_token(32), 'timecreated' => $now,
            ]);
        }
        $accesstoken = self::random_token(32);
        $refreshtoken = self::random_token(32);
        $record = new \stdClass();
        $record->connectionid = $connectionid;
        $record->accesstokenhash = self::token_hash($accesstoken);
        $record->refreshtokenhash = self::token_hash($refreshtoken);
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->expires = $now + self::ACCESS_TOKEN_TTL;
        $record->refreshexpires = $now + self::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = $now;
        $DB->insert_record(self::TOKEN_TABLE, $record);

        return [
            'access_token' => $accesstoken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $refreshtoken,
        ];
    }

    /**
     * Token-Handler fuer oauth/token.php (#336): Methodenpruefung,
     * Grant-Type-Dispatch (authorization_code/refresh_token),
     * client_secret_post-Pruefung, einheitliches Antwortformat. Reine
     * Funktion (Schalenmuster wie handle_registration()) - kein exit(),
     * $body ist bereits eingelesen (Formular- oder JSON-Rumpf, das
     * Unterscheiden ist Ein-/Ausgabe und bleibt in der Schale).
     *
     * @param string $method
     * @param array|null $body
     * @return array{status: int, headers: array<string, string>, body: array}
     */
    public static function handle_token(string $method, ?array $body): array {
        if ($method !== 'POST') {
            return self::result(405, ['Allow' => 'POST'], ['error' => 'invalid_request']);
        }
        if ($body === null) {
            return self::result(400, [], ['error' => 'invalid_request']);
        }

        $granttype = (string) ($body['grant_type'] ?? '');
        $clientid = (string) ($body['client_id'] ?? '');
        $client = $clientid !== '' ? self::get_client($clientid) : null;
        if (!$client) {
            return self::result(400, [], ['error' => 'invalid_client']);
        }
        // client_secret_post-Clients authentifizieren sich zusaetzlich - PKCE
        // deckt oeffentliche Clients bereits ab (#291).
        if ($client->tokenendpointauthmethod === 'client_secret_post') {
            $secret = (string) ($body['client_secret'] ?? '');
            if ($secret === '' || !hash_equals((string) $client->clientsecret, $secret)) {
                return self::result(401, [], ['error' => 'invalid_client']);
            }
        }

        if ($granttype === 'authorization_code') {
            $code = (string) ($body['code'] ?? '');
            $redirecturi = (string) ($body['redirect_uri'] ?? '');
            $codeverifier = (string) ($body['code_verifier'] ?? '');
            if ($code === '' || $redirecturi === '' || $codeverifier === '') {
                return self::result(400, [], ['error' => 'invalid_request']);
            }
            $tokens = self::exchange_code($code, $clientid, $redirecturi, $codeverifier);
        } else if ($granttype === 'refresh_token') {
            $refreshtoken = (string) ($body['refresh_token'] ?? '');
            if ($refreshtoken === '') {
                return self::result(400, [], ['error' => 'invalid_request']);
            }
            $tokens = self::rotate_refresh_token($refreshtoken, $clientid);
        } else {
            return self::result(400, [], ['error' => 'unsupported_grant_type']);
        }

        if ($tokens === null) {
            return self::result(400, [], ['error' => 'invalid_grant']);
        }
        return self::result(200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache'], $tokens);
    }

    /**
     * Loest ein OAuth-Access-Token auf eine Moodle-userid auf (#337). Gueltig
     * heisst: der Datensatz existiert, ist nicht widerrufen/rotiert
     * (revoked=0) und noch nicht abgelaufen. Ersetzt die bisherige
     * Webservice-Token-Kruecke aus dem Prototypen vollstaendig - ein
     * external_tokens-Eintrag wird hier nie geprueft.
     *
     * Reine DB-Lookup-Funktion, kein $USER/Session-Aufbau - das bleibt
     * Aufgabe des Aufrufers (dispatcher::authenticate()), analog zum
     * bestehenden Seam-Muster.
     *
     * @param string $accesstoken
     * @return int|null userid, oder null wenn das Token unbekannt, widerrufen
     *         oder abgelaufen ist.
     */
    public static function authenticate_access_token(string $accesstoken): ?int {
        global $DB;

        self::$currenttokenid = null;
        self::$currentconnectionid = null;
        $record = $DB->get_record(self::TOKEN_TABLE, ['accesstokenhash' => self::token_hash($accesstoken)]);
        if (!$record || (int) $record->revoked === 1 || $record->expires < time()
                || empty($record->connectionid) || !self::grant_active((int) $record->connectionid, (int) $record->userid)) {
            return null;
        }
        self::$currenttokenid = (int) $record->id;
        self::$currentconnectionid = (int) $record->connectionid;
        return (int) $record->userid;
    }

    /**
     * Die Datensatz-ID der Verbindung, die die laufende Anfrage authentifiziert
     * hat - siehe {@see $currenttokenid}. Null ausserhalb einer per
     * {@see authenticate_access_token()} authentifizierten Anfrage (z.B. ein
     * Werkzeugtest, der die externe Funktion direkt aufruft).
     *
     * @return int|null
     */
    public static function current_token_id(): ?int {
        return self::$currenttokenid;
    }

    /**
     * Resolve a legacy token-row reference to its stable connection.
     * Rotation consumes the pair without revoking the connection. Tickets have
     * their own expiry and require the grant, independently of token lifetimes.
     *
     * @param int $id Legacy local_coursepilot_oauth_token.id.
     * @param int|null $owneruserid Optional ticket-owner boundary.
     * @return bool
     */
    public static function connection_active(int $id, ?int $owneruserid = null): bool {
        global $DB;

        $conditions = ['id' => $id];
        if ($owneruserid !== null) {
            $conditions['userid'] = $owneruserid;
        }
        $record = $DB->get_record(self::TOKEN_TABLE, $conditions);
        return $record && !empty($record->connectionid)
            && self::grant_active((int) $record->connectionid, (int) $record->userid);
    }

    /**
     * Ob eine Person ueberhaupt noch irgendeine Coursepilot-Verbindung hat -
     * fuer ein Werkbank-Ticket, das ohne bekannte ausstellende Verbindung
     * ausgestellt wurde (#512, Nachtrag zu Spec #486 §13: "nie staerker als
     * seine Verbindung", auch wenn beim Ausstellen keine Verbindungskennung
     * vorlag). Ohne diese Pruefung waere ein solches Ticket der einzige Weg
     * an eine Werkbankdatei, der einen Sammelwiderruf (#338) ueberlebt.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_active_connection(int $userid): bool {
        global $DB;

        return $DB->record_exists(self::GRANT_TABLE, ['userid' => $userid, 'revoked' => 0]);
    }

    /**
     * Setzt {@see $currenttokenid} zurueck - nur fuer Tests, die mehrere,
     * voneinander unabhaengige Anfragen im selben PHPUnit-Prozess simulieren.
     *
     * @return void
     */
    public static function reset_current_token_id(): void {
        self::$currenttokenid = null;
        self::$currentconnectionid = null;
    }

    /**
     * Sammelwiderruf (#338): entwertet alle noch aktiven Access-/Refresh-
     * Token, unabhaengig von Person oder Client - das Werkzeug fuer den
     * Sicherheitsvorfall. Im Unterschied zur Notbremse (dispatcher-
     * Killswitch ueber die Einstellung remoteaccessenabled), die nur neue
     * Zugriffe sperrt, ausgegebene Token dabei aber unangetastet laesst.
     *
     * @return int Anzahl der widerrufenen Token.
     */
    public static function revoke_all_tokens(): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            // Lock grants before tokens, matching rotation and single revocation.
            $DB->set_field(self::GRANT_TABLE, 'revoked', 1, ['revoked' => 0]);
            $count = $DB->count_records(self::TOKEN_TABLE, ['revoked' => 0]);
            $DB->set_field(self::TOKEN_TABLE, 'revoked', 1, ['revoked' => 0]);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
        return $count;
    }

    /**
     * Widerruft ein einzelnes Token per Datensatz-ID (#338).
     *
     * $owneruserid erzwingt Eigentuemerschaft direkt in der Abfrage, statt
     * sich auf eine Pruefung im Aufrufer zu verlassen - die
     * Selbstverwaltungsseite der Lehrkraft uebergibt hier $USER->id, die
     * Administrationsuebersicht laesst den Parameter weg.
     *
     * @param int $id
     * @param int|null $owneruserid Nur setzen, wenn ausschliesslich eigene
     *        Token widerrufbar sein sollen.
     * @return bool false, wenn kein passender aktiver Datensatz existiert
     *         (unbekannte ID, bereits widerrufen oder - bei gesetztem
     *         $owneruserid - ein fremdes Token).
     */
    public static function revoke_token(int $id, ?int $owneruserid = null): bool {
        global $DB;

        $conditions = ['id' => $id];
        if ($owneruserid !== null) {
            $conditions['userid'] = $owneruserid;
        }
        $record = $DB->get_record(self::TOKEN_TABLE, $conditions);
        if (!$record || empty($record->connectionid)) {
            return false;
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            if (!self::lock_connection((int) $record->connectionid)) {
                $transaction->allow_commit();
                return false;
            }
            self::revoke_locked_connection((int) $record->connectionid);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
        return true;
    }

    /**
     * Aktive Verbindungen einer einzelnen Person (Selbstverwaltungsseite,
     * #338) - nie fremde, weil die WHERE-Klausel selbst die Grenze zieht,
     * statt sich auf die Anzeige zu verlassen.
     *
     * @param int $userid
     * @return \stdClass[] Absteigend nach Ausstellungszeitpunkt, jeweils mit
     *         clientname (kann null sein).
     */
    public static function active_tokens_for_user(int $userid): array {
        global $DB;

        return $DB->get_records_sql(
            'SELECT t.id, t.clientid, t.userid, t.timecreated, t.expires, c.clientname
               FROM {' . self::TOKEN_TABLE . '} t
               JOIN {' . self::GRANT_TABLE . '} g ON g.id = t.connectionid AND g.revoked = 0
          LEFT JOIN {' . self::CLIENT_TABLE . '} c ON c.clientid = t.clientid
              WHERE t.userid = :userid AND t.revoked = 0
           ORDER BY t.timecreated DESC',
            ['userid' => $userid]
        );
    }

    /**
     * Alle aktiven Verbindungen ueber alle Personen (Administrations-
     * Uebersicht, #338).
     *
     * @return \stdClass[] Absteigend nach Ausstellungszeitpunkt, jeweils mit
     *         clientname sowie Name/E-Mail der Person.
     */
    public static function active_tokens(): array {
        global $DB;

        // Alle Namensfelder, die fullname() erwartet - sonst meldet Moodle
        // fehlende Felder per debugging() (#578).
        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

        return $DB->get_records_sql(
            'SELECT t.id, t.clientid, t.userid, t.timecreated, t.expires, c.clientname,
                    ' . $namefields . ', u.email
               FROM {' . self::TOKEN_TABLE . '} t
               JOIN {' . self::GRANT_TABLE . '} g ON g.id = t.connectionid AND g.revoked = 0
          LEFT JOIN {' . self::CLIENT_TABLE . '} c ON c.clientid = t.clientid
          LEFT JOIN {user} u ON u.id = t.userid
              WHERE t.revoked = 0
           ORDER BY t.timecreated DESC'
        );
    }

    /** Stable issuing connection, independent of the token generation. */
    public static function current_connection_id(): ?int {
        return self::$currentconnectionid;
    }

    /** Tickets keep their own expiry; require the grant and its owner. */
    public static function grant_active(int $id, int $userid): bool {
        global $DB;
        return $DB->record_exists(self::GRANT_TABLE, ['id' => $id, 'userid' => $userid, 'revoked' => 0]);
    }

    /** Acquire the shared connection row within a delegated transaction. */
    private static function lock_connection(int $id): bool {
        global $DB;
        $marker = self::random_token(32);
        $DB->execute('UPDATE {' . self::GRANT_TABLE . '}
                         SET statehash = :marker WHERE id = :id AND revoked = 0',
            ['marker' => $marker, 'id' => $id]);
        return $DB->record_exists(self::GRANT_TABLE, ['id' => $id, 'statehash' => $marker, 'revoked' => 0]);
    }

    /** Revoke all generations and bound tickets while holding the grant lock. */
    private static function revoke_locked_connection(int $id): void {
        global $DB;
        $DB->set_field(self::GRANT_TABLE, 'revoked', 1, ['id' => $id]);
        $DB->set_field(self::TOKEN_TABLE, 'revoked', 1, ['connectionid' => $id]);
    }

    /**
     * JWKS-Dokument (#336): leer, aber valide. jwks_uri ist nur deklariert,
     * weil der OIDC-Namensraum es erzwingt (#302, Punkt 2) -
     * local_coursepilot ist kein OIDC-Provider und stellt keine signierten
     * ID-Token aus; Access-Token sind opake DB-Werte.
     *
     * ponytail: kein Schluesselmaterial, weil es keinen Verwendungszweck
     * hat. Wird einer sichtbar (signierte ID-Token fuer echtes OIDC), kommt
     * hier RS256-Schluesselmaterial rein - Moodle vendort firebase/php-jwt
     * bereits.
     *
     * @return array{keys: array}
     */
    public static function jwks_document(): array {
        return ['keys' => []];
    }

    /**
     * Kryptografisch zufaelliger Token als Hex-String.
     *
     * @param int $bytes
     * @return string
     */
    public static function random_token(int $bytes = 32): string {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Der DB-Lookup verwendet nur den festen SHA-256-Hash des Geheimnisses.
     * Damit werden Tokens weder persistiert noch als Klartext-Abfragewert
     * verarbeitet; der Unique-Index bleibt fuer die Authentifizierung nutzbar.
     *
     * @param string $token
     * @return string
     */
    private static function token_hash(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * @param array<string, string> $headers
     * @param array $body
     * @return array{status: int, headers: array<string, string>, body: array}
     */
    private static function result(int $status, array $headers, array $body): array {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
