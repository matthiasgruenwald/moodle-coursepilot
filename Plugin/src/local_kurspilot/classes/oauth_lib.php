<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot;

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
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class oauth_lib {

    /** @var string DB-Tabelle der per DCR/CIMD registrierten Clients. */
    private const CLIENT_TABLE = 'local_kurspilot_oauth_client';

    /** @var string DB-Tabelle der kurzlebigen, PKCE-gebundenen Autorisierungscodes. */
    private const CODE_TABLE = 'local_kurspilot_oauth_code';

    /** @var string DB-Tabelle der Access-/Refresh-Token. */
    private const TOKEN_TABLE = 'local_kurspilot_oauth_token';

    /** @var int Lebensdauer eines Autorisierungscodes in Sekunden (RFC 6749 empfiehlt kurz). */
    private const CODE_TTL = 120;

    /** @var int Lebensdauer eines Zugriffstokens in Sekunden - 1 Stunde (#336). */
    public const ACCESS_TOKEN_TTL = 3600;

    /** @var int Lebensdauer eines Erneuerungstokens in Sekunden - 30 Tage (#336). */
    public const REFRESH_TOKEN_TTL = 30 * 24 * 3600;

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
            'issuer' => $wwwroot . '/local/kurspilot/oauth.php',
            'authorization_endpoint' => $wwwroot . '/local/kurspilot/oauth/authorize.php',
            'token_endpoint' => $wwwroot . '/local/kurspilot/oauth/token.php',
            'registration_endpoint' => $wwwroot . '/local/kurspilot/oauth/register.php',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post'],
            'scopes_supported' => ['kurspilot.read'],
            // OIDC-Pflichtfelder - erzwungen durch den Namensraum, obwohl das
            // Plugin kein OIDC-Provider ist (#302, Punkt 2).
            'jwks_uri' => $wwwroot . '/local/kurspilot/oauth/jwks.php',
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
            'resource' => $wwwroot . '/local/kurspilot/mcp.php',
            'authorization_servers' => [$wwwroot . '/local/kurspilot/oauth.php'],
            'scopes_supported' => ['kurspilot.read'],
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
        $redirecturis = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirecturis) || empty($redirecturis)) {
            return ['error' => 'invalid_client_metadata', 'error_description' => 'redirect_uris ist Pflicht.'];
        }
        foreach ($redirecturis as $uri) {
            if (!self::is_allowed_redirect_uri($uri)) {
                return [
                    'error' => 'invalid_redirect_uri',
                    'error_description' => 'redirect_uri muss https sein oder ein Loopback (http://127.0.0.1 / http://localhost).',
                ];
            }
        }

        $authmethod = $metadata['token_endpoint_auth_method'] ?? 'none';
        if (!in_array($authmethod, ['none', 'client_secret_post'], true)) {
            $authmethod = 'none';
        }
        $clientname = clean_param($metadata['client_name'] ?? '', PARAM_TEXT) ?: null;
        $clientsecret = $authmethod === 'client_secret_post' ? self::random_token(32) : null;

        $record = self::persist_client(self::random_token(24), $clientname, $redirecturis, $authmethod, $clientsecret, 'dcr');
        return self::client_registration_response($record);
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
     * Registrierungs-Handler fuer oauth/register.php: Methodenpruefung,
     * JSON-Parsing, Aufruf von register_client(), einheitliches
     * Antwortformat - Fehlerantworten immer JSON (RFC 7591, Abschnitt 3.2.2).
     *
     * @param string $method
     * @param array|null $body Bereits dekodierter JSON-Rumpf, oder null bei
     *        Parse-Fehler.
     * @return array{status: int, headers: array<string, string>, body: array}
     */
    public static function handle_registration(string $method, ?array $body): array {
        if ($method !== 'POST') {
            return self::result(405, ['Allow' => 'POST'], [
                'error' => 'invalid_request',
                'error_description' => 'Nur POST ist erlaubt.',
            ]);
        }
        if ($body === null) {
            return self::result(400, [], [
                'error' => 'invalid_client_metadata',
                'error_description' => 'Ungueltiges JSON.',
            ]);
        }

        $result = self::register_client($body);
        if (isset($result['error'])) {
            return self::result(400, [], $result);
        }
        return self::result(201, ['Cache-Control' => 'no-store'], $result);
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
     * Ruft ein CIMD-Dokument per HTTP ab. Duenne Netzwerk-Schale um
     * {@see cache_cimd_client()} - die eigentliche Pruef-/Persistierlogik ist
     * dort, netzwerkfrei und damit per PHPUnit ohne echten HTTP-Request
     * pruefbar (#335: Erfolgsfall zuvor ungetestet, da nur ueber Netzwerk
     * erreichbar).
     *
     * @param string $url
     * @return \stdClass|null
     */
    protected static function fetch_and_cache_cimd_client(string $url): ?\stdClass {
        $curl = new \curl();
        $body = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 5, 'CURLOPT_FOLLOWLOCATION' => false]);
        $info = $curl->get_info();
        if (($info['http_code'] ?? 0) !== 200) {
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

        // Ein Code ist genau einmal einloesbar (#336) - sofort markieren,
        // bevor das Token-Paar ausgestellt wird.
        $record->used = 1;
        $DB->update_record(self::CODE_TABLE, $record);

        return self::issue_token_pair($clientid, (int) $record->userid);
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

        $record = $DB->get_record(self::TOKEN_TABLE, ['refreshtoken' => $refreshtoken]);
        if (!$record || (int) $record->revoked === 1 || $record->refreshexpires < time()) {
            return null;
        }
        if ($record->clientid !== $clientid) {
            return null;
        }

        // Rotation: das eingeloeste Refresh-Token stirbt sofort, unabhaengig
        // vom neuen Paar - eine zweite Einloesung desselben Tokens (z. B.
        // durch einen kompromittierten Client) schlaegt danach fehl.
        $record->revoked = 1;
        $DB->update_record(self::TOKEN_TABLE, $record);

        return self::issue_token_pair($clientid, (int) $record->userid);
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
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string}
     */
    private static function issue_token_pair(string $clientid, int $userid): array {
        global $DB;

        $now = time();
        $record = new \stdClass();
        $record->accesstoken = self::random_token(32);
        $record->refreshtoken = self::random_token(32);
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->expires = $now + self::ACCESS_TOKEN_TTL;
        $record->refreshexpires = $now + self::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = $now;
        $DB->insert_record(self::TOKEN_TABLE, $record);

        return [
            'access_token' => $record->accesstoken,
            'token_type' => 'Bearer',
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'refresh_token' => $record->refreshtoken,
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

        $record = $DB->get_record(self::TOKEN_TABLE, ['accesstoken' => $accesstoken]);
        if (!$record || (int) $record->revoked === 1 || $record->expires < time()) {
            return null;
        }
        return (int) $record->userid;
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

        $count = $DB->count_records(self::TOKEN_TABLE, ['revoked' => 0]);
        $DB->set_field(self::TOKEN_TABLE, 'revoked', 1, ['revoked' => 0]);
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

        $conditions = ['id' => $id, 'revoked' => 0];
        if ($owneruserid !== null) {
            $conditions['userid'] = $owneruserid;
        }
        $record = $DB->get_record(self::TOKEN_TABLE, $conditions);
        if (!$record) {
            return false;
        }
        $record->revoked = 1;
        $DB->update_record(self::TOKEN_TABLE, $record);
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

        return $DB->get_records_sql(
            'SELECT t.id, t.clientid, t.userid, t.timecreated, t.expires, c.clientname,
                    u.firstname, u.lastname, u.email
               FROM {' . self::TOKEN_TABLE . '} t
          LEFT JOIN {' . self::CLIENT_TABLE . '} c ON c.clientid = t.clientid
          LEFT JOIN {user} u ON u.id = t.userid
              WHERE t.revoked = 0
           ORDER BY t.timecreated DESC'
        );
    }

    /**
     * JWKS-Dokument (#336): leer, aber valide. jwks_uri ist nur deklariert,
     * weil der OIDC-Namensraum es erzwingt (#302, Punkt 2) -
     * local_kurspilot ist kein OIDC-Provider und stellt keine signierten
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
     * @param array<string, string> $headers
     * @param array $body
     * @return array{status: int, headers: array<string, string>, body: array}
     */
    private static function result(int $status, array $headers, array $body): array {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
