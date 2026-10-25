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


namespace local_coursepilot\output;

use local_coursepilot\dispatcher;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact responses, headers, access log events and request mapping of the HTTP entry points.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(http_endpoint::class)]
#[CoversClass(http_response::class)]
final class http_endpoint_contract_test extends \advanced_testcase {
    use caught_throwable;
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Calls the private bearer parser.
     *
     * @param string[] $server Server variables.
     * @param string[] $headers Request headers.
     * @return string|null The token or null.
     */
    private function bearer(array $server, array $headers = []): ?string {
        return (new \ReflectionMethod(http_endpoint::class, 'bearer'))->invoke(null, $server, $headers);
    }

    public function test_the_browser_endpoint_needs_the_remote_access_grant_before_anything_else(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->caught(fn() => http_endpoint::browse(0, '../outside'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
    }

    public function test_browser_success_and_error_envelopes_are_exact(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $instanceid = $this->create_webdav_instance($USER);
        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake->seed_folder('/Coursepilot');
        $fake->seed_folder('/Coursepilot/Lessons');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];

        $success = http_endpoint::browse($instanceid, '');
        $failure = http_endpoint::browse(0, '../outside');

        $this->assertSame(200, $success->status);
        $this->assertSame($headers, $success->headers);
        $body = json_decode($success->body, true);
        $this->assertSame(['ok', 'state'], array_keys($body));
        $this->assertTrue($body['ok']);
        $this->assertSame(
            json_decode(
                json_encode(
                    \local_coursepilot\location_selection::page_state(
                        (int) $USER->id,
                        \local_coursepilot\location_selection::browse($instanceid, '')
                    )
                ),
                true
            ),
            $body['state']
        );
        $this->assertSame(400, $failure->status);
        $this->assertSame($headers, $failure->headers);
        $error = json_decode($failure->body, true);
        $this->assertSame(['ok', 'errorkey', 'errorclass', 'page'], array_keys($error));
        $this->assertFalse($error['ok']);
        $this->assertSame('', $error['errorclass']);
        $this->assertSame('', $error['page']);
    }

    public function test_an_empty_ticket_is_a_logged_404_with_the_invalid_ticket_message(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        $response = http_endpoint::download('');

        $this->assertSame(404, $response->status);
        $this->assertSame(['Content-Type' => 'application/json'], $response->headers);
        $this->assertSame(
            ['error' => get_string('workbenchticketinvalid', 'local_coursepilot')],
            json_decode($response->body, true)
        );
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_coursepilot\event\tool_access_failed::class, $events[0]);
        $this->assertSame('workbenchticketinvalid', $events[0]->other['reason']);
        $this->assertSame('coursepilot_workbench_download', $events[0]->other['toolname']);
    }

