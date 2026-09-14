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
 * Markup-Helfer fuer {@see ortswahl.php} (Issue #494): nur Bootstrap-Klassen
 * des Themes, kein Eigenbau-CSS. Ausgelagert, damit ortswahl.php selbst kurz
 * bleibt - reines Rendering, keine Entscheidungslogik (die lebt vollstaendig
 * in {@see \local_kurspilot\ortswahl_lib}).
 *
 * Issue #507 (Spec #486, Review von #486): in kleine Funktionen unter 50
 * Zeilen zerlegt - eine je Markup-Baustein, damit sich einzelne Bausteine
 * ohne Seiteneffekte auf den Rest aendern lassen.
 *
 * Issue #511 (Review von #486, Sicherheitsbefund HIGH): auch von
 * {@see connections.php} genutzt, damit die Kontextbereich-/Materialbestand-
 * Zeilen nur einmal escaped werden muessen statt an zwei Stellen getrennt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_kurspilot\ortswahl_lib;

/**
 * Rendert Reiter, Fortschrittsband, Dateifenster-Geruest und Abschliessen-
 * Knopf - das Dateifenster selbst fuellt {@see ortswahl.js} zur Laufzeit ueber
 * ortswahl_browse.php (Issue #494: Auflistungen werden nie serverseitig
 * gerendert oder gespeichert).
 *
 * @param \moodle_page $page
 * @param \stdClass $user
 */
function local_kurspilot_render_ortswahl_editor(\moodle_page $page, \stdClass $user): void {
    $data = local_kurspilot_ortswahl_editor_data();
    local_kurspilot_render_ortswahl_data_script($data);

    echo html_writer::start_div('kurspilot-ortswahl', ['id' => 'kurspilot-ortswahl']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url('/local/kurspilot/ortswahl.php'))->out(false), 'id' => 'kurspilot-ortswahl-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'finish', 'value' => '1']);

    local_kurspilot_render_ortswahl_progress_band();
    local_kurspilot_render_ortswahl_tabs($data);
    local_kurspilot_render_ortswahl_hidden_target_fields();
    local_kurspilot_render_ortswahl_browse_modal($data);
    local_kurspilot_render_ortswahl_confirm_modal($data);

    echo html_writer::tag('button', $data['strings']['finishbutton'], [
        'type' => 'submit', 'class' => 'btn btn-success mt-3', 'id' => 'kurspilot-ortswahl-finish', 'disabled' => 'disabled',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // Footer, not head: this runs after $OUTPUT->header(), a head script would be silently dropped.
    $page->requires->js(new moodle_url('/local/kurspilot/javascript/ortswahl.js'));
}

/**
 * Die Daten fuer {@see ortswahl.js} - Instanzen, aktuelle Ziele und die
 * uebersetzten Strings, die das JS ohne weitere `get_string()`-Aufrufe
 * braucht.
 *
 * @return array{instances: array, targets: array, browseurl: string,
 *         manageinstancesurl: string, sesskey: string, timeoutms: int, strings: array<string, string>}
 */
function local_kurspilot_ortswahl_editor_data(): array {
    return [
        'instances' => ortswahl_lib::own_instances(),
        'targets' => [
            'kontextbereich' => ortswahl_lib::current('kontextbereich'),
            'materialbestand' => ortswahl_lib::current('materialbestand'),
        ],
        'browseurl' => (new moodle_url('/local/kurspilot/ortswahl_browse.php'))->out(false),
        'manageinstancesurl' => (new moodle_url('/repository/manage_instances.php', [
            'contextid' => \local_kurspilot\storage_anchor::own_context()->id,
        ]))->out(false),
        'sesskey' => sesskey(),
        'timeoutms' => ortswahl_lib::BROWSE_TIMEOUT_MS,
        'strings' => local_kurspilot_ortswahl_editor_strings(),
    ];
}

/**
 * @return array<string, string>
 */
function local_kurspilot_ortswahl_editor_strings(): array {
    return [
        'tabkontextbereich' => get_string('ortswahltabkontextbereich', 'local_kurspilot'),
        'tabmaterialbestand' => get_string('ortswahltabmaterialbestand', 'local_kurspilot'),
        'kontexthint' => get_string('ortswahlkontexthint', 'local_kurspilot'),
        'keepmoodle' => get_string('ortswahlkeepmoodle', 'local_kurspilot'),
        'chooseinstance' => get_string('ortswahlchooseinstance', 'local_kurspilot'),
        'selectfolder' => get_string('ortswahlselectfolder', 'local_kurspilot'),
        'breadcrumbroot' => get_string('ortswahlbreadcrumbroot', 'local_kurspilot'),
        'loading' => get_string('ortswahlloading', 'local_kurspilot'),
        'createfolder' => get_string('ortswahlcreatefolder', 'local_kurspilot'),
        'newfoldername' => get_string('ortswahlnewfoldername', 'local_kurspilot'),
        'progresschosen' => get_string('ortswahlprogresschosen', 'local_kurspilot', '%s'),
        'progressopen' => get_string('ortswahlprogressopen', 'local_kurspilot', '%s'),
        'finishbutton' => get_string('ortswahlfinishbutton', 'local_kurspilot'),
        'selectionincomplete' => get_string('ortswahlselectionincomplete', 'local_kurspilot'),
        'timeouttitle' => get_string('ortswahltimeouttitle', 'local_kurspilot'),
        'timeouttext' => get_string('ortswahltimeouttext', 'local_kurspilot'),
        'browseerrorheading' => get_string('ortswahlbrowseerrorheading', 'local_kurspilot'),
        'retry' => get_string('ortswahlretry', 'local_kurspilot'),
        'checkcredentials' => get_string('ortswahlcheckcredentials', 'local_kurspilot'),
        'later' => get_string('ortswahllater', 'local_kurspilot'),
        'confirmheading' => get_string('ortswahlconfirmheading', 'local_kurspilot'),
        'confirmcount' => get_string('ortswahlconfirmcount', 'local_kurspilot', '%s'),
        'confirmtext' => get_string('ortswahlconfirmtext', 'local_kurspilot'),
        'confirmbutton' => get_string('ortswahlconfirmbutton', 'local_kurspilot'),
        'confirmcancel' => get_string('ortswahlconfirmcancel', 'local_kurspilot'),
        'overlaplocked' => get_string('ortswahloverlaplocked', 'local_kurspilot'),
    ];
}

/**
 * @param array $data Ergebnis von {@see local_kurspilot_ortswahl_editor_data()}.
 */
function local_kurspilot_render_ortswahl_data_script(array $data): void {
    echo html_writer::tag(
        'script',
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG),
        ['type' => 'application/json', 'id' => 'kurspilot-ortswahl-data']
    );
}

/**
 * Die <li>-Zeilen fuer Kontextbereich und Materialbestand (Issue #494,
 * #500) - geteilt zwischen der Ortswahlseite und "Meine Verbindungen"
 * (Issue #511). Der Anzeigename ist Speicherinhalt (ein Ordner- oder
 * Instanzname, auch aus einer fremd geteilten Freigabe) und wird deshalb
 * vor der Einbettung ins Markup escaped - ohne dieses `s()` fuehrte ein
 * praeparierter Name auf beiden Seiten Skript aus (Sicherheitsbefund HIGH).
 *
 * @return string HTML der beiden <li>-Zeilen, ohne umschliessendes <ul>.
 */
function local_kurspilot_current_locations_list_items(): string {
    $kontextbereich = ortswahl_lib::current('kontextbereich');
    $materialbestand = ortswahl_lib::current('materialbestand');
    return html_writer::tag('li', s(get_string('ortswahlcurrentkontextbereich', 'local_kurspilot', $kontextbereich['display']))
            . ' — ' . ortswahl_lib::zugelassen_label($kontextbereich))
        . html_writer::tag('li', s(get_string('ortswahlcurrentmaterialbestand', 'local_kurspilot', $materialbestand['display']))
            . ' — ' . ortswahl_lib::zugelassen_label($materialbestand));
}

/**
 * Fortschrittsband und die Sperr-Begruendung des "Einrichten abschliessen"-
 * Knopfs (Issue #497, Spec §5: Materialbestand im Kontextbereich/im selben
 * Ordner) - beide vom JS befuellt, leer im Markup.
 */
function local_kurspilot_render_ortswahl_progress_band(): void {
    echo html_writer::div('', 'mb-3 d-flex gap-2 flex-wrap', ['id' => 'kurspilot-ortswahl-progress']);
    echo html_writer::div('', 'mb-2 small text-danger', ['id' => 'kurspilot-ortswahl-overlaplock', 'hidden' => 'hidden']);
}

/**
 * Die Reiter Kontextbereich/Materialbestand mit ihren "Bei Moodle
 * bleiben"/"Instanz waehlen"-Knoepfen.
 *
 * @param array $data Ergebnis von {@see local_kurspilot_ortswahl_editor_data()}.
 */
function local_kurspilot_render_ortswahl_tabs(array $data): void {
    echo html_writer::start_tag('ul', ['class' => 'nav nav-tabs', 'role' => 'tablist']);
    foreach (ortswahl_lib::TARGETS as $index => $target) {
        $labelkey = $target === 'kontextbereich' ? 'tabkontextbereich' : 'tabmaterialbestand';
        echo html_writer::tag('li', html_writer::link(
            '#kurspilot-ortswahl-pane-' . $target,
            $data['strings'][$labelkey],
            [
                'class' => 'nav-link' . ($index === 0 ? ' active' : ''),
                'data-bs-toggle' => 'tab',
                'data-target' => $target,
                'role' => 'tab',
            ]
        ), ['class' => 'nav-item', 'role' => 'presentation']);
    }
    echo html_writer::end_tag('ul');

    echo html_writer::start_div('tab-content border border-top-0 p-3');
    foreach (ortswahl_lib::TARGETS as $index => $target) {
        echo html_writer::start_div('tab-pane fade' . ($index === 0 ? ' show active' : ''), ['id' => 'kurspilot-ortswahl-pane-' . $target]);
        if ($target === 'kontextbereich') {
            echo html_writer::div($data['strings']['kontexthint'], 'text-muted small mb-2');
        }
        echo html_writer::tag('button', $data['strings']['keepmoodle'], [
            'type' => 'button', 'class' => 'btn btn-outline-secondary me-2', 'data-action' => 'keep-moodle', 'data-target' => $target,
        ]);
        echo html_writer::tag('button', $data['strings']['chooseinstance'], [
            'type' => 'button', 'class' => 'btn btn-primary', 'data-action' => 'open-picker', 'data-target' => $target,
        ]);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

/**
 * Die versteckten Formularfelder, die das JS je Ziel mit der getroffenen
 * Wahl befuellt (Typ, Instanz-ID, Pfad, Uebergabe-Bestaetigung) - eines der
 * beiden Ziele bleibt bei "moodle", wenn nie ein anderer Ort gewaehlt wird.
 * `_confirmed` traegt die Uebergabe-Bestaetigung eines gefuellten Ordners
 * (Issue #518, Spec §5) - serverseitig ausgewertet nur fuer "kontextbereich"
 * ({@see \local_kurspilot\ortswahl_lib::apply()}).
 */
function local_kurspilot_render_ortswahl_hidden_target_fields(): void {
    foreach (ortswahl_lib::TARGETS as $target) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $target . '_type', 'id' => 'kurspilot-ortswahl-' . $target . '_type']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $target . '_instanceid', 'id' => 'kurspilot-ortswahl-' . $target . '_instanceid']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $target . '_path', 'id' => 'kurspilot-ortswahl-' . $target . '_path']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $target . '_confirmed', 'id' => 'kurspilot-ortswahl-' . $target . '_confirmed']);
    }
}

