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
 * Administrationsuebersicht (#338): alle aktiven Fernzugriffsverbindungen
 * ueber alle Personen, mit Einzelwiderruf und Sammelwiderruf. Ohne
 * 'moodle/site:config' nicht erreichbar - require_capability() unten greift
 * unabhaengig davon, ob die Seite ueber den Administrationsbaum
 * (settings.php, ebenfalls capability-geschuetzt) oder direkt per URL
 * aufgerufen wird.
 *
 * Duenne Schale (#334-Muster): die eigentliche Logik lebt testbar in
 * {@see \local_kurspilot\oauth_lib}, diese Datei tut nur noch Ein-/Ausgabe.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use local_kurspilot\admin\connection_ablageort;
use local_kurspilot\oauth_lib;

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/kurspilot/admin/connections.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('connections', 'local_kurspilot'));
$PAGE->set_heading(get_string('connections', 'local_kurspilot'));

$revokeid = optional_param('revoke', 0, PARAM_INT);
if ($revokeid) {
    require_sesskey();
    oauth_lib::revoke_token($revokeid);
    redirect(new moodle_url('/local/kurspilot/admin/connections.php'));
}

$revokeall = optional_param('revokeall', 0, PARAM_BOOL);
if ($revokeall) {
    require_sesskey();
    oauth_lib::revoke_all_tokens();
    redirect(new moodle_url('/local/kurspilot/admin/connections.php'));
}

$tokens = oauth_lib::active_tokens();

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('connectionsintro', 'local_kurspilot'));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/kurspilot/admin/connections.php'))->out(false),
    'onsubmit' => 'return confirm(' . json_encode(get_string('connectionrevokeallconfirm', 'local_kurspilot')) . ');',
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'revokeall', 'value' => 1]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('connectionrevokeall', 'local_kurspilot')]);
echo html_writer::end_tag('form');

if (!$tokens) {
    echo $OUTPUT->notification(
        get_string('connectionnoconnections', 'local_kurspilot'),
        \core\output\notification::NOTIFY_INFO
    );
} else {
    $table = new html_table();
    $table->head = [
        get_string('connectionperson', 'local_kurspilot'),
        get_string('connectionclient', 'local_kurspilot'),
        get_string('connectionsince', 'local_kurspilot'),
        get_string('connectionexpires', 'local_kurspilot'),
        get_string('connectionablageort', 'local_kurspilot'),
        '',
    ];
    foreach ($tokens as $tokenrecord) {
        $revokeurl = new moodle_url('/local/kurspilot/admin/connections.php', [
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
            html_writer::link($revokeurl, get_string('connectionrevoke', 'local_kurspilot')),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
