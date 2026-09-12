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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * OAuth-Discovery und DCR/CIMD-Registrierung (#335), Seam-Muster wie #334:
 * ohne laufenden Webserver per PHPUnit gegen die reinen Handler-Methoden
 * pruefbar.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(oauth_lib::class)]
final class oauth_lib_test extends \advanced_testcase {

    private const WWWROOT = 'https://kurspilot.example';

    /**
     * Discovery liefert unter beiden bekannten Namen dieselben, echten
     * Metadaten - kein Dummy mehr, registration_endpoint ist ein echter
     * Endpunkt.
     */
    public function test_discovery_serves_both_wellknown_names(): void {
        $oidc = oauth_lib::handle_discovery(self::WWWROOT, '.well-known/openid-configuration');
        $oauth = oauth_lib::handle_discovery(self::WWWROOT, '.well-known/oauth-authorization-server');

        $this->assertSame(200, $oidc['status']);
        $this->assertSame(200, $oauth['status']);
        $this->assertSame($oidc['body'], $oauth['body']);
        $this->assertSame(
            self::WWWROOT . '/local/kurspilot/oauth/register.php',
            $oidc['body']['registration_endpoint']
        );
    }

    /**
     * Unbekannte PATH_INFO-Werte unter oauth.php sind ein Irrlaeufer -
     * 404 als JSON, kein Rewrite noetig.
     */
    public function test_discovery_rejects_unknown_path(): void {
        $response = oauth_lib::handle_discovery(self::WWWROOT, 'nonsense');

        $this->assertSame(404, $response['status']);
        $this->assertSame('not_found', $response['body']['error']);
    }

    /**
     * Protected-Resource-Metadaten sind unter beiden Adressen identisch -
     * dieselbe Quelle wird von oauth/protected-resource.php und von
     * dispatcher::handle() (PATH_INFO auf mcp.php) aufgerufen.
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
     * Eine gueltige Registrierung legt einen Client an und gibt die Kennung
     * samt Metadaten zurueck (RFC 7591).
     */
    public function test_register_client_creates_client_and_returns_metadata(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', [
            'client_name' => 'Testclient',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);

        $this->assertSame(201, $response['status']);
        $this->assertNotEmpty($response['body']['client_id']);
        $this->assertSame('Testclient', $response['body']['client_name']);
        $this->assertSame(['https://claude.ai/api/mcp/auth_callback'], $response['body']['redirect_uris']);

