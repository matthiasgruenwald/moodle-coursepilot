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
 * Dispatcher-Seam (#334): dieselbe Entscheidungslogik wie bisher in mcp.php,
 * jetzt ohne exit/Superglobals per PHPUnit ohne laufenden Webserver
 * aufrufbar.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(dispatcher::class)]
final class dispatcher_test extends \advanced_testcase {

    /**
     * Legt einen Nutzer mit gueltigem OAuth-Access-Token an (#337) - ersetzt
     * die fruehere Webservice-Token-Kruecke. Bekommt standardmaessig
     * local/coursepilot:useremote (Archetyp-Default fuer editingteacher, siehe
     * db/access.php), damit bestehende Tests ohne Aenderung weiterlaufen;
     * $withremote = false simuliert den entzogenen Fernzugriff.
     *
     * @param bool $withremote
     * @return array{0: \stdClass, 1: string} Nutzer und Access-Token.
     */
    private function create_authenticated_user(bool $withremote = true): array {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        if (!$withremote) {
            $roleid = $this->get_role_id('editingteacher');
            assign_capability(
                'local/coursepilot:useremote',
                CAP_PROHIBIT,
                $roleid,
                \context_system::instance()->id,
                true
            );
        }
        $token = $this->issue_access_token($user->id);
        return [$user, $token];
    }

    /**
     * Legt direkt einen OAuth-Access-Token-Datensatz an - der einfachste Weg
     * zum echten Authentifizierungspfad, ohne den vollen DCR/PKCE-Roundtrip
     * (#336) fuer jeden Test nachzustellen.
     *
     * @param int $userid
     * @param int $expiresoffset Sekunden relativ zu jetzt (negativ = abgelaufen).
     * @param bool $revoked
     * @return string Das Access-Token.
     */
    private function issue_access_token(int $userid, int $expiresoffset = 3600, bool $revoked = false): string {
        global $DB;

        $record = new \stdClass();
        $record->accesstoken = oauth_lib::random_token(32);
        $record->refreshtoken = oauth_lib::random_token(32);
        $record->clientid = 'test-client';
        $record->userid = $userid;
        $record->expires = time() + $expiresoffset;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
        $record->revoked = $revoked ? 1 : 0;
        $record->timecreated = time();
        $DB->insert_record('local_coursepilot_oauth_token', $record);

        return $record->accesstoken;
    }

    /**
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * @return array{origin: null, pathinfo: string, method: string}
     */
    private function headers(array $overrides = []): array {
        return array_merge(['origin' => null, 'pathinfo' => '', 'method' => 'POST'], $overrides);
    }

