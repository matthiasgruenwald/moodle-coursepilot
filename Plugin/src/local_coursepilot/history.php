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
 * Verlaufsseite an der Kursnavigation (#397, Spec 0015 §10.6/§10.7,
 * Phase 4): die Lehrkraft sieht und schreibt den Verlauf auch ohne
 * laufenden Chat - direkt in Moodle, Vorbild Papierkorb. Kein
 * Coursepilot-Freigabeakt: die Lehrkraft handelt hier selbst, wie im
 * Modulformular.
 *
 * Duenne Schale (#334-Muster): die Datenaufbereitung lebt in
 * {@see \local_coursepilot\history\version_history}, das eigentliche
 * Zurueckschreiben unveraendert in
 * {@see \local_coursepilot\external\restore_activity_version} - direkt
 * aufgerufen, ohne Webservice-Layer (die Lehrkraft ist bereits eingeloggt),
 * aber mit denselben Capability-Pruefungen und Schutzschienen wie ueber
 * den Chat (Abnahmekriterium 4). Diese Seite legt keine eigene Zeile in
 * local_coursepilot_cm_version an und liest die Tabelle nirgends direkt
 * ausser der reinen Existenzpruefung in
 * {@see version_history::course_activities()} - die Speicherung des
 * Verlaufs bleibt von dieser Oberflaeche getrennt, herauslösbar als
 * eigenstaendiges Plugin (Abnahmekriterium 7).
 *
 * Zwei Modi ueber die Query-Parameter:
 * - ?id=<courseid>: Liste der Aktivitaeten des Kurses mit Verlauf.
 * - ?cmid=<cmid>: Versionen einer Aktivitaet, mit Zurueckschreiben.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\external\restore_activity_version;
use local_coursepilot\history\version_history;

$cmid = optional_param('cmid', 0, PARAM_INT);
$courseid = optional_param('id', 0, PARAM_INT);
$restoreversion = optional_param('restore', 0, PARAM_INT);
$confirmed = optional_param('confirmed', 0, PARAM_BOOL);
$bestaetigt = optional_param('bestaetigt', 0, PARAM_BOOL);

if ($cmid) {
    $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
    $course = get_course($cm->course);
    $modcontext = context_module::instance($cm->id);
    $pageurl = new moodle_url('/local/coursepilot/history.php', ['cmid' => $cmid]);
} else if ($courseid) {
    $course = get_course($courseid);
    $pageurl = new moodle_url('/local/coursepilot/history.php', ['id' => $courseid]);
} else {
    throw new moodle_exception('invalidcoursemoduleid', 'error');
}

require_login($course, true, $cmid ? $cm : null);
$coursecontext = context_course::instance($course->id);
// local/coursepilot:viewhistory ist auf CONTEXT_COURSE definiert (db/access.php) -
// im cmid-Modus deshalb am Kurskontext geprueft, nicht am Modulkontext.
require_capability('local/coursepilot:viewhistory', $coursecontext);

// local/coursepilot:restoreversion UND moodle/course:manageactivities (Spec 0015
// §10.7) - nur mit beiden ist Zurueckschreiben moeglich, sonst reines Ansehen
// (Abnahmekriterium 5). Einmal berechnet, sowohl fuer den Schreibzweig als
// auch fuer die Anzeige der Zurueckschreiben-Links.
$canrestore = $cmid
    && has_capability('local/coursepilot:restoreversion', $modcontext)
    && has_capability('moodle/course:manageactivities', $modcontext);

$PAGE->set_context($cmid ? $modcontext : $coursecontext);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('historytitle', 'local_coursepilot'));
$PAGE->set_heading($course->fullname);

$listurl = new moodle_url('/local/coursepilot/history.php', ['id' => $course->id]);

