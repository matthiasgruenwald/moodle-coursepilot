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
 * Dynamic Client Registration (RFC 7591, #335).
 *
 * Unauthentifiziert erreichbar wie bei jedem DCR-Endpunkt - der Schutz liegt
 * bei authorize.php (Moodle-Login, #336), nicht hier.
 *
 * Reine Schale (#334-Muster): liest Methode und JSON-Rumpf ein, uebergibt an
 * {@see \local_coursepilot\oauth_lib::handle_registration()}.
 *
 * ponytail: kein Rate-Limiting auf diesem Endpunkt - jede unauthentifizierte
 * POST-Anfrage legt bei gueltigen redirect_uris einen neuen Client-Datensatz
 * an. Fuer die Spike-Instanz (kleiner, bekannter Nutzerkreis) kein akutes
 * Risiko; natuerlicher Ort fuer eine Drossel ist #338 (Fernzugriffs-
 * Steuerung), sobald die Instanz oeffentlich erreichbar ist.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use local_coursepilot\oauth_lib;

$decoded = json_decode(file_get_contents('php://input'), true);
$body = is_array($decoded) ? $decoded : null;

$response = oauth_lib::handle_registration($_SERVER['REQUEST_METHOD'] ?? 'POST', $body);

http_response_code($response['status']);
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
header('Content-Type: application/json');
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES);
