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

/**
 * Adapts public HTTP requests to the tested Moodle domain interfaces.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class http_endpoint {
    /**
     * Returns an owned folder listing or the browser's established error envelope.
     *
     * @param int $instanceid Owned WebDAV instance.
     * @param string $path Relative folder path.
     * @return http_response Browser state; never stored or sent to an AI client.
     */
    public static function browse(int $instanceid, string $path): http_response {
        global $USER;
        \local_coursepilot\remote_access::require_granted();
        $headers = ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'];
        try {
            $result = \local_coursepilot\location_selection::browse($instanceid, $path);
            $body = ['ok' => true, 'state' => \local_coursepilot\location_selection::page_state((int) $USER->id, $result)];
            return new http_response(200, $headers, (string) json_encode($body));
        } catch (\moodle_exception $e) {
            return new http_response(400, $headers, (string) json_encode([
                'ok' => false,
                'errorkey' => $e->errorcode,
                'errorclass' => $e->a->errorclass ?? '',
                'page' => $e->a->page ?? '',
            ]));
        }
    }

    /**
     * Redeems an anonymous single-use ticket and logs only the fixed failure reason.
     *
     * @param string $ticket Opaque URL ticket.
     * @return http_response Full download bytes or the established JSON failure.
     */
    public static function download(string $ticket): http_response {
        $toolname = 'coursepilot_workbench_download';
        if ($ticket === '') {
            \local_coursepilot\access_log::log_failure('workbenchticketinvalid', $toolname);
            return http_response::json(['status' => 404, 'headers' => [],
                'body' => ['error' => get_string('workbenchticketinvalid', 'local_coursepilot')]], 0);
        }
        try {
            $delivery = \local_coursepilot\workbench_ticket::redeem($ticket);
        } catch (\local_coursepilot\workbench_ticket_redemption_failed $e) {
            \local_coursepilot\access_log::log_failure($e->errorcode, $toolname, $e->path);
            return http_response::json(['status' => 403, 'headers' => [], 'body' => ['error' => $e->getMessage()]], 0);
        }
        \local_coursepilot\access_log::log_success($toolname, false, $delivery['path'], $delivery['userid']);
        return new http_response(200, [
            'Content-Type' => $delivery['mimetype'],
            'Content-Length' => (string) $delivery['size'],
            'Content-Disposition' => 'attachment; filename="' . rawurlencode($delivery['filename']) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $delivery['content']);
    }

    /**
     * Accepts both RFC 6749 form bodies and JSON sent by MCP clients.
     *
     * @param string $method HTTP method.
     * @param string $contenttype Request Content-Type.
     * @param string $rawbody Raw request bytes.
     * @param mixed[] $post Parsed form fields.
     * @return http_response The OAuth token response.
     */
    public static function token(string $method, string $contenttype, string $rawbody, array $post): http_response {
        if (str_contains($contenttype, 'application/json')) {
            $decoded = json_decode($rawbody, true);
            $body = is_array($decoded) ? $decoded : null;
        } else {
            $body = $post;
        }
        return http_response::json(\local_coursepilot\connection::handle_token($method, $body));
    }

    /**
     * Dispatches an MCP request, retaining CGI and case-insensitive header support.
     *
     * @param string $rawbody Request JSON bytes.
     * @param string[] $server PHP server request metadata.
     * @param string[] $headers HTTP headers when PHP exposes them separately.
     * @return http_response MCP HTTP status, headers and encoded JSON.
     */
    public static function mcp(string $rawbody, array $server, array $headers): http_response {
        $decoded = json_decode($rawbody, true);
        $response = dispatcher::handle(is_array($decoded) ? $decoded : null, self::bearer($server, $headers), [
            'origin' => $server['HTTP_ORIGIN'] ?? null,
            'pathinfo' => $server['PATH_INFO'] ?? '',
            'method' => $server['REQUEST_METHOD'] ?? 'POST',
            'protocolversion' => $server['HTTP_MCP_PROTOCOL_VERSION'] ?? null,
        ]);
        return http_response::json($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Reads webserver headers where the PHP SAPI provides them.
     *
     * @return string[] Actual request headers, or empty for a SAPI without that API.
     */
    public static function request_headers(): array {
        return function_exists('getallheaders') ? getallheaders() : [];
    }

    /**
     * Resolves a Bearer token using the published server-variable precedence.
     *
     * @param string[] $server PHP request metadata.
     * @param string[] $headers Additional HTTP headers.
     * @return string|null Parsed opaque token, or null for other authorization schemes.
     */
    private static function bearer(array $server, array $headers): ?string {
        $header = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '') {
            foreach ($headers as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            return $matches[1];
        }
        return null;
    }
}
