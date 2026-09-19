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
 * MCP-Endpunkt: POST-only, JSON, zustandslos, dual-era (Legacy 2025-* mit
 * initialize-Handshake, modern 2026-07-28 mit server/discover).
 *
 * Reine Schale (#334): liest Request-Rumpf, Bearer-Token und Header ein,
 * uebergibt an {@see \local_coursepilot\dispatcher::handle()} - die eigentliche
 * Entscheidungslogik (Auth-Gate, Datenschutz-Vertragspruefung aus ADR 0011)
 * lebt dort und ist ohne diese Datei per PHPUnit testbar. Diese Datei bleibt
 * bewusst ungetestet: sie tut nichts als Ein-/Ausgabe.
 *
 * Belegt durch Recherche #290 und Prototyp #294.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

// WS_SERVER ist Pflicht, nicht NO_MOODLE_COOKIES: ohne WS_SERVER scheitert
// external_api::call_external_function() an 'servicerequireslogin'
// (external_api.php:216) - Fund aus dem Prototypen #294.
define('WS_SERVER', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use local_coursepilot\dispatcher;

raise_memory_limit(MEMORY_EXTRA);
\core_external\external_api::set_timeout();

/**
 * Liest das Bearer-Token. Unter CGI/FastCGI kommt der Authorization-Header
 * teils nur als REDIRECT_HTTP_AUTHORIZATION an (Recherche #290, 6.1).
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
    // Die nach dem Handshake ausgehandelte Protokoll-Revision (#400): sie
    // entscheidet, ob die Antwort die Ergebnis-Metadaten der Revision
    // 2026-07-28 traegt, siehe dispatcher::resultmeta().
    'protocolversion' => $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null,
]);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
if ($response['body'] !== null) {
    header('Content-Type: application/json');
    // JSON_INVALID_UTF8_SUBSTITUTE statt Absturz auf ungueltigen Bytes:
    // json_encode() liefert sonst kommentarlos false (leerer Rumpf trotz
    // Status 200 und Content-Type application/json) sobald irgendein
    // Textfeld im Ergebnis ungueltiges UTF-8 enthaelt - z.B. Kurs-/
    // Abschnittsnamen aus einem restaurierten Datenbestand mit alten
    // Latin-1-Resten. Der Client sieht dann keine JSON-RPC-Fehlermeldung,
    // nur einen nicht auswertbaren leeren Rumpf.
    echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
exit;