    public function test_an_unknown_ticket_is_a_logged_403_with_the_failure_message(): void {
        $this->resetAfterTest();
        $sink = $this->redirectEvents();

        $response = http_endpoint::download('unknown-ticket');

        $this->assertSame(403, $response->status);
        $this->assertSame(['Content-Type' => 'application/json'], $response->headers);
        $body = json_decode($response->body, true);
        $this->assertSame(['error'], array_keys($body));
        $this->assertNotSame('', $body['error']);
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_coursepilot\event\tool_access_failed::class, $events[0]);
        $this->assertSame('coursepilot_workbench_download', $events[0]->other['toolname']);
        $this->assertNotSame('', (string) $events[0]->other['reason']);
    }

    /**
     * Issues a download ticket for a stored file and leaves the user logged out.
     *
     * @param string $content
     * @return array{0: string, 1: int} Ticket and owner id.
     */
    private function ticket_for(string $content): array {
        global $USER;
        $this->setAdminUser();
        $owner = (int) $USER->id;
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = \local_coursepilot\connection::issue_code('client', $owner, 'https://client.example/cb', $challenge);
        $tokens = \local_coursepilot\connection::exchange_code($code, 'client', 'https://client.example/cb', $verifier);
        \local_coursepilot\connection::authenticate_access_token($tokens['access_token']);
        [$directory, $filename] = \local_coursepilot\material_files::resolve_file('lesson.txt');
        get_file_storage()->create_file_from_string(\local_coursepilot\material_files::filerecord(
            \local_coursepilot\material_files::own_context()->id,
            $directory,
            $filename
        ), $content);
        $link = \local_coursepilot\workbench_ticket::issue('lesson.txt');
        parse_str(parse_url($link['url'], PHP_URL_QUERY), $query);
        $this->setUser();
        return [$query['ticket'], $owner];
    }

    public function test_a_download_has_exactly_the_documented_headers_and_logs_a_read_for_the_owner(): void {
        $this->resetAfterTest();
        [$ticket, $owner] = $this->ticket_for("Bytes\n");
        $sink = $this->redirectEvents();

        $response = http_endpoint::download($ticket);

        $this->assertSame(200, $response->status);
        $this->assertSame("Bytes\n", $response->body);
        $this->assertSame([
            'Content-Type' => 'text/plain',
            'Content-Length' => '6',
            'Content-Disposition' => 'attachment; filename="lesson.txt"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $response->headers);
        $events = $sink->get_events();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_coursepilot\event\tool_access_succeeded::class, $events[0]);
        $this->assertSame('lesson.txt', $events[0]->other['path']);
        $this->assertSame('coursepilot_workbench_download', $events[0]->other['toolname']);
        $this->assertSame($owner, (int) $events[0]->userid);
    }

    public function test_a_download_counts_as_a_read_not_as_a_write_for_the_log_level(): void {
        $this->resetAfterTest();
        [$ticket] = $this->ticket_for('x');
        set_config('loglevel', \local_coursepilot\access_log::LEVEL_ERRORS, 'local_coursepilot');
        $sink = $this->redirectEvents();

        http_endpoint::download($ticket);

        $this->assertCount(0, $sink->get_events());
    }

    public function test_the_authorization_header_is_parsed_strictly(): void {
        $this->assertSame('abc', $this->bearer(['HTTP_AUTHORIZATION' => 'Bearer abc']));
        $this->assertSame('abc', $this->bearer(['HTTP_AUTHORIZATION' => "  bearer   abc \n"]));
        $this->assertNull($this->bearer(['HTTP_AUTHORIZATION' => 'xBearer abc']));
        $this->assertNull($this->bearer(['HTTP_AUTHORIZATION' => 'Bearer abc def']));
        $this->assertNull($this->bearer(['HTTP_AUTHORIZATION' => 'Basic abc']));
        $this->assertNull($this->bearer([]));
    }

    public function test_the_first_authorization_header_wins(): void {
        $this->assertSame(
            'first',
            $this->bearer([], ['X-A' => '1', 'Authorization' => 'Bearer first', 'authorization' => 'Bearer second'])
        );
    }

    public function test_the_webserver_header_api_is_optional(): void {
        $headers = $this->caught(fn() => http_endpoint::request_headers());

        $this->assertNull($headers);
        $this->assertSame([], http_endpoint::request_headers());
    }

    /**
     * Provides raw requests and server metadata for the mapping test.
     *
     * @return array<string, array{array<string, string>, array<string, mixed>}>
     */
    public static function mapped_requests(): array {
        $context = ['origin' => null, 'pathinfo' => '', 'method' => 'POST', 'protocolversion' => null];
        return [
            'foreign origin' => [
                ['HTTP_ORIGIN' => 'https://evil.example'],
                ['origin' => 'https://evil.example'] + $context,
            ],
            'preflight' => [['REQUEST_METHOD' => 'OPTIONS'], ['method' => 'OPTIONS'] + $context],
            'wrong method' => [['REQUEST_METHOD' => 'GET'], ['method' => 'GET'] + $context],
            'metadata path' => [
                ['PATH_INFO' => '/.well-known/oauth-protected-resource'],
                ['pathinfo' => '/.well-known/oauth-protected-resource'] + $context,
            ],
            'defaults' => [[], $context],
        ];
    }

    /**
     * The server variables reach the dispatcher under their names.
     *
     * @param array<string, string> $server Server variables.
     * @param array<string, string|null> $context Expected dispatcher context.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mapped_requests')]
    public function test_the_server_variables_reach_the_dispatcher_under_their_names(array $server, array $context): void {
        $this->resetAfterTest();
        $raw = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';

        $response = http_endpoint::mcp($raw, $server, []);

        $expected = http_response::json(
            dispatcher::handle(json_decode($raw, true), null, $context),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $this->assertSame($expected->status, $response->status);
        $this->assertSame($expected->headers, $response->headers);
        $this->assertSame($expected->body, $response->body);
    }

    public function test_the_protocol_version_header_reaches_the_dispatcher(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = \local_coursepilot\connection::issue_code('client', (int) $USER->id, 'https://client.example/cb', $challenge);
        $tokens = \local_coursepilot\connection::exchange_code($code, 'client', 'https://client.example/cb', $verifier);
        $token = $tokens['access_token'];
        $raw = '{"jsonrpc":"2.0","id":1,"method":"tools/list"}';
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . $token];

        $plain = http_endpoint::mcp($raw, $server, []);
        $modern = http_endpoint::mcp($raw, $server + ['HTTP_MCP_PROTOCOL_VERSION' => dispatcher::MODERN_VERSION], []);

        $this->assertSame(200, $plain->status);
        $this->assertSame(200, $modern->status);
        $this->assertNotSame($plain->body, $modern->body);
        $this->assertStringNotContainsString('\/', $plain->body);
        $this->assertStringNotContainsString('\u', $plain->body);
    }
}