        $client = oauth_lib::get_client($response['body']['client_id']);
        $this->assertNotNull($client, 'Client muss in der DB angelegt sein.');
        $this->assertSame('dcr', $client->source);
    }

    /**
     * Ein nicht erlaubtes Umleitungsziel (kein https, kein Loopback) wird
     * abgewiesen.
     */
    public function test_register_client_rejects_disallowed_redirect_uri(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', [
            'redirect_uris' => ['http://evil.example/callback'],
        ]);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_redirect_uri', $response['body']['error']);
    }

    /**
     * Loopback-Redirect-URIs sind erlaubt (RFC 8252, native/CLI-Clients).
     */
    public function test_register_client_allows_loopback_redirect_uri(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', [
            'redirect_uris' => ['http://127.0.0.1:51000/callback'],
        ]);

        $this->assertSame(201, $response['status']);
    }

    /**
     * Fehlende redirect_uris sind ein Registrierungsfehler, nicht eine
     * PHP-Warnung.
     */
    public function test_register_client_requires_redirect_uris(): void {
        $this->resetAfterTest();

        $response = oauth_lib::handle_registration('POST', []);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_client_metadata', $response['body']['error']);
    }

    /**
     * Nur POST ist erlaubt - alles andere ist ein Registrierungsfehler,
     * JSON, kein HTML.
     */
    public function test_registration_rejects_non_post_method(): void {
        $response = oauth_lib::handle_registration('GET', []);

        $this->assertSame(405, $response['status']);
        $this->assertIsArray($response['body']);
    }

    /**
     * Ungueltiges JSON im Registrierungsrumpf ist ein Fehler, keine
     * PHP-Exception.
     */
    public function test_registration_rejects_invalid_json(): void {
        $response = oauth_lib::handle_registration('POST', null);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_client_metadata', $response['body']['error']);
    }

    /**
     * Fehlerantworten aller Handler sind JSON-faehige Arrays, nie HTML.
     */
    public function test_error_responses_are_json_not_html(): void {
        $this->resetAfterTest();

        $responses = [
            oauth_lib::handle_discovery(self::WWWROOT, 'nonsense'),
            oauth_lib::handle_registration('GET', []),
            oauth_lib::handle_registration('POST', null),
            oauth_lib::handle_registration('POST', ['redirect_uris' => ['not a uri']]),
        ];

        foreach ($responses as $response) {
            $encoded = json_encode($response['body']);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString('<html', strtolower($encoded));
        }
    }

    /**
     * CIMD: eine https-URL als client_id ohne lokalen DCR-Datensatz wird
     * als Metadaten-Dokument abgerufen. Eine unbekannte, nicht wie eine URL
     * aussehende client_id liefert null, keine Exception.
     */
    public function test_get_client_returns_null_for_unknown_non_url_clientid(): void {
        $this->resetAfterTest();

        $this->assertNull(oauth_lib::get_client('not-a-registered-client'));
    }

    /**
     * CIMD-Erfolgsfall: ein gueltiges, bereits dekodiertes Dokument legt
     * einen Client an, dessen clientid die URL selbst ist - netzwerkfrei
     * pruefbar (#335), die HTTP-Abholung selbst bleibt in
     * fetch_and_cache_cimd_client() und ist eine duenne Schale darum.
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

        // get_client() findet den gecachten Datensatz danach direkt in der DB.
        $found = oauth_lib::get_client($url);
        $this->assertNotNull($found);
        $this->assertSame($url, $found->clientid);
    }

    /**
     * CIMD-Dokument mit nicht erlaubtem Umleitungsziel wird abgewiesen,
     * genau wie bei der DCR-Registrierung.
     */
    public function test_cache_cimd_client_rejects_disallowed_redirect_uri(): void {
        $this->resetAfterTest();

        $record = oauth_lib::cache_cimd_client('https://client.example/cimd.json', [
            'redirect_uris' => ['http://evil.example/callback'],
        ]);

        $this->assertNull($record);
    }

    /**
     * CIMD-Dokument ohne redirect_uris ist ungueltig.
     */
    public function test_cache_cimd_client_rejects_missing_redirect_uris(): void {
        $this->resetAfterTest();

        $this->assertNull(oauth_lib::cache_cimd_client('https://client.example/cimd.json', []));
    }

    /**
     * is_allowed_redirect_uri: die Grenzfaelle direkt geprueft.
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
     * Registriert einen Testclient und liefert ihn zusammen mit einer
     * PKCE-Verifier/Challenge-Paarung zurueck - gemeinsamer Aufbau fuer die
     * Autorisierungs-/Token-Tests unten.
     *
     * @return array{clientid: string, redirecturi: string, verifier: string, challenge: string}
     */
    private function registered_client_with_pkce(): array {
        $response = oauth_lib::handle_registration('POST', [
            'client_name' => 'Testclient',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
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
     * validate_authorize_request(): der Erfolgsfall liefert den Client, kein
     * Fehlerfeld.
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
     * PKCE ist Pflicht: fehlende code_challenge wird abgewiesen (#336).
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
    }

    /**
     * Nur S256 wird akzeptiert - plain (oder jede andere Methode) ist ein
     * Fehler, nicht nur eine Warnung (#336).
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
    }

    /**
     * Ein nicht registriertes Umleitungsziel wird abgewiesen, auch wenn
     * Client und PKCE sonst gueltig sind (#336).
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
    }

    /**
     * Unbekannter Client ist ein eigener Fehlercode (invalid_client), nicht
     * dasselbe invalid_request wie bei den anderen Feldpruefungen.
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
    }

    /**
     * Ablehnung im Zustimmungsdialog: das Umleitungsziel traegt
     * error=access_denied und state - eine saubere Fehlerantwort, kein
     * Autorisierungscode (#336).
     */
    public function test_denial_redirect_url_carries_access_denied_and_state(): void {
        $url = oauth_lib::denial_redirect_url('https://claude.ai/callback', 'xyz');

        $this->assertStringStartsWith('https://claude.ai/callback?', $url);
        $this->assertStringContainsString('error=access_denied', $url);
        $this->assertStringContainsString('state=xyz', $url);
        $this->assertStringNotContainsString('code=', $url);
    }

    /**
     * Voller Roundtrip: Code ausstellen, mit korrektem Verifier einloesen -
     * liefert ein Zugriffstoken mit 1h Laufzeit und ein Erneuerungstoken.
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
    }

    /**
     * Ein Autorisierungscode ist genau einmal einloesbar - die zweite
     * Einloesung schlaegt fehl (#336).
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
     * Ein falscher PKCE-Code-Verifier scheitert - der Code ist damit noch
     * nicht verbraucht.
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
     * redirect_uri-Mismatch beim Einloesen scheitert (RFC 6749, 4.1.3).
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
     * Refresh-Rotation: ein neues Erneuerungstoken wird ausgestellt, das
     * alte ist danach entwertet - eine zweite Einloesung des alten Tokens
     * schlaegt fehl (#336).
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

        // Das alte Refresh-Token ist tot.
        $reuse = oauth_lib::rotate_refresh_token($original['refresh_token'], $fixture['clientid']);
        $this->assertNull($reuse);
    }

    /**
     * Ein Client-Mismatch beim Refresh (fremder Client versucht, ein
     * Refresh-Token eines anderen einzuloesen) scheitert.
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
     * Ein unbekanntes Refresh-Token scheitert, keine Exception.
     */
    public function test_rotate_refresh_token_rejects_unknown_token(): void {
        $this->resetAfterTest();

        $this->assertNull(oauth_lib::rotate_refresh_token('nie-ausgestellt', 'irgendein-client'));
    }

    /**
     * Das Schluesselendpunkt-Dokument ist valide (leeres, aber gueltiges
     * JWKS - #336).
     */
    public function test_jwks_document_is_valid_empty_keyset(): void {
        $document = oauth_lib::jwks_document();

        $this->assertSame(['keys' => []], $document);
        $this->assertIsString(json_encode($document));
    }

    /**
     * handle_token(): Schalenmuster-Handler fuer oauth/token.php - der volle
     * Authorization-Code-Roundtrip ist ohne laufenden Webserver pruefbar
     * (#336-Review: Entscheidungslogik gehoert in oauth_lib, nicht in die
     * Schale).
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
     * Nur POST ist erlaubt.
     */
    public function test_handle_token_rejects_non_post_method(): void {
        $response = oauth_lib::handle_token('GET', []);

        $this->assertSame(405, $response['status']);
        $this->assertSame('invalid_request', $response['body']['error']);
    }

    /**
     * Ein Parse-Fehler im Rumpf (null statt Array) ist ein Fehler, keine
     * PHP-Warnung.
     */
    public function test_handle_token_rejects_missing_body(): void {
        $response = oauth_lib::handle_token('POST', null);

        $this->assertSame(400, $response['status']);
        $this->assertSame('invalid_request', $response['body']['error']);
    }

    /**
     * Unbekannter Client ist invalid_client, nicht invalid_grant.
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
     * Unbekannter grant_type ist unsupported_grant_type.
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
     * handle_token() traegt auch die Refresh-Rotation (RFC 6749
     * grant_type=refresh_token).
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
     * Legt direkt einen Token-Datensatz an - der einfachste Weg zu einem
     * pruefbaren Token, ohne den vollen DCR/PKCE-Roundtrip nachzustellen
     * (analog zu dispatcher_test::issue_access_token()).
     *
     * @param int $userid
     * @param string $clientid
     * @return \stdClass Der vollstaendige Token-Datensatz inkl. id.
     */
    private function issue_token(int $userid, string $clientid = 'test-client'): \stdClass {
        global $DB;

        $record = new \stdClass();
        $record->accesstoken = oauth_lib::random_token(32);
        $record->refreshtoken = oauth_lib::random_token(32);
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->expires = time() + oauth_lib::ACCESS_TOKEN_TTL;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = time();
        $record->id = $DB->insert_record('local_kurspilot_oauth_token', $record);
        return $record;
    }

    /**
     * Sammelwiderruf (#338): entwertet alle noch aktiven Token, ueber
     * Personen und Clients hinweg, und liefert die Anzahl zurueck.
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
     * Ein zweiter Sammelwiderruf entwertet nichts mehr - die Anzahl der
     * (weiteren) widerrufenen Token ist danach 0, keine Fehlermeldung.
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
     * Einzelwiderruf ohne Eigentuemerfilter (Administration): widerruft
     * jedes Token, unabhaengig davon, wem es gehoert.
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
     * Einzelwiderruf mit Eigentuemerfilter (Selbstverwaltung): eine Person
     * kann das eigene Token widerrufen - der Zugriff schlaegt danach fehl
     * (#338, Abnahmekriterium: Zugriff mit altem Token scheitert).
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
     * Einzelwiderruf mit Eigentuemerfilter scheitert an einem fremden Token
     * - die Person kann fremde Verbindungen nicht widerrufen, das Token
     * bleibt gueltig (#338, Abnahmekriterium: nie fremde Verbindungen).
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
     * Eine unbekannte Token-ID liefert false, keine Exception.
     */
    public function test_revoke_token_returns_false_for_unknown_id(): void {
        $this->resetAfterTest();

        $this->assertFalse(oauth_lib::revoke_token(999999));
    }

    /**
     * Selbstverwaltungsseite: eine Person sieht ausschliesslich die eigenen
     * aktiven Verbindungen, nie die einer anderen Person (#338).
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
     * Widerrufene Token erscheinen nicht mehr in der Selbstverwaltungsseite.
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
     * Administrationsuebersicht (#338): alle aktiven Verbindungen ueber
     * alle Personen hinweg, mit Personendaten fuer die Anzeige.
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
     * Der gewaehlte Ablageort haengt an der Pointer-Datei im Anker
     * (storage_anchor), nicht an einem Token: Tokenrotation, eine zweite
     * Verbindung eines anderen Clients und der Sammelwiderruf aller
     * Verbindungen fassen keine der beiden an. Bereits mit #446 belegt,
     * beim Umbau der Ortswahl auf die eigene Seite (#494) verloren gegangen
     * (Issue #509) - hier mit dem heutigen Schreibweg
     * (storage_anchor::write_pointer_document()) wiederhergestellt.
     */
    public function test_storage_location_survives_rotation_second_client_and_mass_revocation(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        global $USER;

        storage_anchor::write_pointer_document([
            'kontextbereich' => 'mein-ort',
            'materialordner' => 'mein-material',
        ]);
        $assertlocationunchanged = function (): void {
            $this->assertSame('/mein-ort/', context_files::resolve_directory(''));
            $this->assertSame('/mein-material/', storage_anchor::resolve_pointer_location(material_files::area())->path);
        };
        $assertlocationunchanged();

        // Tokenrotation.
        $fixture = $this->registered_client_with_pkce();
        $code = oauth_lib::issue_code($fixture['clientid'], (int) $USER->id, $fixture['redirecturi'], $fixture['challenge']);
        $tokens = oauth_lib::exchange_code($code, $fixture['clientid'], $fixture['redirecturi'], $fixture['verifier']);
        oauth_lib::rotate_refresh_token($tokens['refresh_token'], $fixture['clientid']);
        $assertlocationunchanged();

        // Zweite Verbindung, anderer Client.
        $this->issue_token((int) $USER->id, 'ein-zweiter-client');
        $assertlocationunchanged();

        // Sammelwiderruf aller Verbindungen.
        oauth_lib::revoke_all_tokens();
        $assertlocationunchanged();
    }

}
