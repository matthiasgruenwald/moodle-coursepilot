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
 * Single-use workbench download endpoint (#501, Spec #486 §13). The URL
 * ticket is the sole authorization, without Moodle login. A dedicated route
 * avoids webservice/pluginfile.php (ignores OAuth Bearer) and
 * tokenpluginfile.php (overly broad access).
 *
 * Thin wrapper like oauth/token.php (#334): pass the ticket to
 * workbench_ticket::redeem(), whose validation and consumption logic is
 * unit-testable without a webserver. Ignore Range headers and always
 * deliver the complete file.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use local_coursepilot\access_log;
use local_coursepilot\workbench_ticket;

$toolname = 'coursepilot_workbench_download';

// PARAM_ALPHANUM matches oauth_lib::random_token() hexadecimal secrets.
// Update this validation if the format changes, for example to base64url.
$ticket = optional_param('ticket', '', PARAM_ALPHANUM);

if ($ticket === '') {
    http_response_code(404);
    access_log::log_failure('workbenchticketinvalid', $toolname);
    header('Content-Type: application/json');
    echo json_encode(['error' => get_string('workbenchticketinvalid', 'local_coursepilot')]);
    return;
}

try {
    $delivery = workbench_ticket::redeem($ticket);
} catch (\local_coursepilot\workbench_ticket_redemption_failed $e) {
    http_response_code(403);
    // Log the fixed reason key, never the ticket secret (Spec #486 §13).
    // The path is known once the ticket is found; only unknown tickets have null.
    access_log::log_failure($e->errorcode, $toolname, $e->path);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    return;
}

access_log::log_success($toolname, false, $delivery['path'], $delivery['userid']);

header('Content-Type: ' . $delivery['mimetype']);
header('Content-Length: ' . $delivery['size']);
header('Content-Disposition: attachment; filename="' . rawurlencode($delivery['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $delivery['content'];
