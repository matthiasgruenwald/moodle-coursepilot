<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * JSON-Endpunkt fuer das Dateifenster der Ortswahlseite (Issue #494, Spec
 * #486 §5): listet eine Ebene einer eigenen WebDAV-Nutzerinstanz, ueber
 * {@see \local_kurspilot\ortswahl_lib::browse()} - nie serverseitig
 * gespeichert oder protokolliert, nie an die KI gereicht (diese Seite ist
 * kein MCP-Endpunkt, nur die Ortswahlseite selbst ruft sie per fetch() auf).
 *
 * Duenne Schale (#334-Muster): keine Logik hier, nur Ein-/Ausgabe.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_kurspilot\ortswahl_lib;

require_login(null, false);
require_sesskey();
require_capability('local/kurspilot:useremote', context_system::instance());

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$instanceid = required_param('instanceid', PARAM_INT);
$path = optional_param('path', '', PARAM_RAW_TRIMMED);

try {
    $result = ortswahl_lib::browse($instanceid, $path);
    echo json_encode(['ok' => true] + $result);
} catch (moodle_exception $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
