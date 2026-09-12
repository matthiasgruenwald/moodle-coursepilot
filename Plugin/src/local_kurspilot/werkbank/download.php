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
 * Einmal-Downloadendpunkt fuer Werkbankdateien (#501, Spec #486 §13): eigener
 * Endpunkt, weder `webservice/pluginfile.php` (ignoriert den OAuth-Bearer)
 * noch `tokenpluginfile.php` (zu breit) - das Ticket in der URL ist der
 * einzige Berechtigungsnachweis, kein Moodle-Login.
 *
 * Duenne Schale (#334-Muster wie oauth/token.php): liest den Ticketparameter
 * ein, uebergibt an {@see \local_kurspilot\werkbank_ticket::redeem()}. Die
 * eigentliche Pruef-/Verbrauchslogik lebt dort, per PHPUnit ohne laufenden
 * Webserver pruefbar. Ohne Range-Unterstuetzung: ein etwaiger Range-Header
 * des Clients wird schlicht nie gelesen, jede Auslieferung ist vollstaendig.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../config.php');

use local_kurspilot\access_log;
use local_kurspilot\werkbank_ticket;

$toolname = 'kurspilot_werkbank_download';

// PARAM_ALPHANUM passt zum Geheimnisformat aus oauth_lib::random_token()
// (bin2hex() - reine Hex-Zeichen); bei einem kuenftig anderen Tokenformat
// (z.B. base64url) muss diese Zeile mitziehen.
$ticket = optional_param('ticket', '', PARAM_ALPHANUM);

if ($ticket === '') {
    http_response_code(404);
    access_log::log_failure('werkbankticketinvalid', $toolname);
    header('Content-Type: application/json');
    echo json_encode(['error' => get_string('werkbankticketinvalid', 'local_kurspilot')]);
    return;
}

try {
    $delivery = werkbank_ticket::redeem($ticket);
} catch (\local_kurspilot\werkbank_ticket_redemption_failed $e) {
    http_response_code(403);
    // Der Grund geht ins Zugriffsprotokoll, das Ticket selbst nie (Spec
    // #486 §13) - $e->errorcode ist der feste Sprachschluessel, kein
    // Freitext mit Geheimnisbezug. $e->path ist bekannt, sobald das Ticket
    // selbst gefunden wurde (nur beim unbekannten Ticket bleibt er null).
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
