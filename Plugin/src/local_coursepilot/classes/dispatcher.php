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

use core_external\external_api;

/**
 * Die Dispatcher-Seam (#334): dieselbe Entscheidungslogik, die vorher
 * prozedural in mcp.php lag - Origin-Pruefung, Discovery-Sonderfall,
 * Methodenpruefung, Parse-Fehler, Auth-Gate, Protokoll-Switch - jetzt als
 * reine(re) Funktion: Werte rein, Antwortwert (Status, Kopfzeilen, Rumpf)
 * raus. Kein exit, kein Zugriff auf HTTP-Superglobals ($_SERVER, php://input),
 * kein HTTP-Wissen - damit per PHPUnit ohne laufenden Webserver aufrufbar.
 *
 * Reste an Moodle-Bindung ($DB/$CFG, external_api::call_external_function())
 * bleiben bewusst hier statt injiziert - advanced_testcase bringt DB und
 * $USER bereits mit, eine Callback-Abstraktion waere unbenutzte Flexibilitaet
 * (YAGNI). Das sind Moodle-Framework-Globals, keine HTTP-Superglobals - die
 * Trennung, um die es in #334 geht. Die HTTP-Header-Extraktion selbst
 * (Authorization-Header lesen, REDIRECT_HTTP_AUTHORIZATION-Fallback,
 * getallheaders()) bleibt in mcp.php - das ist Ein-/Ausgabe, keine Entscheidung.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class dispatcher {

    /** @var string Protokoll-Revision der Legacy-Aera (initialize-Handshake). */
    public const LEGACY_VERSION = '2025-06-18';

    /** @var string Protokoll-Revision der modernen Aera (server/discover). */
    public const MODERN_VERSION = '2026-07-28';

    /**
     * Der Wegweiser (#451, Spec 0020 §2): ohne lokale Skill-Datei gibt es
     * keine description, an der ein frisch verbundener Client anspringt -
     * der Server setzt sie stattdessen selbst, identisch in initialize und
     * server/discover sowie als Hinweis in der coursepilot_list_skills-
     * Werkzeugbeschreibung (fuer Clients, die instructions nicht anzeigen).
     * Bewusst nur der Weg, nichts Fachliches - Auslöser und Verzweigung,
     * keine Planstrenge/Datenschutzregel/Werkzeugkunde, die gehoert hinter
     * get_skill.
     */
    public const HANDSHAKE_INSTRUCTIONS = 'Vor Planung oder Schreibzugriff zuerst coursepilot_list_skills aufrufen.';

    /** @var string[] Zusaetzlich erlaubte Origins neben $CFG->wwwroot. */
    private const EXTRA_ALLOWED_ORIGINS = ['https://claude.ai', 'https://chatgpt.com'];

    /**
     * Frischehinweis fuer Listenantworten in Millisekunden (fuenf Minuten).
     *
     * Gilt fuer alle vier Listen gleich - die Werkzeugliste dieses Plugins
     * aendert sich nur bei einem Plugin-Upgrade, die drei leeren Listen nie.
     */
    private const LIST_TTL_MS = 300000;

    /**
     * Die Seam: bearbeitet eine MCP-Anfrage vollstaendig und liefert das
     * Ergebnis als Wert zurueck statt es auszugeben.
     *
     * @param array|null $request Der bereits dekodierte JSON-Rumpf, oder
     *        null, wenn das Dekodieren fehlgeschlagen ist (Parse-Fehler-Fall).
     * @param string|null $token Das bereits aus dem Authorization-Header
     *        extrahierte Bearer-Token.
     * @param array{origin: ?string, pathinfo: ?string, method: ?string, protocolversion?: ?string} $headers
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    public static function handle(?array $request, ?string $token, array $headers): array {
        global $CFG;

        // Origin-Pruefung: greift nur bei vorhandenem Header (offener Punkt
        // aus #294 fuer die Pflege der Allowlist).
        $origin = $headers['origin'] ?? null;
        if ($origin !== null) {
            $allowed = array_merge([rtrim($CFG->wwwroot, '/')], self::EXTRA_ALLOWED_ORIGINS);
            if (!in_array(rtrim($origin, '/'), $allowed, true)) {
                // #339: eigener Aufruf, nicht ueber error() - dieser Zweig
                // liegt vor handle_authorized() und antwortet nicht im
                // JSON-RPC-Fehlerformat.
                access_log::log_failure('Origin not allowed');
                return self::result(403, [], ['error' => 'Origin not allowed']);
            }
        }
        $corsheaders = $origin !== null ? ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'] : [];

        // CORS-Preflight (#337-Nachtrag): der Custom Connector von Claude.ai
        // ruft mcp.php per Browser-fetch() mit Authorization-Header auf -
        // ohne Antwort auf den OPTIONS-Preflight blockt der Browser den
        // eigentlichen POST clientseitig, bevor er je hier ankommt (per
        // curl/Server-Log nicht sichtbar, nur am Verbindungsfehler auf
        // Claude-Seite erkennbar).
        if (($headers['method'] ?? 'POST') === 'OPTIONS') {
            return self::result(204, $corsheaders + [
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Max-Age' => '86400',
            ], null);
        }

        $result = self::handle_authorized($request, $token, $headers);
        $result['headers'] = $corsheaders + $result['headers'];
        return $result;
    }

    /**
     * Der eigentliche Anfrage-Ablauf nach Origin-Pruefung und CORS-Preflight
     * (#337-Nachtrag: aus handle() ausgelagert, damit CORS-Kopfzeilen an
     * genau einer Stelle auf jede Antwort angewendet werden, statt an jedem
     * einzelnen return).
     *
     * @param array|null $request
     * @param string|null $token
     * @param array{origin: ?string, pathinfo: ?string, method: ?string, protocolversion?: ?string} $headers
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function handle_authorized(?array $request, ?string $token, array $headers): array {
        global $CFG;

        // Globale Notbremse (#338): sperrt jeden weiteren MCP-Zugriff sofort,
        // unabhaengig von Token-Gueltigkeit oder Capability - deshalb vor
        // allem anderen geprueft. Ausgegebene Token bleiben dabei bestehen
        // (Unterschied zum Sammelwiderruf in oauth_lib::revoke_all_tokens()).
        // Rein eine Einstellung dieses einen Endpunkts - der normale
        // Moodle-Login (require_login() auf den uebrigen Seiten) ist davon
        // nicht betroffen. Standard (kein Konfigwert gesetzt) ist
        // eingeschaltet, deshalb der Vergleich auf explizit '0'.
        if ((string) get_config('local_coursepilot', 'remoteaccessenabled') === '0') {
            return self::error(403, $request['id'] ?? null, -32003, get_string('remoteaccessdisabled', 'local_coursepilot'));
        }

        // Protected-Resource-Metadaten am Ressourcenpfad selbst (RFC 9728,
        // Abschnitt 3) - Fund aus #312.
        $pathinfo = trim($headers['pathinfo'] ?? '', '/');
        if ($pathinfo === '.well-known/oauth-protected-resource') {
            return self::result(
                200,
                ['Cache-Control' => 'no-store'],
                oauth_lib::protected_resource_metadata($CFG->wwwroot)
            );
        }

        $method = $headers['method'] ?? 'POST';
        if ($method !== 'POST') {
            // #339: bewusst ungeloggt - kein JSON-RPC-Zugriffsversuch (kein
            // geparster Rumpf, kein Werkzeugbezug), sondern ein falsch
            // konfigurierter HTTP-Client.
            return self::result(405, ['Allow' => 'POST'], ['error' => 'Method Not Allowed - MCP over HTTP is POST only']);
        }

        if ($request === null) {
            return self::error(400, null, -32700, 'Parse error');
        }

        $id = $request['id'] ?? null;
        $rpcmethod = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        // Auth-Gate vor dem Handshake: erst ein 401 mit resource_metadata
        // bringt die Clients dazu, die Discovery-Kette ueberhaupt zu starten
        // (RFC 9728, #302).
        if (!self::authenticate($token)) {
            return self::error(401, $id, -32001, 'AUTHENTICATION_FAILED', [
                'WWW-Authenticate' => 'Bearer resource_metadata="'
                    . $CFG->wwwroot . '/local/coursepilot/oauth/protected-resource.php"',
            ]);
        }

        // Fernzugriffs-Notbremse (#296, #337): getrennt von local/coursepilot:use,
        // damit ein Admin den Fernzugriff systemweit sperren kann, ohne
        // einzelne Kurse anzufassen. Anders als der vage Auth-Fehler oben ist
        // dieser Fehler konkret - ein gueltiges Token allein reicht nicht.
        if (!has_capability('local/coursepilot:useremote', \context_system::instance())) {
            return self::error(403, $id, -32002, get_string('capabilitymissing', 'local_coursepilot', 'local/coursepilot:useremote'));
        }

        $serverinfo = ['name' => 'local_coursepilot', 'version' => '0.1.0'];

        switch ($rpcmethod) {
            // Legacy-Aera: Handshake.
            case 'initialize':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'protocolVersion' => $params['protocolVersion'] ?? self::LEGACY_VERSION,
                        'capabilities' => ['tools' => new \stdClass()],
                        'serverInfo' => $serverinfo,
                        'instructions' => self::HANDSHAKE_INSTRUCTIONS,
                    ] + self::resultmeta($headers, 'complete'),
                ]);

            case 'notifications/initialized':
            case 'notifications/cancelled':
                return self::result(202, [], null);

            case 'ping':
                // Leeres Ergebnisobjekt - in der Legacy-Aera bleibt es leer.
                // Dann muss es ein Objekt sein, kein leeres Array: json_encode
                // schriebe daraus "[]" statt "{}".
                $pingresult = self::resultmeta($headers, 'complete');
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $pingresult === [] ? new \stdClass() : $pingresult,
                ]);

            // Moderne Aera: Discovery statt Handshake.
            case 'server/discover':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'supportedVersions' => [self::MODERN_VERSION, self::LEGACY_VERSION],
                        'capabilities' => ['tools' => new \stdClass()],
                        'serverInfo' => $serverinfo,
                        'instructions' => self::HANDSHAKE_INSTRUCTIONS,
                    ] + self::resultmeta($headers, 'complete'),
                ]);

            case 'tools/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'tools' => self::tools(),
                        // 'data' (wie bei tools/call) ist fuer tools/list kein
                        // gueltiger Wert ("Unsupported result type 'data' for
                        // tools/list") - die Liste ist vollstaendig, nicht
                        // paginiert, also 'complete'.
                    ] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            case 'tools/call':
                return self::handle_tools_call($id, $params, $headers);

            // #401: leere Hoeflichkeitsantworten statt 404 - wir bieten weder
            // Resources noch Prompts an (deshalb keine capabilities.resources/
            // .prompts in initialize/server/discover), aber Codex fragt diese
            // drei Discovery-Methoden nach jedem Handshake unaufgefordert ab.
            case 'resources/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['resources' => []] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            case 'resources/templates/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['resourceTemplates' => []] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            case 'prompts/list':
                return self::result(200, [], [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => ['prompts' => []] + self::resultmeta($headers, 'complete', self::LIST_TTL_MS),
                ]);

            default:
                return self::error(404, $id, -32601, 'Method not found: ' . $rpcmethod);
        }
    }

    /**
     * tools/call: Laufzeitpruefung des Vertrags, dann der eigentliche
     * Aufruf ueber Moodles Webservice-Schicht (#295, Punkt 1).
     *
     * @param mixed $id
     * @param array $params
     * @param array{origin: ?string, pathinfo: ?string, method: ?string, protocolversion?: ?string} $headers
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function handle_tools_call($id, array $params, array $headers): array {
        $toolname = (string) ($params['name'] ?? '');
        $function = privacy_surface::function_for_tool($toolname);
        if ($function === null) {
            return self::error(404, $id, -32601, 'Unknown tool: ' . $toolname);
        }

        $response = external_api::call_external_function($function, $params['arguments'] ?? []);
        if ($response['error']) {
            $message = self::error_message($response['exception'] ?? null);
            access_log::log_failure($message, $toolname);
            return self::result(200, [], [
                'jsonrpc' => '2.0',
                'id' => $id,
                // Auch der Fehlerzweig braucht die Ergebnis-Metadaten (#466):
                // ohne 'resultType' verwirft ein 2026-07-28-Client die
                // Antwort als ungueltig und zeigt der Lehrkraft einen
                // Protokollfehler statt der Meldung des Werkzeugs. Ein
                // 'isError'-Ergebnis ist vollstaendig geliefert, also
                // 'complete' wie im Erfolgsfall.
                'result' => [
                    'isError' => true,
                    'content' => [['type' => 'text', 'text' => $message]],
                ] + self::resultmeta($headers, 'complete'),
            ]);
        }

        $data = $response['data'];
        // Materialvorgaenge nachvollziehbar machen (Spec 0018 §9.2): Werkzeuge,
        // die einen Kontext- oder Materialordner-Pfad zurueckgeben, liefern
        // ihn unter demselben Schluessel 'path' - kein Sonderfall je Werkzeug.
        $path = is_string($data['path'] ?? null) ? $data['path'] : null;
        access_log::log_success($toolname, tool_registry::is_write($toolname), $path);

        // Zweiter Inhaltstyp (Spec 0018 §3.2, Issue #430): ein Werkzeug wie
        // preview_material_file liefert 'image_base64'+'mimetype', der
        // Dispatcher haengt daraus einen MCP-Bildblock an - sonst bekaeme
        // das Modell nur eine Zeichenkette, keine Aufnahme, die es
        // tatsaechlich "sieht" (Scheinlösung, siehe Spec). Alle uebrigen
        // Werkzeuge setzen diese Schluessel nie, ihre Antwort bleibt damit
        // unveraendert Text plus structuredContent. Der Bildinhalt wird aus
        // der Text-/structuredContent-Kopie entfernt, um ihn nicht doppelt
        // durch den Kontext zu schicken.
        $content = [];
        $textdata = $data;
        $imagebase64 = $data['image_base64'] ?? null;
        $mimetype = $data['mimetype'] ?? null;
        if (is_string($imagebase64) && $imagebase64 !== '' && is_string($mimetype) && $mimetype !== '') {
            unset($textdata['image_base64']);
            $content[] = ['type' => 'image', 'data' => $imagebase64, 'mimeType' => $mimetype];
        }
        array_unshift($content, [
            'type' => 'text',
            // JSON_INVALID_UTF8_SUBSTITUTE: siehe mcp.php, derselbe
            // Fund - ohne das Flag liefert diese innere Kodierung
            // ebenfalls kommentarlos false bei ungueltigem UTF-8.
            'text' => json_encode($textdata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        return self::result(200, [], [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => $content,
                'structuredContent' => $textdata,
            ] + self::resultmeta($headers, 'complete'),
        ]);
    }

    /**
     * Der Fehlertext eines gescheiterten Werkzeugaufrufs.
     *
     * Bei invalid_parameter_exception ist ->message nur die generische
     * Moodle-Zeichenkette ("Ungueltiger Parameterwert") - die eigentliche,
     * fuer die Lehrkraft formulierte Meldung steht in debuginfo. Ohne
     * diesen Griff verwirft der Dispatcher jede Meldung, die die Werkzeuge
     * formulieren: "XML zu gross fuer den Server" und "Ungueltiges
     * Moodle-XML" kommen beim Client als derselbe nichtssagende Satz an,
     * und weder Lehrkraft noch KI koennen den Fehler beheben.
     *
     * Nur fuer genau diesen Fehlercode: debuginfo dieser Ausnahmeart ist
     * immer vom Aufrufer gesetzter Text (unsere eigene Meldung oder die
     * Parameterbeschreibung von validate_parameters()). Andere Ausnahmen -
     * allen voran dml_* mit SQL im debuginfo - bleiben bei ->message.
     *
     * @param \stdClass|null $exception Die Ausnahmeinfo aus
     *        external_api::call_external_function() (get_exception_info()).
     * @return string
     */
    private static function error_message(?\stdClass $exception): string {
        if ($exception === null) {
            return 'error';
        }
        // get_exception_info() haengt bei eingeschaltetem Entwickler-Debugging
        // eine Zeile "Error code: ..." an das debuginfo an - Rauschen fuer die
        // Lehrkraft, und es haengt an der Serverkonfiguration, ob sie da ist.
        $debuginfo = trim(preg_replace('/\n+Error code: \S+\s*$/', '', (string) ($exception->debuginfo ?? '')));
        if (($exception->errorcode ?? '') === 'invalidparameter' && $debuginfo !== '') {
            return $debuginfo;
        }
        return $exception->message ?? 'error';
    }

    /**
     * Ergebnis-Metadaten der Revision 2026-07-28 - und nur fuer diese.
     *
     * Beide Aeren verlangen das Gegenteil voneinander (#400): ein
     * 2026-07-28-Client (Claude Code, Claude.ai) verwirft eine Antwort ohne
     * diese Felder als ungueltig ("missing required resultType", danach
     * "expected number, received undefined" fuer ttlMs; cacheScope kennt nur
     * "public"/"private", "session" war ungueltig - #337-Nachtrag). Ein
     * 2025-06-18-Client (Codex ab 0.151, rmcp) verwirft umgekehrt jede
     * tools/call-Antwort, die 'resultType' enthaelt, mit "Unexpected response
     * type" - in seiner Revision gibt es das Feld nicht. Entschieden wird
     * an der ausgehandelten Revision aus dem MCP-Protocol-Version-Header,
     * den Clients laut Spezifikation nach dem Handshake bei jeder Anfrage
     * mitschicken; fehlt er, gilt die Legacy-Aera (kein Zusatzfeld) - das ist
     * die Variante, die kein Client aktiv ablehnt.
     *
     * "private" als cacheScope, weil personenbezogene Kursdaten der
     * aufrufenden Lehrkraft.
     *
     * #458 (Fund aus dem Abnahmelauf zu #456): 'data' war nie ein gueltiger
     * resultType - die Revision kennt fuer einen erfolgreichen Aufruf nur
     * 'complete', daneben 'input_required' fuer das MRTR-Muster, das wir nicht
     * anbieten. Und die Caching-Felder gehoeren laut Spezifikation an
     * tools/list, prompts/list, resources/list, resources/templates/list und
     * resources/read - also an die Listen, die wir bedienen -, nicht an
     * tools/call: ein ttlMs auf einem Schreibvorgang legt einem Client nahe,
     * ihn zu cachen. Deshalb ist $ttlms fuer tools/call null.
     *
     * #466: die Revision macht 'resultType' fuer JEDES Ergebnis zur Pflicht,
     * nicht nur fuer tools/call - jeder Zweig mit einem 'result' ruft das hier
     * auf. Der Ausloeser war ein einzelner vergessener Zweig (der Fehlerpfad
     * von tools/call), der jede Meldung dieses Servers unlesbar machte.
     * initialize und server/discover liegen vor der Aushandlung: fehlt der
     * Header dort, greift ohnehin die Legacy-Zeile oben.
     *
     * @param array{protocolversion?: ?string} $headers
     * @param string $resulttype 'complete' - der einzige Erfolgswert, den die
     *        Revision kennt, fuer jedes Ergebnis.
     * @param int|null $ttlms Freshness-Hinweis in Millisekunden - nur fuer
     *        Listenantworten. Null laesst die Caching-Felder ganz weg.
     * @return array<string, mixed> Leer ausserhalb der modernen Aera.
     */
    private static function resultmeta(array $headers, string $resulttype, ?int $ttlms = null): array {
        if (($headers['protocolversion'] ?? null) !== self::MODERN_VERSION) {
            return [];
        }
        $meta = ['resultType' => $resulttype];
        if ($ttlms !== null) {
            $meta['ttlMs'] = $ttlms;
            $meta['cacheScope'] = 'private';
        }
        return $meta;
    }

    /**
     * Bildet ein Bearer-Token auf einen Moodle-Nutzer ab und richtet $USER
     * ein (#337).
     *
     * Akzeptiert ausschliesslich OAuth-Access-Token aus
     * {@see oauth_lib::authenticate_access_token()} - die fruehere
     * Webservice-Token-Kruecke (external_tokens) aus dem Prototypen entfaellt
     * vollstaendig, der OAuth-2.1-Autorisierungsserver (#335/#336) ist ihr
     * einziger Ersatz.
     *
     * @param string|null $token
     * @return bool
     */
    private static function authenticate(?string $token): bool {
        global $DB, $USER;

        if ($token === null) {
            return false;
        }
        $userid = oauth_lib::authenticate_access_token($token);
        if ($userid === null) {
            return false;
        }
        $usr = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$usr) {
            return false;
        }

        \core\session\manager::set_user($usr);
        // Bearer-Token-Auth ist zustandslos (kein Cookie/Session-Vertrauen,
        // jeder POST validiert das Token neu, #337/Spec 0012 Abschnitt 3) -
        // die CSRF-Abwehr per sesskey adressiert ein Cookie-Session-Risiko
        // (Ambient Authority ueber Browser-Cookies), das hier nicht existiert:
        // ein Angreifer kann den Authorization-Header nicht faelschen lassen.
        // Noetig, weil external_api::call_external_function() sesskey nur
        // dann uebergeht, wenn WS_SERVER=true ist (mcp.php setzt das vor dem
        // Moodle-Bootstrap) - in PHPUnit ist die Konstante zu diesem
        // Zeitpunkt bereits unveraenderlich auf false gesetzt, siehe
        // dispatcher_test.php.
        $USER->ignoresesskey = true;
        external_api::set_context_restriction(\context_system::instance());
        return true;
    }

    /**
     * Die Werkzeugliste - direkt aus der Allowlist abgeleitet, damit
     * gelistet und aufrufbar dieselbe Menge sind.
     *
     * @return array
     */
    private static function tools(): array {
        $descriptions = tool_registry::descriptions();
        $schemas = tool_registry::schemas();
        $tools = [];
        foreach (array_keys(privacy_surface::allowed_tools()) as $name) {
            $schema = $schemas[$name] ?? null;
            $inputschema = [
                'type' => 'object',
                'properties' => $schema ? $schema['properties'] : new \stdClass(),
                'additionalProperties' => false,
            ];
            if ($schema && !empty($schema['required'])) {
                $inputschema['required'] = $schema['required'];
            }
            $tools[] = [
                'name' => $name,
                'description' => $descriptions[$name] ?? '',
                'inputSchema' => $inputschema,
            ];
        }
        return $tools;
    }

    /**
     * @param mixed $id
     * @param array<string, string> $extraheaders
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function error(int $status, $id, int $code, string $message, array $extraheaders = []): array {
        // Zentraler Funnelpunkt fuer jede JSON-RPC-Fehlerantwort (#339): Auth-
        // Gate, Capability-Gate, Notbremse, Parse-Fehler, unbekannte
        // Methode/Werkzeug laufen alle hier durch (Origin-Ablehnung und
        // Method-Not-Allowed antworten NICHT im JSON-RPC-Format und damit
        // nicht ueber error() - Origin-Ablehnung hat einen eigenen
        // access_log::log_failure()-Aufruf in handle(), 405 bleibt bewusst
        // ungeloggt, siehe Kommentar dort). $message ist stets ein fester
        // Text/Code, nie das Zugriffstoken (das taucht an keiner Stelle des
        // Aufrufpfads in einer Fehlermeldung auf).
        access_log::log_failure($message);
        return self::result($status, $extraheaders, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }

    /**
     * @param array<string, string> $headers
     * @param array|null $body
     * @return array{status: int, headers: array<string, string>, body: array|null}
     */
    private static function result(int $status, array $headers, ?array $body): array {
        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }
}
