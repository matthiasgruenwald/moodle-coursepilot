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
 * Die Ortswahlseite (Issue #494, Spec #486 §5/§10): eigene Profilseite neben
 * "Meine Verbindungen" - wo Kontextbereich und Materialbestand liegen. Nur
 * fuer die angemeldete Lehrkraft und ihre eigenen WebDAV-Nutzerinstanzen.
 *
 * Duenne Schale (#334-Muster): die gesamte Logik lebt testbar in
 * {@see \local_kurspilot\ortswahl_lib}, diese Datei tut nur noch Ein-/Ausgabe
 * (Formular entgegennehmen, Markup rendern) - siehe Issue #494
 * Akzeptanzkriterium "ueber ihre Klasse getestet, nicht ueber die Seite".
 *
 * Issue #507 (Spec #486, Review von #486): Seitenaufbau und Uebernahme der
 * Formulareingabe sind in Funktionen unter 50 Zeilen zerlegt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_kurspilot\altbestand;
use local_kurspilot\ortswahl_lib;
use local_kurspilot\pointer_location;
use local_kurspilot\webdav\webdav_setup_steps;

require_login(null, false);
$context = context_system::instance();
require_capability('local/kurspilot:useremote', $context);

global $USER, $OUTPUT, $PAGE;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(webdav_setup_steps::ORTSWAHL_PAGE));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('ortswahltitle', 'local_kurspilot'));
$PAGE->set_heading(get_string('ortswahlheading', 'local_kurspilot'));

$finishresult = local_kurspilot_handle_ortswahl_finish();

echo $OUTPUT->header();
local_kurspilot_render_ortswahl_page($finishresult, $USER);
echo $OUTPUT->footer();

/**
 * Nimmt eine abgeschickte Ortswahl entgegen (Issue #494, Spec §5) - nur wenn
 * ueberhaupt "finish" mitgeschickt wurde, sonst wird die Seite ohne
 * Formularverarbeitung nur angezeigt.
 *
 * @return array{type: string, text: string}|null
 */
function local_kurspilot_handle_ortswahl_finish(): ?array {
    if (!optional_param('finish', 0, PARAM_BOOL)) {
        return null;
    }
    require_sesskey();
    try {
        $changed = ortswahl_lib::apply(local_kurspilot_read_ortswahl_selection());
        return empty($changed)
            ? ['type' => \core\output\notification::NOTIFY_INFO, 'text' => get_string('ortswahlfinishnochange', 'local_kurspilot')]
            : ['type' => \core\output\notification::NOTIFY_SUCCESS, 'text' => get_string('ortswahlfinishsuccess', 'local_kurspilot', implode(', ', $changed))];
    } catch (moodle_exception $e) {
        return ['type' => \core\output\notification::NOTIFY_ERROR, 'text' => $e->getMessage()];
    }
}

/**
 * Liest die Formulareingabe je Ziel - roh, {@see \local_kurspilot\ortswahl_lib::apply()}
 * validiert Instanz, Pfad und Sperren.
 *
 * @return array<string, array{type: string, instanceid: int, path: string, confirmed: bool}>
 * @throws moodle_exception ortswahlselectioninvalid bei einem unbekannten Typ.
 */
function local_kurspilot_read_ortswahl_selection(): array {
    $selection = [];
    foreach (ortswahl_lib::TARGETS as $target) {
        $type = optional_param($target . '_type', pointer_location::MOODLE, PARAM_ALPHA);
        $selection[$target] = [
            'type' => $type,
            'instanceid' => optional_param($target . '_instanceid', 0, PARAM_INT),
            'path' => optional_param($target . '_path', '', PARAM_RAW_TRIMMED),
            'confirmed' => optional_param($target . '_confirmed', 0, PARAM_BOOL),
        ];
        if ($type !== pointer_location::MOODLE && $type !== pointer_location::EXTERN) {
            throw new moodle_exception('ortswahlselectioninvalid', 'local_kurspilot');
        }
    }
    return $selection;
}

/**
 * Rendert den gesamten Seiteninhalt zwischen Header und Footer.
 *
 * @param array{type: string, text: string}|null $finishresult Ergebnis von {@see local_kurspilot_handle_ortswahl_finish()}.
 * @param \stdClass $user
 */
function local_kurspilot_render_ortswahl_page(?array $finishresult, \stdClass $user): void {
    global $OUTPUT;

    if ($finishresult !== null) {
        echo $OUTPUT->notification($finishresult['text'], $finishresult['type']);
    }
    echo html_writer::tag('p', get_string('ortswahlintro', 'local_kurspilot'));
    local_kurspilot_render_altbestand_warning();
    local_kurspilot_render_ortswahl_setup_state($user);
    local_kurspilot_render_ortswahl_current_locations();
    local_kurspilot_render_ortswahl_history();
}

/**
 * Warnt, wenn noch Altbestand offen ist (Issue #498, Spec §486 §5) -
 * angezeigt unabhaengig davon, ob gerade ein neuer Wechsel bevorsteht; ein
 * Abschliessen trotz dieser Warnung verdraengt den offenen Altbestand
 * ({@see \local_kurspilot\ortswahl_lib::apply()}), seine Dateien bleiben
 * dabei unberuehrt.
 */
