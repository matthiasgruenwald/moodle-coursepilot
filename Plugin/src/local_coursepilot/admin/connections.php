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
 * Administrationsuebersicht (#338): alle aktiven Fernzugriffsverbindungen
 * ueber alle Personen, mit Einzelwiderruf und Sammelwiderruf. Ohne
 * 'moodle/site:config' nicht erreichbar - require_capability() unten greift
 * unabhaengig davon, ob die Seite ueber den Administrationsbaum
 * (settings.php, ebenfalls capability-geschuetzt) oder direkt per URL
 * aufgerufen wird.
 *
 * Duenne Schale (#334-Muster): die eigentliche Logik lebt testbar in
 * {@see \local_coursepilot\oauth_lib}, diese Datei tut nur noch Ein-/Ausgabe.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use local_coursepilot\oauth_lib;
use local_coursepilot\output\admin_connections_page;

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursepilot/admin/connections.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('connections', 'local_coursepilot'));
$PAGE->set_heading(get_string('connections', 'local_coursepilot'));

$revokeid = optional_param('revoke', 0, PARAM_INT);
if ($revokeid) {
    require_sesskey();
    oauth_lib::revoke_token($revokeid);
    redirect(new moodle_url('/local/coursepilot/admin/connections.php'));
}

$revokeall = optional_param('revokeall', 0, PARAM_BOOL);
if ($revokeall) {
    require_sesskey();
    oauth_lib::revoke_all_tokens();
    redirect(new moodle_url('/local/coursepilot/admin/connections.php'));
}

$tokens = oauth_lib::active_tokens();

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursepilot/admin_connections', admin_connections_page::page_data($tokens));
echo $OUTPUT->footer();
