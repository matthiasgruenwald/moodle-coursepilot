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
 * Selbstverwaltungsseite (#338): eine Lehrkraft sieht ausschliesslich die
 * eigenen aktiven Fernzugriffsverbindungen und kann einzelne widerrufen -
 * nie fremde, weil oauth_lib::active_tokens_for_user()/revoke_token() die
 * Eigentuemerschaft direkt in der Abfrage erzwingen, nicht nur in der
 * Anzeige. Aus dem Profil verlinkt (siehe lib.php,
 * local_coursepilot_myprofile_navigation()).
 *
 * Duenne Schale (#334-Muster): die eigentliche Logik lebt testbar in
 * {@see \local_coursepilot\oauth_lib}, diese Datei tut nur noch Ein-/Ausgabe.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\oauth_lib;
use local_coursepilot\output\connections_page;
use local_coursepilot\output\location_selection as location_selection_output;
use local_coursepilot\webdav\webdav_setup_steps;

require_login(null, false);

global $USER;
$context = context_system::instance();
require_capability('local/coursepilot:useremote', $context);
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursepilot/connections.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myconnections', 'local_coursepilot'));
$PAGE->set_heading(get_string('myconnections', 'local_coursepilot'));

$revokeid = optional_param('revoke', 0, PARAM_INT);
if ($revokeid) {
    require_sesskey();
    // $USER->id als Eigentuemerfilter - eine fremde ID revoked hier nichts.
    oauth_lib::revoke_token($revokeid, (int) $USER->id);
    redirect(new moodle_url('/local/coursepilot/connections.php'));
}

$tokens = oauth_lib::active_tokens_for_user((int) $USER->id);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_coursepilot/connections', connections_page::page_data(
    $tokens,
    location_selection_output::current_locations_data(),
    new moodle_url(webdav_setup_steps::ORTSWAHL_PAGE)
));
echo $OUTPUT->footer();