function local_kurspilot_render_altbestand_warning(): void {
    global $OUTPUT;
    if (altbestand::open()) {
        echo $OUTPUT->notification(get_string('ortswahlaltbestandopen', 'local_kurspilot'), \core\output\notification::NOTIFY_WARNING);
    }
}

/**
 * Rendert je Bereitschaftszustand ({@see ortswahl_lib::setup_state()}) den
 * passenden Leerzustand oder das Dateifenster.
 *
 * @param \stdClass $user
 */
function local_kurspilot_render_ortswahl_setup_state(\stdClass $user): void {
    global $PAGE;

    $state = ortswahl_lib::setup_state((int) $user->id);
    if ($state['state'] === ortswahl_lib::STATE_NOT_ENABLED) {
        local_kurspilot_render_ortswahl_not_enabled($user);
    } else if ($state['state'] === ortswahl_lib::STATE_NO_INSTANCE) {
        local_kurspilot_render_ortswahl_no_instance();
    } else {
        require_once(__DIR__ . '/ortswahl_render.php');
        local_kurspilot_render_ortswahl_editor($PAGE, $user);
    }
}

/**
 * Leerzustand "nicht freigeschaltet" (Issue #494): kopierbarer Text an die
 * Administration mit nur den fehlenden Schritten.
 *
 * @param \stdClass $user
 */
function local_kurspilot_render_ortswahl_not_enabled(\stdClass $user): void {
    global $OUTPUT;

    echo $OUTPUT->notification(get_string('ortswahlnotenabledtext', 'local_kurspilot'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->heading(get_string('ortswahlmissingstepsheading', 'local_kurspilot'), 4);
    echo html_writer::tag('p', get_string('ortswahlmissingstepsintro', 'local_kurspilot'));
    echo html_writer::tag('textarea', s(ortswahl_lib::missing_steps_text((int) $user->id)), [
        'class' => 'form-control', 'rows' => 4, 'readonly' => 'readonly', 'id' => 'kurspilot-ortswahl-missingsteps',
    ]);
    echo html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'supportcontact']),
        get_string('ortswahlcoresupportlink', 'local_kurspilot'),
        ['class' => 'btn btn-link px-0 mt-2']
    );
}

/**
 * Leerzustand "keine Instanz" (Issue #494): Schritt-fuer-Schritt-Anleitung
 * plus optionalem Schul-Hinweis.
 */
function local_kurspilot_render_ortswahl_no_instance(): void {
    global $OUTPUT;

    echo $OUTPUT->notification(get_string('ortswahlnoinstanceheading', 'local_kurspilot'), \core\output\notification::NOTIFY_INFO);
    echo html_writer::tag('p', get_string('ortswahlnoinstanceintro', 'local_kurspilot'));
    echo html_writer::start_tag('ol');
    echo html_writer::tag('li', get_string('ortswahlnoinstancestep1', 'local_kurspilot'));
    echo html_writer::tag('li', get_string('ortswahlnoinstancestep2', 'local_kurspilot'));
    echo html_writer::tag('li', get_string('ortswahlnoinstancestep3', 'local_kurspilot'));
    echo html_writer::end_tag('ol');

    $hint = ortswahl_lib::school_hint();
    if ($hint !== '') {
        echo $OUTPUT->heading(get_string('ortswahlschoolhintheading', 'local_kurspilot'), 5);
        echo html_writer::tag('p', s($hint));
    }
}

/**
 * Die aktuellen Orte beider Ziele (Issue #494). Das Markup selbst - inklusive
 * Escaping der Anzeigenamen (Issue #511, Sicherheitsbefund HIGH) - teilt sich
 * {@see local_kurspilot_current_locations_list_items()} mit connections.php.
 */
function local_kurspilot_render_ortswahl_current_locations(): void {
    global $OUTPUT;

    require_once(__DIR__ . '/ortswahl_render.php');
    echo $OUTPUT->heading(get_string('ortswahlcurrentheading', 'local_kurspilot'), 4);
    echo html_writer::tag('ul', local_kurspilot_current_locations_list_items());
}

/**
 * Der Ortsverlauf (Issue #494, Grundstruktur).
 */
function local_kurspilot_render_ortswahl_history(): void {
    global $OUTPUT;

    echo $OUTPUT->heading(get_string('ortswahlhistoryheading', 'local_kurspilot'), 4);
    $history = ortswahl_lib::history();
    if (empty($history)) {
        echo html_writer::tag('p', get_string('ortswahlhistoryempty', 'local_kurspilot'));
        return;
    }

    $table = new html_table();
    $table->head = [
        get_string('ortswahlhistorydate', 'local_kurspilot'),
        get_string('ortswahlhistorytarget', 'local_kurspilot'),
        get_string('ortswahlhistoryfrom', 'local_kurspilot'),
        get_string('ortswahlhistoryto', 'local_kurspilot'),
    ];
    foreach (array_reverse($history) as $entry) {
        $target = $entry['ziel'] === 'kontextbereich'
            ? get_string('ortswahltabkontextbereich', 'local_kurspilot')
            : get_string('ortswahltabmaterialbestand', 'local_kurspilot');
        $table->data[] = [
            userdate((int) ($entry['datum'] ?? 0)),
            $target,
            s((string) ($entry['von'] ?? '')),
            s((string) ($entry['nach'] ?? '')),
        ];
    }
    echo html_writer::table($table);
}