/**
 * Das Dateifenster (Bootstrap-Modal, vom JS befuellt) - leeres Geruest, nie
 * serverseitig mit Auflistungsinhalt gerendert.
 *
 * @param array $data Ergebnis von {@see local_kurspilot_ortswahl_editor_data()}.
 */
function local_kurspilot_render_ortswahl_browse_modal(array $data): void {
    echo html_writer::start_div('modal', ['id' => 'kurspilot-ortswahl-modal', 'tabindex' => '-1']);
    echo html_writer::start_div('modal-dialog modal-lg');
    echo html_writer::start_div('modal-content');
    echo html_writer::start_div('modal-header');
    echo html_writer::tag('h5', '', ['class' => 'modal-title', 'id' => 'kurspilot-ortswahl-modal-title']);
    echo html_writer::tag('button', '', ['type' => 'button', 'class' => 'btn-close', 'data-kurspilot-dismiss' => 'modal']);
    echo html_writer::end_div();
    echo html_writer::start_div('modal-body');
    echo html_writer::start_div('row');
    echo html_writer::div('', 'col-4', ['id' => 'kurspilot-ortswahl-instances']);
    echo html_writer::start_div('col-8');
    echo html_writer::div('', 'mb-2 small', ['id' => 'kurspilot-ortswahl-breadcrumb']);
    echo html_writer::div('', '', ['id' => 'kurspilot-ortswahl-folders']);
    echo html_writer::start_div('input-group mt-2');
    echo html_writer::empty_tag('input', [
        'type' => 'text', 'class' => 'form-control form-control-sm', 'id' => 'kurspilot-ortswahl-newfolder',
        'placeholder' => $data['strings']['newfoldername'],
    ]);
    echo html_writer::tag('button', $data['strings']['createfolder'], [
        'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary', 'id' => 'kurspilot-ortswahl-createfolder',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::start_div('modal-footer flex-column align-items-stretch');
    echo html_writer::div('', 'small text-danger mb-2', ['id' => 'kurspilot-ortswahl-modal-reason', 'hidden' => 'hidden']);
    echo html_writer::tag('button', $data['strings']['selectfolder'], [
        'type' => 'button', 'class' => 'btn btn-primary align-self-end', 'id' => 'kurspilot-ortswahl-confirmfolder',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

/**
 * Uebergabe-Bestaetigung eines gefuellten Ordners (Issue #497, Spec §5) -
 * eigenes, kleines Modal, nur fuer den Reiter Kontextbereich.
 *
 * @param array $data Ergebnis von {@see local_kurspilot_ortswahl_editor_data()}.
 */
function local_kurspilot_render_ortswahl_confirm_modal(array $data): void {
    echo html_writer::start_div('modal', ['id' => 'kurspilot-ortswahl-confirm-modal', 'tabindex' => '-1']);
    echo html_writer::start_div('modal-dialog');
    echo html_writer::start_div('modal-content');
    echo html_writer::start_div('modal-header');
    echo html_writer::tag('h5', $data['strings']['confirmheading'], ['class' => 'modal-title']);
    echo html_writer::tag('button', '', ['type' => 'button', 'class' => 'btn-close', 'data-kurspilot-dismiss' => 'modal']);
    echo html_writer::end_div();
    echo html_writer::start_div('modal-body');
    echo html_writer::tag('p', '', ['id' => 'kurspilot-ortswahl-confirm-count']);
    echo html_writer::tag('p', s($data['strings']['confirmtext']));
    echo html_writer::end_div();
    echo html_writer::start_div('modal-footer');
    echo html_writer::tag('button', $data['strings']['confirmcancel'], [
        'type' => 'button', 'class' => 'btn btn-outline-secondary', 'data-kurspilot-dismiss' => 'modal',
    ]);
    echo html_writer::tag('button', $data['strings']['confirmbutton'], [
        'type' => 'button', 'class' => 'btn btn-success', 'id' => 'kurspilot-ortswahl-confirmfolder-ack',
    ]);
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
    echo html_writer::end_div();
}
