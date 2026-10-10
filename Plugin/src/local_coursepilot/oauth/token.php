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
 * Token endpoint (#336): authorization code and rotated refresh tokens,
 * with one-hour access and 30-day refresh lifetimes.
 *
 * Thin I/O shell (#334): reads method, Content-Type and form/JSON body
 * and delegates to {@see \local_coursepilot\oauth_lib::handle_token()}.
 * Grant dispatch, client_secret_post checks and required-field validation
 * are PHPUnit-testable without a web server. Only this shell exits.
 * Errors always use JSON: Moodle HTML 404 breaks opencode parsing (#312).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use local_coursepilot\oauth_lib;

// Accept both form and JSON bodies. RFC 6749 requires
// application/x-www-form-urlencoded, but some MCP clients send JSON.
$contenttype = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contenttype, 'application/json')) {
    $decoded = json_decode(file_get_contents('php://input'), true);
    $body = is_array($decoded) ? $decoded : null;
} else {
    $body = $_POST;
}

$response = oauth_lib::handle_token($_SERVER['REQUEST_METHOD'] ?? 'POST', $body);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
header('Content-Type: application/json');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