// Zurueckschreiben: erst Bestaetigung, dann Ausfuehrung (Abnahmekriterium 3).
if ($cmid && $restoreversion) {
    // Beide Faehigkeiten einzeln erzwingen statt nur $canrestore abzufragen:
    // jede der beiden wirft ihre eigene required_capability_exception, wenn
    // sie fehlt - ohne restoreversion ODER ohne manageactivities ist der
    // Restore-Zweig dieser Seite gar nicht erreichbar (Abnahmekriterium 5).
    require_capability('local/coursepilot:restoreversion', $modcontext);
    require_capability('moodle/course:manageactivities', $modcontext);

    $viewurl = new moodle_url('/local/coursepilot/history.php', ['cmid' => $cmid]);

    if (!$confirmed) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('historyrestoreconfirm', 'local_coursepilot', $restoreversion),
            new moodle_url('/local/coursepilot/history.php', [
                'cmid' => $cmid,
                'restore' => $restoreversion,
                'confirmed' => 1,
                'sesskey' => sesskey(),
            ]),
            $viewurl
        );
        echo $OUTPUT->footer();
        exit;
    }

    require_sesskey();
    try {
        $result = restore_activity_version::execute($cmid, $restoreversion, (bool) $bestaetigt);
        redirect($viewurl, $result['meldung'], null, \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $e) {
        if ($e->errorcode !== 'completiondatalossconfirmationrequired' || $bestaetigt) {
            throw $e;
        }
        // set_completion's Zweitakt (Ticket #392) greift ueber
        // restore_activity_version unveraendert - die Seite fragt hier
        // erneut nach, statt das Loeschen von Abschlussdaten stillschweigend
        // zu ueberspringen.
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('historydatalossconfirm', 'local_coursepilot', $e->getMessage()),
            new moodle_url('/local/coursepilot/history.php', [
                'cmid' => $cmid,
                'restore' => $restoreversion,
                'confirmed' => 1,
                'bestaetigt' => 1,
                'sesskey' => sesskey(),
            ]),
            $viewurl
        );
        echo $OUTPUT->footer();
        exit;
    }
}

echo $OUTPUT->header();

if ($cmid) {
    $data = version_history::list_versions($cmid);
    $newest = $data['versionen'] ? end($data['versionen'])['version'] : null;

    echo $OUTPUT->heading(format_string($cm->name), 3);
    echo html_writer::tag('p', get_string('historyintro', 'local_coursepilot'));

    if ($data['modname'] === 'quiz') {
        echo $OUTPUT->notification(get_string('historyquizhint', 'local_coursepilot'), \core\output\notification::NOTIFY_INFO);
    }

    $table = new html_table();
    $table->head = [
        get_string('historycolversion', 'local_coursepilot'),
        get_string('historycoluser', 'local_coursepilot'),
        get_string('historycoltime', 'local_coursepilot'),
        get_string('historycolchange', 'local_coursepilot'),
        '',
    ];
    foreach ($data['versionen'] as $row) {
        $action = '';
        if ($canrestore && $row['version'] !== $newest) {
            $restoreurl = new moodle_url('/local/coursepilot/history.php', ['cmid' => $cmid, 'restore' => $row['version']]);
            $action = html_writer::link($restoreurl, get_string('historyrestore', 'local_coursepilot'));
        }
        $table->data[] = [
            $row['version'],
            s($row['nutzer']),
            userdate($row['zeitpunkt']),
            s($row['einzeiler']),
            $action,
        ];
    }
    echo html_writer::table($table);

    echo html_writer::tag('p', s($data['hinweis_luecken']));
    echo html_writer::link($listurl, get_string('historybacktolist', 'local_coursepilot'));
} else {
    $activities = version_history::course_activities($course->id);
    echo $OUTPUT->heading(get_string('historytitle', 'local_coursepilot'), 2);
    echo html_writer::tag('p', get_string('historyintro', 'local_coursepilot'));

    if (!$activities) {
        echo $OUTPUT->notification(get_string('historynoactivities', 'local_coursepilot'), \core\output\notification::NOTIFY_INFO);
    } else {
        $table = new html_table();
        $table->head = [
            get_string('historycolname', 'local_coursepilot'),
            get_string('historycoltype', 'local_coursepilot'),
            '',
        ];
        foreach ($activities as $activity) {
            $viewurl = new moodle_url('/local/coursepilot/history.php', ['cmid' => $activity['cmid']]);
            $table->data[] = [
                s($activity['name']),
                s($activity['modname']),
                html_writer::link($viewurl, get_string('historyview', 'local_coursepilot')),
            ];
        }
        echo html_writer::table($table);
    }
}

echo $OUTPUT->footer();