    /**
     * Auth-Gate greift vor dem Handshake: initialize unauthentifiziert
     * liefert 401, nicht die Serverinfo.
     */
    public function test_initialize_without_token_returns_401(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], null, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertSame(-32001, $response['body']['error']['code']);
        $this->assertArrayNotHasKey('result', $response['body']);
    }

    /**
     * Legacy-Aera: initialize liefert die Serverinfo nach gueltiger Auth.
     */
    public function test_initialize_returns_serverinfo_when_authenticated(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame('local_coursepilot', $response['body']['result']['serverInfo']['name']);
    }

    /**
     * Moderne Aera: server/discover wird ebenfalls bedient.
     */
    public function test_server_discover_returns_supported_versions(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'server/discover'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertContains(
            dispatcher::MODERN_VERSION,
            $response['body']['result']['supportedVersions']
        );
        $this->assertContains(
            dispatcher::LEGACY_VERSION,
            $response['body']['result']['supportedVersions']
        );
    }

    /**
     * #451, Akzeptanzkriterium: beide Handshake-Wege liefern denselben
     * Wegweiser als 'instructions' - ohne lokale Skill-Datei gibt es keine
     * description, an der ein frisch verbundener Client anspringt.
     */
    public function test_initialize_and_server_discover_return_identical_instructions(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $initialize = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());
        $discover = dispatcher::handle(['id' => 2, 'method' => 'server/discover'], $token, $this->headers());

        $this->assertStringContainsString(
            'coursepilot_list_skills',
            $initialize['body']['result']['instructions'],
            'Der Wegweiser muss coursepilot_list_skills namentlich nennen.'
        );
        $this->assertSame(
            $initialize['body']['result']['instructions'],
            $discover['body']['result']['instructions']
        );
    }

    /**
     * #451, Akzeptanzkriterium: die Werkzeugbeschreibung von
     * coursepilot_list_skills traegt denselben Hinweis wie 'instructions' -
     * fuer Clients, die instructions nicht anzeigen.
     */
    public function test_list_skills_tool_description_carries_the_same_hint(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());

        $tools = array_column($response['body']['result']['tools'], 'description', 'name');
        $this->assertStringContainsString(
            dispatcher::HANDSHAKE_INSTRUCTIONS,
            $tools['coursepilot_list_skills']
        );
    }

    /**
     * tools/list leitet sich aus der Allowlist ab.
     */
    public function test_tools_list_is_derived_from_allowlist(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());

        $names = array_column($response['body']['result']['tools'], 'name');
        $this->assertSame(array_keys(privacy_surface::allowed_tools()), $names);
    }

    /**
     * #342, Akzeptanzkriterium: jede Werkzeugbeschreibung bleibt unter 2 KB
     * - generisch ueber alle gelisteten Werkzeuge, nicht nur die neuen fuenf.
     */
    public function test_tool_descriptions_stay_under_2kb(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());

        foreach ($response['body']['result']['tools'] as $tool) {
            $bytes = strlen($tool['description']);
            $this->assertLessThan(2048, $bytes, $tool['name'] . ': Beschreibung ist ' . $bytes . ' Bytes lang.');
        }
    }

    /**
     * Jede MCP-Schemadeklaration ist die von Moodle validierte
     * execute_parameters()-Beschreibung, nicht eine gepflegte Stichprobe.
     */
    public function test_tools_list_schemas_match_external_parameters(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers());
        $tools = [];
        foreach ($response['body']['result']['tools'] as $tool) {
            $tools[$tool['name']] = $tool['inputSchema'];
        }

        $functions = tool_registry::service_functions();
        foreach (tool_registry::allowed_tools() as $name => $function) {
            $classname = $functions[$function]['classname'];
            $expected = ['type' => 'object']
                + external_schema_converter::from_parameters($classname::execute_parameters())
                + ['additionalProperties' => false];
            $this->assertEquals($expected, $tools[$name], "{$name}: tools/list-Schema weicht ab.");
        }
    }

    /**
     * resultType ist fuer die Revision 2026-07-28 Pflicht (#337-Nachtrag,
     * Fund aus dem Claude-Code-Livetest: ohne dieses Feld verwirft ein
     * 2026-07-28-Client die tools/list-Antwort als ungueltig).
     */
    public function test_tools_list_includes_result_type(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'tools/list'], $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));

        $this->assertSame('complete', $response['body']['result']['resultType']);
        $this->assertIsInt($response['body']['result']['ttlMs']);
        $this->assertSame('private', $response['body']['result']['cacheScope']);
    }

    /**
     * coursepilot_get_course_catalog ist per tools/call tatsaechlich aufrufbar,
     * nicht nur gelistet (#341).
     */
    public function test_course_catalog_tool_is_callable(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_get_course_catalog',
                    'arguments' => ['courseid' => $course->id],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame((int) $course->id, $response['body']['result']['structuredContent']['courseid']);
        $this->assertSame('aus Moodle gelesen', $response['body']['result']['structuredContent']['source']);
    }

    /**
     * Legt eine Bilddatei im Materialordner des uebergebenen Nutzers an -
     * per GD erzeugt statt aus einer Fixture-Datei geladen, damit der Test
     * keine Binaerdatei mitfuehren muss. Breiter als 768px, damit die
     * Vorschau tatsaechlich verkleinert.
     *
     * @param \stdClass $user
     * @param string $filename
     * @return void
     */
    private function store_material_image(\stdClass $user, string $filename): void {
        $image = imagecreatetruecolor(1600, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 0, 0));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $png);
    }

    /**
     * Spec 0018 §3.2/Issue #430: der Dispatcher haengt an eine erfolgreiche
     * Bildvorschau einen zweiten MCP-Inhaltsblock (type "image") an - base64
     * plus mimeType, kein Umweg ueber eine Zeichenkette im JSON.
     */
    public function test_preview_material_file_returns_mcp_image_content_block(): void {
        $this->resetAfterTest();
        [$user, $token] = $this->create_authenticated_user();
        $this->store_material_image($user, 'bild.png');

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_preview_material_file',
                    'arguments' => ['path' => 'bild.png'],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $content = $response['body']['result']['content'];
        $this->assertCount(2, $content, 'Text- plus Bildblock erwartet.');
        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('image', $content[1]['type']);
        $this->assertSame('image/jpeg', $content[1]['mimeType']);

        $decoded = base64_decode($content[1]['data'], true);
        $this->assertNotFalse($decoded, 'Bilddaten muessen gueltiges base64 sein.');
        $info = getimagesizefromstring($decoded);
        $this->assertNotFalse($info, 'Bilddaten muessen ein von PHP lesbares Bild ergeben.');
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertLessThanOrEqual(768, max($info[0], $info[1]));

        // Der Bild-Byte-Blob wird nicht doppelt durch den Kontext geschickt -
        // weder im JSON-Textblock noch in structuredContent.
        $this->assertArrayNotHasKey('image_base64', $response['body']['result']['structuredContent']);
        $decodedtext = json_decode($content[0]['text'], true);
        $this->assertArrayNotHasKey('image_base64', $decodedtext);
    }

    /**
     * Spec 0018 §3: eine Nicht-Bilddatei ist kein Fehler - "available":
     * false mit erklaerender Meldung, weiterhin genau ein Textblock (kein
     * Bildblock).
     */
    public function test_preview_of_non_image_file_returns_message_not_error(): void {
        $this->resetAfterTest();
        [$user, $token] = $this->create_authenticated_user();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], '%PDF-1.4 kein echtes PDF, reicht fuer den Test');

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_preview_material_file',
                    'arguments' => ['path' => 'blatt.pdf'],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('isError', $response['body']['result']);
        $this->assertFalse($response['body']['result']['structuredContent']['available']);
        $this->assertNotEmpty($response['body']['result']['structuredContent']['message']);
        $this->assertCount(1, $response['body']['result']['content'], 'Kein Bildblock ohne Bildvorschau.');
    }

    /**
     * Der zweite Inhaltstyp (#430) bleibt die einzige Erweiterung am
     * Dispatcher (Spec 0018 §3.2) - ein Werkzeug ohne Bildfelder liefert
     * unveraendert genau einen Textblock mit JSON plus structuredContent.
     */
    public function test_tools_without_image_fields_keep_single_text_content_block(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $result = $response['body']['result'];
        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertArrayHasKey('structuredContent', $result);
        $decoded = json_decode($result['content'][0]['text'], true);
        $this->assertSame($result['structuredContent'], $decoded);
    }

    /**
     * Der Fehlertext eines Werkzeugs erreicht den Aufrufer im Klartext.
     *
     * invalid_parameter_exception traegt die eigentliche Meldung in
     * debuginfo; ->message ist nur die generische Moodle-Zeichenkette
     * ("Ungueltiger Parameterwert"). Wer nur ->message weitergibt, verwirft
     * damit jede Meldung, die die Werkzeuge dieses Plugins formulieren -
     * "Datei zu gross" und "XML kaputt" kommen beim Client als derselbe
     * nichtssagende Satz an.
     */
    public function test_tool_error_detail_reaches_the_caller(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            [
                'id' => 1,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'coursepilot_export_questions_xml',
                    'arguments' => ['questionids' => []],
                ],
            ],
            $token,
            $this->headers()
        );

        $this->assertTrue($response['body']['result']['isError']);
        $this->assertSame(
            'Es muss mindestens eine questionid angegeben werden.',
            $response['body']['result']['content'][0]['text']
        );
    }

    /**
     * Die Regel, nicht der Einzelfall (#466): JEDE Antwort mit einem
     * 'result' traegt in der Revision 2026-07-28 ein 'resultType' - die
     * Revision macht das Feld fuer alle Ergebnisse zur Pflicht, nicht nur
     * fuer tools/call.
     *
     * Die Methodenliste wird aus den case-Labels des Dispatchers abgeleitet,
     * nicht von Hand gepflegt: der Ausloeser dieser Runde war ein einzelner
     * vergessener Zweig (der Fehlerpfad von tools/call), und eine handgefuehrte
     * Liste haette denselben Fehler ein zweites Mal zugelassen. Ein neuer
     * Zweig ist damit automatisch mitgetestet - wer ihn hinzufuegt, muss ihn
     * entweder korrekt beantworten oder bewusst unter die result-losen
     * Methoden eintragen.
     *
     * Legacy-Aera bleibt spiegelbildlich feldlos: ein 2025-06-18-Client
     * verwirft eine Antwort MIT diesen Feldern (#400).
     */
    public function test_every_result_carries_resulttype_in_the_modern_era(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        foreach ($this->result_bearing_methods() as $method) {
            $request = ['id' => 1, 'method' => $method] + $this->arguments_for($method);

            $modern = dispatcher::handle($request, $token, $this->headers([
                'protocolversion' => dispatcher::MODERN_VERSION,
            ]));
            $legacy = dispatcher::handle($request, $token, $this->headers([
                'protocolversion' => dispatcher::LEGACY_VERSION,
            ]));

            $this->assertSame('complete', ((array) $modern['body']['result'])['resultType'] ?? null, $method);
            $this->assertArrayNotHasKey('resultType', (array) $legacy['body']['result'], $method);
        }
    }

    /**
     * Auch der Fehlerzweig von tools/call - er war der Ausloeser (#466) und
     * ist der einzige result-Zweig, den die abgeleitete Liste oben nicht
     * erreicht: sie ruft jede Methode auf ihrem Erfolgsweg auf.
     */
    public function test_tool_error_result_carries_resulttype(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $request = [
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'coursepilot_export_questions_xml',
                'arguments' => ['questionids' => []],
            ],
        ];

        $modern = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));
        $legacy = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));

        $this->assertTrue($modern['body']['result']['isError']);
        $this->assertTrue($legacy['body']['result']['isError']);
        $this->assertSame('complete', $modern['body']['result']['resultType']);
        $this->assertArrayNotHasKey('resultType', $legacy['body']['result']);
        // Caching-Felder gehoeren nicht an tools/call (#458) - auch nicht an
        // die Fehlerantwort.
        $this->assertArrayNotHasKey('ttlMs', $modern['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $modern['body']['result']);
    }

    /**
     * Die JSON-RPC-Methoden, die der Dispatcher mit einem 'result'
     * beantwortet - abgelesen an seinen case-Labels.
     *
     * @return string[]
     */
    private function result_bearing_methods(): array {
        $source = file_get_contents(__DIR__ . '/../classes/dispatcher.php');
        preg_match_all("/case '([a-z\/]+)':/", $source, $matches);
        $methods = array_values(array_unique($matches[1]));

        // Benachrichtigungen beantwortet JSON-RPC gar nicht (202, leerer
        // Rumpf) - sie sind der einzige zulaessige Fall ohne 'result'.
        $withoutresult = ['notifications/initialized', 'notifications/cancelled'];

        $this->assertNotEmpty($methods, 'Keine case-Labels im Dispatcher gefunden');

        return array_values(array_diff($methods, $withoutresult));
    }

    /**
     * Die Parameter, die eine Methode zum Gelingen braucht - leer fuer alle,
     * die ohne auskommen.
     *
     * @param string $method
     * @return array
     */
    private function arguments_for(string $method): array {
        if ($method === 'tools/call') {
            return ['params' => ['name' => 'coursepilot_list_courses']];
        }
        return [];
    }

    /**
     * Eine Antwort ohne Inhalt bleibt ein JSON-Objekt, kein leeres Array.
     *
     * ping liefert laut Spezifikation ein leeres Ergebnisobjekt. In der
     * Legacy-Aera kommen keine Metadaten dazu - wer dafuer ein leeres PHP-
     * Array nimmt, verschickt "[]" statt "{}" und bricht das Schema.
     */
    public function test_ping_result_stays_an_object_in_the_legacy_era(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            $token,
            $this->headers(['protocolversion' => dispatcher::LEGACY_VERSION])
        );

        $this->assertStringContainsString(
            '"result":{}',
            json_encode($response['body'])
        );
    }

    /**
     * Die Ergebnis-Metadaten der Revision 2026-07-28 (resultType/ttlMs/
     * cacheScope) gehen nur an Clients, die genau diese Revision aushandeln.
     *
     * Ein 2025-06-18-Client (Codex, rmcp) verwirft eine tools/call-Antwort
     * mit diesen Feldern vollstaendig ("Unexpected response type", #400) -
     * sie sind in seiner Revision nicht vorgesehen.
     */
    public function test_result_metadata_only_for_modern_protocol_version(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $request = [
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'coursepilot_get_course_catalog',
                'arguments' => ['courseid' => $course->id],
            ],
        ];

        $legacy = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));
        $modern = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));
        $unknown = dispatcher::handle($request, $token, $this->headers());

        $this->assertArrayNotHasKey('resultType', $legacy['body']['result']);
        $this->assertArrayNotHasKey('resultType', $unknown['body']['result']);
        // #458: 'complete' ist der einzige Erfolgswert, den die Revision fuer
        // tools/call kennt ('input_required' bleibt dem MRTR-Muster
        // vorbehalten, das wir nicht anbieten). Caching-Felder gehoeren
        // ausschliesslich an Listenantworten - ein ttlMs auf einem
        // Schreibvorgang legt einem Client nahe, ihn zu cachen.
        $this->assertSame('complete', $modern['body']['result']['resultType']);
        $this->assertArrayNotHasKey('ttlMs', $modern['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $modern['body']['result']);

        $list = ['id' => 2, 'method' => 'tools/list'];
        $legacylist = dispatcher::handle($list, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));
        $modernlist = dispatcher::handle($list, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));
        $this->assertArrayNotHasKey('resultType', $legacylist['body']['result']);
        $this->assertSame('complete', $modernlist['body']['result']['resultType']);
    }

    /**
     * tools/call weist ein nicht gelistetes Werkzeug ab.
     */
    public function test_tools_call_rejects_unlisted_tool(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'not_a_real_tool']],
            $token,
            $this->headers()
        );

        $this->assertSame(404, $response['status']);
        $this->assertSame(-32601, $response['body']['error']['code']);
    }

    /**
     * #401: die drei Discovery-Methoden, die Codex nach dem Handshake
     * unaufgefordert abfragt, liefern eine leere Liste im jeweils richtigen
     * Feld statt eines 404 - Hoeflichkeit, kein angebotenes Feature.
     */
    public function test_resources_list_returns_empty_list(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'resources/list'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['result']['resources']);
    }

    /**
     * #401: die drei neuen Discovery-Methoden folgen derselben Aeren-Weiche
     * wie tools/list (siehe test_result_metadata_only_for_modern_protocol_version)
     * - stellvertretend an resources/list geprueft, dieselbe resultmeta()-
     * Funnelstelle bedient auch die anderen beiden.
     */
    public function test_resources_list_result_metadata_only_for_modern_protocol_version(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();
        $request = ['id' => 1, 'method' => 'resources/list'];

        $legacy = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::LEGACY_VERSION,
        ]));
        $modern = dispatcher::handle($request, $token, $this->headers([
            'protocolversion' => dispatcher::MODERN_VERSION,
        ]));

        $this->assertArrayNotHasKey('resultType', $legacy['body']['result']);
        $this->assertSame('complete', $modern['body']['result']['resultType']);
        $this->assertSame(300000, $modern['body']['result']['ttlMs']);
        $this->assertSame('private', $modern['body']['result']['cacheScope']);
    }

    /**
     * #401: wie test_resources_list_returns_empty_list, fuer
     * resources/templates/list.
     */
    public function test_resources_templates_list_returns_empty_list(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'resources/templates/list'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['result']['resourceTemplates']);
    }

    /**
     * #401: wie test_resources_list_returns_empty_list, fuer prompts/list.
     */
    public function test_prompts_list_returns_empty_list(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'prompts/list'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
        $this->assertSame([], $response['body']['result']['prompts']);
    }

    /**
     * #401, Akzeptanzkriterium: die drei neuen Discovery-Methoden sind kein
     * Auffangbecken fuer Tippfehler - ein wirklich unbekannter Methodenname
     * liefert weiterhin 404/-32601.
     */
    public function test_truly_unknown_method_still_returns_404(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'resources/subscribe'], $token, $this->headers());

        $this->assertSame(404, $response['status']);
        $this->assertSame(-32601, $response['body']['error']['code']);
    }

    /**
     * Ohne Origin-Header greift die Origin-Pruefung nicht.
     */
    public function test_origin_check_is_skipped_without_header(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(['id' => 1, 'method' => 'ping'], null, $this->headers(['origin' => null]));

        $this->assertNotSame(403, $response['status']);
    }

    /**
     * Mit vorhandenem, nicht erlaubtem Origin-Header greift die Pruefung.
     */
    public function test_origin_check_rejects_disallowed_origin(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            null,
            $this->headers(['origin' => 'https://evil.example'])
        );

        $this->assertSame(403, $response['status']);
    }

    /**
     * Eine abgelehnte Origin erzeugt ebenfalls ein Fehler-Ereignis (#339) -
     * dieser Zweig liegt vor handle_authorized() und laeuft nicht ueber
     * error(), braucht deshalb einen eigenen Test.
     */
    public function test_origin_rejection_triggers_failure_event(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            null,
            $this->headers(['origin' => 'https://evil.example'])
        );

        $events = array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_failed);
        $this->assertCount(1, $events);
        $sink->close();
    }

    /**
     * CORS-Preflight (#337-Nachtrag): ein Browser-fetch() mit Authorization-
     * Header von einem erlaubten Origin schickt zuerst OPTIONS. Ohne die
     * passenden Access-Control-*-Kopfzeilen blockt der Browser den
     * eigentlichen POST clientseitig - vom Server aus nie sichtbar, nur am
     * Verbindungsfehler auf Client-Seite erkennbar (Fund aus dem
     * Claude.ai-Custom-Connector-Livetest).
     */
    public function test_options_preflight_returns_cors_headers_for_allowed_origin(): void {
        $response = dispatcher::handle(
            null,
            null,
            $this->headers(['origin' => 'https://claude.ai', 'method' => 'OPTIONS'])
        );

        $this->assertSame(204, $response['status']);
        $this->assertSame('https://claude.ai', $response['headers']['Access-Control-Allow-Origin']);
        $this->assertStringContainsString('POST', $response['headers']['Access-Control-Allow-Methods']);
    }

    /**
     * Die CORS-Kopfzeile gehoert nicht nur auf den Preflight, sondern auch
     * auf die eigentliche Antwort - sonst verwirft der Browser sie trotz
     * erfolgreichem Preflight (#337-Nachtrag).
     */
    public function test_actual_response_also_carries_cors_header_for_allowed_origin(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'ping'],
            null,
            $this->headers(['origin' => 'https://claude.ai'])
        );

        $this->assertSame('https://claude.ai', $response['headers']['Access-Control-Allow-Origin']);
    }

    /**
     * Fehlerantworten sind JSON-faehige Arrays, nie HTML.
     */
    public function test_error_responses_are_json_not_html(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(null, null, $this->headers());

        $this->assertIsArray($response['body']);
        $encoded = json_encode($response['body']);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('<html', strtolower($encoded));
    }

    /**
     * Ein gueltiges OAuth-Access-Token wird auf die richtige Person
     * abgebildet - der Toolaufruf laeuft als dieser Nutzer, nicht als
     * irgendein anderer (#337).
     */
    public function test_valid_oauth_token_is_mapped_to_correct_person(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers(['protocolversion' => dispatcher::MODERN_VERSION])
        );

        $this->assertSame(200, $response['status']);
        $this->assertSame((int) $course->id, $response['body']['result']['structuredContent']['courses'][0]['id']);
        // Ergebnis-Metadaten der Revision 2026-07-28 (#337-Nachtrag, Fund aus
        // dem Claude-Code-Livetest: cacheScope "session" ist kein gueltiger
        // Wert, nur "public"/"private" - liess jeden tools/call scheitern).
        // #458: derselbe Livetest ein zweites Mal - 'data' ist ueberhaupt kein
        // gueltiger resultType, und Caching-Felder haben an einer
        // tools/call-Antwort nichts zu suchen.
        $this->assertSame('complete', $response['body']['result']['resultType']);
        $this->assertArrayNotHasKey('ttlMs', $response['body']['result']);
        $this->assertArrayNotHasKey('cacheScope', $response['body']['result']);
    }

    /**
     * Person A sieht unter keinen Umstaenden Kurse der Person B - der
     * Toolaufruf laeuft strikt als der im Token hinterlegte Nutzer (#337).
     */
    public function test_person_a_never_sees_courses_of_person_b(): void {
        $this->resetAfterTest();
        $coursea = $this->getDataGenerator()->create_course(['shortname' => 'kurs-a']);
        $courseb = $this->getDataGenerator()->create_course(['shortname' => 'kurs-b']);
        [$persona, $tokena] = $this->create_authenticated_user();
        [$personb, $tokenb] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($persona->id, $coursea->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($personb->id, $courseb->id, 'editingteacher');

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $tokena,
            $this->headers()
        );

        $ids = array_column($response['body']['result']['structuredContent']['courses'], 'id');
        $this->assertSame([(int) $coursea->id], $ids);
        $this->assertNotContains((int) $courseb->id, $ids);
    }

    /**
     * Ein Moodle-Webservice-Token (external_tokens, die fruehere Kruecke)
     * wird nicht mehr akzeptiert (#337).
     */
    public function test_moodle_webservice_token_is_no_longer_accepted(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $service = $DB->get_record('external_services', ['shortname' => privacy_surface::SERVICE_SHORTNAME]);
        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $user->id,
            \context_system::instance()
        );

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * Ein abgelaufenes OAuth-Access-Token wird abgewiesen (#337).
     */
    public function test_expired_oauth_token_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_access_token($user->id, -60);

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * Ein widerrufenes OAuth-Access-Token (revoked=1, z. B. durch
     * Refresh-Rotation) wird abgewiesen (#337).
     */
    public function test_revoked_oauth_token_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $token = $this->issue_access_token($user->id, 3600, true);

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * Ein gueltiges Token ohne local/coursepilot:useremote wird abgewiesen -
     * konkret, mit Capability-Namen, auch wenn das Token selbst gueltig ist
     * (#337).
     */
    public function test_valid_token_without_useremote_capability_is_rejected(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user(false);

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(403, $response['status']);
        $this->assertSame(-32002, $response['body']['error']['code']);
        $this->assertStringContainsString('local/coursepilot:useremote', $response['body']['error']['message']);
    }

    /**
     * Fehlt local/coursepilot:use in jedem Kurs, reicht die Fernzugriffs-
     * Capability allein nicht - der Dispatcher reicht den konkreten
     * Kurs-Capability-Fehler aus list_courses::execute() unveraendert durch,
     * statt ihn zu verdecken (#337, Abnahmekriterium "Berechtigungsmeldung,
     * keine leere Liste").
     */
    public function test_useremote_alone_does_not_bypass_course_level_capability(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['result']['isError']);
        $this->assertStringContainsString('CAPABILITY_MISSING:local/coursepilot:use', $response['body']['result']['content'][0]['text']);
    }

    /**
     * Globale Notbremse (#338): remoteaccessenabled=0 sperrt jeden weiteren
     * Zugriff sofort - auch mit gueltigem Token und vorhandener Capability.
     */
    public function test_kill_switch_blocks_access_even_with_valid_token(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();
        set_config('remoteaccessenabled', 0, 'local_coursepilot');

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(403, $response['status']);
        $this->assertSame(-32003, $response['body']['error']['code']);
    }

    /**
     * Ohne gesetzten Konfigwert (frische Installation, Einstellung nie
     * besucht) bleibt der Fernzugriff nutzbar - der Standard ist "an".
     */
    public function test_kill_switch_defaults_to_enabled(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(200, $response['status']);
    }

    /**
     * Nach dem Sammelwiderruf (#338) schlaegt ein Zugriff mit dem alten
     * Token fehl - derselbe Auth-Gate-Pfad wie bei Ablauf/Einzelwiderruf.
     */
    public function test_access_with_token_fails_after_bulk_revoke(): void {
        $this->resetAfterTest();
        [, $token] = $this->create_authenticated_user();

        oauth_lib::revoke_all_tokens();
        $response = dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());

        $this->assertSame(401, $response['status']);
        $this->assertSame(-32001, $response['body']['error']['code']);
    }

    /**
     * Erfolgreicher Werkzeugaufruf erzeugt ein Ereignis ueber die
     * Moodle-Ereignis-API - Voreinstellung "Lesezugriffe und Fehler" (#339).
     */
    public function test_successful_tool_call_triggers_access_event(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $sink = $this->redirectEvents();

        dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $events = array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_succeeded);
        $this->assertCount(1, $events);
        $event = array_values($events)[0];
        $this->assertSame('coursepilot_list_courses', $event->other['toolname']);
        $sink->close();
    }

    /**
     * Ein fehlgeschlagener Zugriff (ungueltiges Token) erzeugt ein
     * Fehler-Ereignis (#339).
     */
    public function test_failed_authentication_triggers_failure_event(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        dispatcher::handle(['id' => 1, 'method' => 'initialize'], 'not-a-real-token', $this->headers());

        $events = array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_failed);
        $this->assertCount(1, $events);
        $sink->close();
    }

    /**
     * Auf Stufe "kein Protokoll" entsteht kein Eintrag, auch nicht bei
     * einem fehlgeschlagenen Zugriff (#339).
     */
    public function test_no_events_at_all_when_logging_disabled(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_NONE, 'local_coursepilot');
        [, $token] = $this->create_authenticated_user();
        $sink = $this->redirectEvents();

        dispatcher::handle(['id' => 1, 'method' => 'initialize'], 'not-a-real-token', $this->headers());
        dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );

        $events = array_filter(
            $sink->get_events(),
            fn ($e) => $e instanceof event\tool_access_succeeded || $e instanceof event\tool_access_failed
        );
        $this->assertCount(0, $events);
        $sink->close();
    }

    /**
     * Auf Stufe "nur Fehler" entsteht bei erfolgreichem Zugriff kein
     * Eintrag, bei Fehler schon (#339).
     */
    public function test_errors_only_level_skips_successful_access(): void {
        $this->resetAfterTest();
        set_config('loglevel', access_log::LEVEL_ERRORS, 'local_coursepilot');
        $course = $this->getDataGenerator()->create_course();
        [$teacher, $token] = $this->create_authenticated_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $sink = $this->redirectEvents();

        dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'coursepilot_list_courses']],
            $token,
            $this->headers()
        );
        dispatcher::handle(['id' => 1, 'method' => 'initialize'], 'not-a-real-token', $this->headers());

        $this->assertCount(
            0,
            array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_succeeded)
        );
        $this->assertCount(
            1,
            array_filter($sink->get_events(), fn ($e) => $e instanceof event\tool_access_failed)
        );
        $sink->close();
    }

    /**
     * Kein Zugangsgeheimnis landet im Protokolltext - auch nicht im
     * Fehlertext eines echten, per Dispatcher ausgeloesten Auth-Fehlers
     * (#339).
     */
    public function test_access_token_never_appears_in_a_logged_event(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();
        $secrettoken = oauth_lib::random_token(32);

        dispatcher::handle(['id' => 1, 'method' => 'initialize'], $secrettoken, $this->headers());

        foreach ($sink->get_events() as $event) {
            $encoded = json_encode($event->get_data());
            $this->assertStringNotContainsString($secrettoken, $encoded);
        }
        $sink->close();
    }
}
