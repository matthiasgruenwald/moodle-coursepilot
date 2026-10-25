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
 * The exact HTTP-level answers of the MCP dispatcher: origin and CORS handling, authentication
 * challenge, protocol and list responses of both protocol revisions, and tool-call error results.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(dispatcher::class)]
final class dispatcher_responses_test extends \advanced_testcase {
    /**
     * Creates a user with a valid OAuth access token and remote access; returns the token.
     *
     * @return string
     */
    private function token(): string {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $user->id, \context_system::instance()->id);
        assign_capability(
            'local/coursepilot:useremote',
            CAP_ALLOW,
            (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST),
            \context_system::instance()->id,
            true
        );
        $accesstoken = bin2hex(random_bytes(32));
        $connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $user->id, 'clientid' => 'test-client', 'revoked' => 0,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => time(),
        ]);
        $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'accesstokenhash' => hash('sha256', $accesstoken), 'refreshtokenhash' => hash('sha256', bin2hex(random_bytes(32))),
            'clientid' => 'test-client', 'userid' => $user->id, 'expires' => time() + 3600,
            'refreshexpires' => time() + connection::REFRESH_TOKEN_TTL, 'revoked' => 0, 'timecreated' => time(),
            'connectionid' => $connectionid,
        ]);
        return $accesstoken;
    }

    /**
     * Request headers of the transport layer.
     *
     * @param mixed[] $overrides
     * @return mixed[]
     */
    private function headers(array $overrides = []): array {
        return array_merge(['origin' => null, 'pathinfo' => '', 'method' => 'POST'], $overrides);
    }

    /**
     * An origin outside the site and the two known clients is refused before anything else.
     */
    public function test_foreign_origin_is_refused_with_a_plain_403(): void {
        $this->resetAfterTest();

        $response = dispatcher::handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], null, $this->headers([
            'origin' => 'https://evil.example',
        ]));

        $this->assertSame(['status' => 403, 'headers' => [], 'body' => ['error' => 'Origin not allowed']], $response);
    }

    /**
     * The site origin counts with or without a trailing slash on either side, and answers carry CORS headers.
     */
    public function test_site_origin_is_accepted_ignoring_trailing_slashes(): void {
        global $CFG;

        $this->resetAfterTest();
        $CFG->wwwroot = 'https://site.example/';

        foreach (['https://site.example', 'https://site.example/'] as $origin) {
            $response = dispatcher::handle(null, null, $this->headers(['origin' => $origin]));

            $this->assertSame(400, $response['status'], $origin);
            $this->assertSame(['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'], $response['headers'], $origin);
        }
    }

    /**
     * The two named browser clients are allowed in addition to the site.
     */
    public function test_named_browser_clients_are_accepted(): void {
        foreach (['https://claude.ai', 'https://chatgpt.com'] as $origin) {
            $response = dispatcher::handle(null, null, $this->headers(['origin' => $origin]));

            $this->assertSame(400, $response['status'], $origin);
        }
    }

    /**
     * A CORS preflight is answered without body and names the allowed methods, headers and cache age.
     */
    public function test_preflight_answers_204_with_the_cors_policy(): void {
        $response = dispatcher::handle(null, null, $this->headers(['origin' => 'https://claude.ai', 'method' => 'OPTIONS']));

        $this->assertSame([
            'status' => 204,
            'headers' => [
                'Access-Control-Allow-Origin' => 'https://claude.ai',
                'Vary' => 'Origin',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Max-Age' => '86400',
            ],
            'body' => null,
        ], $response);
    }

    /**
     * A non-POST request gets 405 with the allowed method; an undecodable body is a JSON-RPC parse error.
     */
    public function test_wrong_method_and_parse_error_answers(): void {
        $this->resetAfterTest();

        $this->assertSame([
            'status' => 405,
            'headers' => ['Allow' => 'POST'],
            'body' => ['error' => 'Method Not Allowed - MCP over HTTP is POST only'],
        ], dispatcher::handle(null, null, $this->headers(['method' => 'GET'])));
        $this->assertSame([
            'status' => 400,
            'headers' => [],
            'body' => ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']],
        ], dispatcher::handle(null, null, $this->headers()));
    }

    /**
     * A missing or unknown token is challenged with the protected-resource metadata location.
     */
    public function test_unauthenticated_request_is_challenged(): void {
        global $CFG;

        $this->resetAfterTest();
        $expected = [
            'status' => 401,
            'headers' => [
                'WWW-Authenticate' => 'Bearer resource_metadata="' . $CFG->wwwroot
                    . '/local/coursepilot/oauth/protected-resource.php"',
            ],
            'body' => ['jsonrpc' => '2.0', 'id' => 7, 'error' => ['code' => -32001, 'message' => 'AUTHENTICATION_FAILED']],
        ];

        $ping = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'];
        $this->assertSame($expected, dispatcher::handle($ping, null, $this->headers()));
        $this->assertSame($expected, dispatcher::handle($ping, 'unknown-token', $this->headers()));
    }

    /**
     * The protected-resource metadata is served on its well-known path, uncached.
     */
    public function test_protected_resource_metadata_on_the_well_known_path(): void {
        global $CFG;

        $this->resetAfterTest();

        $response = dispatcher::handle(null, null, $this->headers(['pathinfo' => '/.well-known/oauth-protected-resource/']));

        $this->assertSame(200, $response['status']);
        $this->assertSame(['Cache-Control' => 'no-store'], $response['headers']);
        $this->assertSame(oauth_lib::protected_resource_metadata($CFG->wwwroot), $response['body']);
    }

    /**
     * Calls the dispatcher authenticated.
     *
     * @param mixed[] $request
     * @param mixed[] $headers
     * @return mixed[]
     */
    private function call(array $request, array $headers = []): array {
        return dispatcher::handle($request + ['jsonrpc' => '2.0', 'id' => 5], $this->token(), $this->headers($headers));
    }

    /**
     * JSON of a response body, so empty objects stay distinguishable from empty lists.
     *
     * @param mixed[] $response
     * @return string
     */
    private function json(array $response): string {
        return json_encode($response['body'], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Initialize echoes the client's protocol version, falls back to the legacy one, and carries the entry guidance.
     */
    public function test_initialize_response(): void {
        $this->resetAfterTest();
        $release = plugin_meta::current()->release;

        $echoed = $this->call(['method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']]);
        $default = $this->call(['method' => 'initialize']);

        $this->assertSame(200, $echoed['status']);
        $this->assertSame([], $echoed['headers']);
        $this->assertSame(
            '{"jsonrpc":"2.0","id":5,"result":{"protocolVersion":"2024-11-05","capabilities":{"tools":{}},'
                . '"serverInfo":{"name":"local_coursepilot","version":' . json_encode($release)
                . '},"instructions":"Before planning or writing, call coursepilot_list_skills first."}}',
            $this->json($echoed)
        );
        $this->assertSame('2025-06-18', $default['body']['result']['protocolVersion']);
    }

    /**
     * Server discovery lists both revisions; ping answers an empty object, with resultType only in the modern revision.
     */
    public function test_discover_and_ping_by_protocol_revision(): void {
        $this->resetAfterTest();
        $release = plugin_meta::current()->release;
        $modern = ['protocolversion' => '2026-07-28'];

        $this->assertSame(
            '{"jsonrpc":"2.0","id":5,"result":{"supportedVersions":["2026-07-28","2025-06-18"],"capabilities":{"tools":{}},'
                . '"serverInfo":{"name":"local_coursepilot","version":' . json_encode($release)
                . '},"instructions":"Before planning or writing, call coursepilot_list_skills first."}}',
            $this->json($this->call(['method' => 'server/discover']))
        );
        $this->assertSame(
            '{"jsonrpc":"2.0","id":5,"result":{"supportedVersions":["2026-07-28","2025-06-18"],"capabilities":{"tools":{}},'
                . '"serverInfo":{"name":"local_coursepilot","version":' . json_encode($release)
                . '},"instructions":"Before planning or writing, call coursepilot_list_skills first.","resultType":"complete"}}',
            $this->json($this->call(['method' => 'server/discover'], $modern))
        );
        $this->assertSame('{"jsonrpc":"2.0","id":5,"result":{}}', $this->json($this->call(['method' => 'ping'])));
        $this->assertSame(
            '{"jsonrpc":"2.0","id":5,"result":{"resultType":"complete"}}',
            $this->json($this->call(['method' => 'ping'], $modern))
        );
    }

    /**
     * Notifications are acknowledged with an empty 202.
     */
    public function test_notifications_are_acknowledged_without_body(): void {
        $this->resetAfterTest();

        $this->assertSame(
            ['status' => 202, 'headers' => [], 'body' => null],
            $this->call(['method' => 'notifications/initialized'])
        );
    }

    /**
     * The empty resource and prompt lists stay lists, with freshness metadata only in the modern revision.
     */
    public function test_empty_lists_and_their_freshness_metadata(): void {
        $this->resetAfterTest();
        $modern = ['protocolversion' => '2026-07-28'];

        $lists = ['resources/list' => 'resources', 'resources/templates/list' => 'resourceTemplates', 'prompts/list' => 'prompts'];
        foreach ($lists as $method => $key) {
            $this->assertSame(
                '{"jsonrpc":"2.0","id":5,"result":{"' . $key . '":[]}}',
                $this->json($this->call(['method' => $method])),
                $method
            );
            $this->assertSame(
                '{"jsonrpc":"2.0","id":5,"result":{"' . $key
                    . '":[],"resultType":"complete","ttlMs":300000,"cacheScope":"private"}}',
                $this->json($this->call(['method' => $method], $modern)),
                $method
            );
        }
    }

    /**
     * The tool list carries every allowed tool with its input schema; freshness metadata comes with the modern revision.
     */
    public function test_tool_list_describes_every_allowed_tool(): void {
        $this->resetAfterTest();

        $legacy = $this->call(['method' => 'tools/list']);
        $modern = $this->call(['method' => 'tools/list'], ['protocolversion' => '2026-07-28']);

        $this->assertSame(200, $legacy['status']);
        $this->assertSame(['jsonrpc', 'id', 'result'], array_keys($legacy['body']));
        $this->assertSame(['tools'], array_keys($legacy['body']['result']));
        $this->assertSame(['tools', 'resultType', 'ttlMs', 'cacheScope'], array_keys($modern['body']['result']));
        $this->assertSame(300000, $modern['body']['result']['ttlMs']);
        $this->assertSame(array_keys(privacy_surface::allowed_tools()), array_column($legacy['body']['result']['tools'], 'name'));
        foreach ($legacy['body']['result']['tools'] as $tool) {
            $this->assertSame(['name', 'description', 'inputSchema'], array_keys($tool));
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertFalse($tool['inputSchema']['additionalProperties']);
        }
    }

    /**
     * Unknown methods and tools are 404 JSON-RPC errors naming what was asked for.
     */
    public function test_unknown_method_and_unknown_tool(): void {
        $this->resetAfterTest();

        $this->assertSame([
            'status' => 404,
            'headers' => [],
            'body' => ['jsonrpc' => '2.0', 'id' => 5, 'error' => ['code' => -32601, 'message' => 'Method not found: foo/bar']],
        ], $this->call(['method' => 'foo/bar']));
        $this->assertSame([
            'status' => 404,
            'headers' => [],
            'body' => ['jsonrpc' => '2.0', 'id' => 5, 'error' => ['code' => -32601, 'message' => 'Unknown tool: nope']],
        ], $this->call(['method' => 'tools/call', 'params' => ['name' => 'nope']]));
        $this->assertSame(
            'Unknown tool: ',
            $this->call(['method' => 'tools/call', 'params' => []])['body']['error']['message']
        );
    }

    /**
     * A failing tool call is delivered as a successful response with isError and the cleaned message.
     */
    public function test_failing_tool_call_is_an_error_result_with_the_teacher_facing_message(): void {
        $this->resetAfterTest();

        $response = $this->call(['method' => 'tools/call', 'params' => [
            'name' => 'coursepilot_get_module_settings', 'arguments' => ['cmid' => 'abc'],
        ]]);
        $modern = $this->call(['method' => 'tools/call', 'params' => [
            'name' => 'coursepilot_get_module_settings', 'arguments' => ['cmid' => 'abc'],
        ]], ['protocolversion' => '2026-07-28']);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['jsonrpc', 'id', 'result'], array_keys($response['body']));
        $this->assertSame(['isError', 'content'], array_keys($response['body']['result']));
        $this->assertTrue($response['body']['result']['isError']);
        $this->assertCount(1, $response['body']['result']['content']);
        $this->assertSame('text', $response['body']['result']['content'][0]['type']);
        $this->assertNotSame('', $response['body']['result']['content'][0]['text']);
        $this->assertStringNotContainsString("\nError code", $response['body']['result']['content'][0]['text']);
        $this->assertSame(['isError', 'content', 'resultType'], array_keys($modern['body']['result']));
        $this->assertSame('complete', $modern['body']['result']['resultType']);
    }

    /**
     * A successful tool call carries the data as text and as structured content, once.
     */
    public function test_successful_tool_call_delivers_text_and_structured_content(): void {
        $this->resetAfterTest();

        $response = $this->call(['method' => 'tools/call', 'params' => ['name' => 'coursepilot_get_version_info']]);
        $modern = $this->call(
            ['method' => 'tools/call', 'params' => ['name' => 'coursepilot_get_version_info']],
            ['protocolversion' => '2026-07-28']
        );

        $result = $response['body']['result'];
        $this->assertSame(['content', 'structuredContent'], array_keys($result));
        $this->assertCount(1, $result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));
        $this->assertSame(['content', 'structuredContent', 'resultType'], array_keys($modern['body']['result']));
    }
}
