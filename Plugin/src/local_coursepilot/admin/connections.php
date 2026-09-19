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

use local_coursepilot\admin\connection_ablageort;
use local_coursepilot\oauth_lib;

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
echo html_writer::tag('p', get_string('connectionsintro', 'local_coursepilot'));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/coursepilot/admin/connections.php'))->out(false),
    'onsubmit' => 'return confirm(' . json_encode(get_string('connectionrevokeallconfirm', 'local_coursepilot')) . ');',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revokeall', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('connectionrevokeall', 'local_coursepilot')]);
echo html_writer::end_tag('form');

if (!$tokens) {
    echo $OUTPUT->notification(
        get_string('connectionnoconnections', 'local_coursepilot'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $table = new html_table();
    $table->head = [
        get_string('connectionperson', 'local_coursepilot'),
        get_string('connectionclient', 'local_coursepilot'),
        get_string('connectionsince', 'local_coursepilot'),
        get_string('connectionexpires', 'local_coursepilot'),
        get_string('connectionablageort', 'local_coursepilot'),
        '',
    ];
    foreach ($tokens as $tokenrecord) {
        $revokeurl = new moodle_url('/local/coursepilot/admin/connections.php', [
            'revoke' => $tokenrecord->id,
            'sesskey' => sesskey(),
        ]);
        $storagelocation = connection_ablageort::describe((int) $tokenrecord->userid);
        $storagelocationlines = array_merge(array_values($storagelocation['targets']), $storagelocation['markers']);
        $table->data[] = [
            s(fullname($tokenrecord) . ' (' . $tokenrecord->email . ')'),
            s($tokenrecord->clientname ?: $tokenrecord->clientid),
            userdate($tokenrecord->timecreated),
            userdate($tokenrecord->expires),
            html_writer::alist(array_map('s', $storagelocationlines), ['class' => 'unlist m-0']),
            html_writer::link($revokeurl, get_string('connectionrevoke', 'local_coursepilot')),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
