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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * HTTP entry contracts run through the production adapter and real Moodle modules.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(http_endpoint::class)]
#[CoversClass(http_response::class)]
final class http_endpoint_test extends \advanced_testcase {
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    public function test_mcp_transport_preserves_unauthorized_status_and_discovery_challenge(): void {
        $this->resetAfterTest();
        $response = http_endpoint::mcp('{"jsonrpc":"2.0","id":1,"method":"tools/list"}', [], []);
        $this->assertSame(401, $response->status);
        $this->assertStringContainsString('oauth/protected-resource.php', $response->headers['WWW-Authenticate']);
        $this->assertSame('application/json', $response->headers['Content-Type']);
        $this->assertNotEmpty(json_decode($response->body, true)['error']);
    }
    public function test_token_transport_rejects_invalid_json_and_keeps_form_support(): void {
        $this->resetAfterTest();
        $json = http_endpoint::token('POST', 'application/json', '[]', []);
        $this->assertSame(400, $json->status);
        $this->assertSame('invalid_client', json_decode($json->body, true)['error']);
        $client = \local_coursepilot\oauth_lib::handle_registration('POST', json_encode([
            'redirect_uris' => ['https://client.example/cb'],
        ]), '192.0.2.1')['body']['client_id'];
        $form = http_endpoint::token('POST', 'application/x-www-form-urlencoded', '', [
            'grant_type' => 'unknown', 'client_id' => $client,
        ]);
        $this->assertSame('unsupported_grant_type', json_decode($form->body, true)['error']);
        $invalid = http_endpoint::token('POST', 'application/json', 'null', []);
        $this->assertSame(400, $invalid->status);
    }
    public function test_download_preserves_missing_unknown_and_single_use_delivery_contracts(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->assertSame(404, http_endpoint::download('')->status);
        $this->assertSame(403, http_endpoint::download('unknown')->status);
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = \local_coursepilot\connection::issue_code('client', (int) $USER->id, 'https://client.example/cb', $challenge);
        $tokens = \local_coursepilot\connection::exchange_code($code, 'client', 'https://client.example/cb', $verifier);
        \local_coursepilot\connection::authenticate_access_token($tokens['access_token']);
        [$directory, $filename] = \local_coursepilot\material_files::resolve_file('lesson.txt');
        get_file_storage()->create_file_from_string(\local_coursepilot\material_files::filerecord(
            \local_coursepilot\material_files::own_context()->id,
            $directory,
            $filename
        ), "Lesson bytes\n");
        $link = \local_coursepilot\workbench_ticket::issue('lesson.txt');
        parse_str(parse_url($link['url'], PHP_URL_QUERY), $query);
        $this->setUser();
        $delivery = http_endpoint::download($query['ticket']);
        $this->assertSame(200, $delivery->status);
        $this->assertSame("Lesson bytes\n", $delivery->body);
        $this->assertSame('13', $delivery->headers['Content-Length']);
        $this->assertSame('attachment; filename="lesson.txt"', $delivery->headers['Content-Disposition']);
        $this->assertSame('private, no-store', $delivery->headers['Cache-Control']);
        $this->assertSame(403, http_endpoint::download($query['ticket'])->status);
    }
    public function test_folder_browser_returns_a_structured_error_for_invalid_paths(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $response = http_endpoint::browse(0, '../outside');
        $this->assertSame(400, $response->status);
        $error = json_decode($response->body, true);
        $this->assertFalse($error['ok']);
        $this->assertNotEmpty($error['errorkey']);
        $this->assertSame('', $error['errorclass']);
        $this->assertSame('', $error['page']);
    }
    public function test_folder_browser_returns_owned_folders_and_translated_external_errors(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $instanceid = $this->create_webdav_instance($USER);
        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake->seed_folder('/Coursepilot');
        $fake->seed_folder('/Coursepilot/Lessons');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        try {
            $response = http_endpoint::browse($instanceid, '');
            $this->assertSame(200, $response->status);
            $state = json_decode($response->body, true)['state'];
            $this->assertSame([['name' => 'Lessons']], $state['browse']['folders']);
            $fake->deny_auth(403);
            $failed = json_decode(http_endpoint::browse($instanceid, '')->body, true);
            $this->assertSame('locationselectionexternalerror', $failed['errorkey']);
            $this->assertNotEmpty($failed['errorclass']);
            $this->assertNotEmpty($failed['page']);
        } finally {
            \core\di::reset_container();
        }
    }

    public function test_mcp_headers_preserve_cgi_precedence_and_header_case(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = \local_coursepilot\connection::issue_code('client', (int) $USER->id, 'https://client.example/cb', $challenge);
        $tokens = \local_coursepilot\connection::exchange_code($code, 'client', 'https://client.example/cb', $verifier);
        $request = '{"jsonrpc":"2.0","id":1,"method":"initialize"}';
        $this->assertSame(200, http_endpoint::mcp($request, [
            'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token'],
        ], [])->status);
        $this->assertSame(200, http_endpoint::mcp($request, [], [
            'X-Other' => 'ignored', 'aUtHoRiZaTiOn' => 'Bearer ' . $tokens['access_token'],
        ])->status);
        $this->assertSame(401, http_endpoint::mcp($request, [
            'HTTP_AUTHORIZATION' => 'Basic invalid', 'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token'],
        ], [])->status);
        $this->assertSame(400, http_endpoint::mcp('null', [], [])->status);
        $empty = http_endpoint::mcp('{"jsonrpc":"2.0","method":"notifications/initialized"}', [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens['access_token'],
        ], []);
        $this->assertSame('', $empty->body);
        $this->assertArrayNotHasKey('Content-Type', $empty->headers);
        $this->assertSame([], http_endpoint::request_headers(), 'CLI has no separate webserver header API.');
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function test_http_sender_delivers_original_bytes_and_json_replaces_invalid_utf8(): void {
        $response = http_response::json(
            ['status' => 200, 'headers' => [], 'body' => ['text' => "Bad \xFF"]],
            JSON_INVALID_UTF8_SUBSTITUTE
        );
        $this->assertSame(['text' => "Bad \u{FFFD}"], json_decode($response->body, true));
        ob_start();
        $response->send();
        $body = ob_get_clean();
        $this->assertSame($response->body, $body);
        $this->assertSame(200, http_response_code());
    }
}
