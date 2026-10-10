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
 * JSON endpoint for the location-selection folder browser (#494, Spec
 * #486 §5). Lists one level of an owned WebDAV instance through
 * {@see \local_coursepilot\location_selection::browse()}. Never persists
 * or logs results or sends them to the model: this is not an MCP endpoint,
 * only the location-selection page calls it through fetch().
 *
 * Thin I/O shell (#334), without domain logic.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\location_selection;

require_login(null, false);
require_sesskey();
\local_coursepilot\remote_access::require_granted();

global $USER;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$instanceid = required_param('instanceid', PARAM_INT);
$path = optional_param('path', '', PARAM_RAW_TRIMMED);

try {
    $result = location_selection::browse($instanceid, $path);
    echo json_encode(['ok' => true, 'state' => location_selection::page_state((int) $USER->id, $result)]);
} catch (moodle_exception $e) {
    http_response_code(400);
    // Issue #565: the client translates errorkey on display through core/str,
    // never stores a rendered sentence in page state. It needs the same
    // placeholders as server rendering: named error class and location-page
    // reference; see pointer_reader::webdav_exception().
    echo json_encode([
        'ok' => false,
        'errorkey' => $e->errorcode,
        'errorclass' => $e->a->errorclass ?? '',
        'page' => $e->a->page ?? '',
    ]);
}
