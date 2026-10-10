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

/**
 * MCP endpoint: POST-only, JSON, stateless, supporting legacy 2025-*
 * initialize handshake and modern 2026-07-28 server/discover.
 *
 * I/O shell (#334): reads body, Bearer token and headers, then delegates
 * to {@see \local_coursepilot\dispatcher::handle()}. Authentication
 * and the ADR 0011 privacy contract are tested there with PHPUnit.
 * This pure I/O file deliberately has no separate tests.
 *
 * Established by research #290 and prototype #294.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

// WS_SERVER is required, not NO_MOODLE_COOKIES: otherwise
// external_api::call_external_function() fails with servicerequireslogin
// (external_api.php:216; prototype #294 finding).
define('WS_SERVER', true);
define('NO_DEBUG_DISPLAY', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing -- Bearer-token endpoint; dispatcher authenticates every request itself.
require(__DIR__ . '/../../config.php');

use local_coursepilot\dispatcher;

raise_memory_limit(MEMORY_EXTRA);
\core_external\external_api::set_timeout();

/**
 * Reads the Bearer token. Under CGI/FastCGI the Authorization header
 * may arrive only as REDIRECT_HTTP_AUTHORIZATION (research #290, §6.1).
 *
 * @return string|null
 */
function coursepilot_mcp_bearer_token(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if ($header === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
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

$decoded = json_decode(file_get_contents('php://input'), true);
$request = is_array($decoded) ? $decoded : null;

$response = dispatcher::handle($request, coursepilot_mcp_bearer_token(), [
    'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
    'pathinfo' => $_SERVER['PATH_INFO'] ?? '',
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'POST',
    // Negotiated protocol revision after handshake (#400): controls
    // 2026-07-28 result metadata; see dispatcher::resultmeta().
    'protocolversion' => $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null,
]);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
if ($response['body'] !== null) {
    header('Content-Type: application/json');
    // JSON_INVALID_UTF8_SUBSTITUTE handles invalid bytes instead of silently
    // returning false: otherwise even a 200 application/json reply has an
    // empty body when any result text has invalid UTF-8, such as restored
    // course/section names with old Latin-1 bytes. The client receives
    // no JSON-RPC error, just an unparseable empty body.
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
exit;
