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
 * Token-Endpunkt (#336): Authorization Code + Refresh mit Rotation, 1h
 * Zugriffs- / 30 Tage Erneuerungstoken.
 *
 * Duenne Schale (#334-Muster): liest Methode, Content-Type und Rumpf ein
 * (Formular- oder JSON-Body - das Unterscheiden ist Ein-/Ausgabe), uebergibt
 * an {@see \local_coursepilot\oauth_lib::handle_token()}. Die eigentliche
 * Entscheidungslogik (Grant-Type-Dispatch, client_secret_post-Pruefung,
 * Pflichtfeld-Validierung) lebt dort als reine, per PHPUnit ohne laufenden
 * Webserver pruefbare Methode - kein exit() in der Entscheidungslogik, nur
 * hier in der Schale. Fehlerantworten sind immer JSON (Moodles HTML-404
 * killt opencodes Parser, Fund aus #312).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use local_coursepilot\oauth_lib;

// Sowohl Formular- als auch JSON-Bodies zulassen - RFC 6749 verlangt
// application/x-www-form-urlencoded, manche MCP-Clients senden trotzdem JSON.
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
