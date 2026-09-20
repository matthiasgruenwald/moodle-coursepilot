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
 * Die Ortswahlseite (Issue #494, Spec #486 §5/§10): eigene Profilseite neben
 * "Meine Verbindungen" - wo Kontextbereich und Materialbestand liegen. Nur
 * fuer die angemeldete Lehrkraft und ihre eigenen WebDAV-Nutzerinstanzen.
 *
 * Duenne Schale (#334-Muster): die gesamte Logik lebt testbar in
 * {@see \local_coursepilot\location_selection}, diese Datei tut nur noch Ein-/Ausgabe
 * (Formular entgegennehmen, Markup rendern) - siehe Issue #494
 * Akzeptanzkriterium "ueber ihre Klasse getestet, nicht ueber die Seite".
 *
 * Issue #507 (Spec #486, Review von #486): Seitenaufbau und Uebernahme der
 * Formulareingabe sind in Funktionen unter 50 Zeilen zerlegt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\previous_location;
use local_coursepilot\oauth_lib;
use local_coursepilot\location_selection;
use local_coursepilot\pointer_location;
use local_coursepilot\webdav\webdav_setup_steps;

require_login(null, false);
$context = context_system::instance();
require_capability('local/coursepilot:useremote', $context);

global $USER, $OUTPUT, $PAGE;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(webdav_setup_steps::ORTSWAHL_PAGE));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('ortswahltitle', 'local_coursepilot'));
$PAGE->set_heading(get_string('ortswahlheading', 'local_coursepilot'));

$oauthreturn = local_coursepilot_read_oauth_passthrough();
$finishresult = local_coursepilot_handle_ortswahl_finish($oauthreturn);

echo $OUTPUT->header();
local_coursepilot_render_ortswahl_page($finishresult, $USER, $oauthreturn);
echo $OUTPUT->footer();

/**
 * Liest die OAuth-Anfrageparameter, mit denen die Ortswahlseite von der
 * Zustimmungsseite aus aufgerufen werden kann (Issue #563, loest die mit
 * Issue #558 dokumentierte Sackgasse auf: ohne diese Parameter fuehrte ein
 * Ortswechsel waehrend des Verbindungsaufbaus nicht mehr zurueck). Fehlen
 * die Parameter oder sind sie ungueltig, verhaelt sich die Seite wie zuvor
 * (kein Banner, kein Ruecksprung) - eigenstaendiger Aufruf bleibt moeglich.
 *
 * @return array{client: \stdClass, params: array<string, string>}|null
 */
function local_coursepilot_read_oauth_passthrough(): ?array {
    if (!optional_param('oauthflow', 0, PARAM_BOOL)) {
        return null;
    }
    $params = [
        'response_type' => optional_param('response_type', '', PARAM_ALPHA),
        'client_id' => optional_param('client_id', '', PARAM_RAW_TRIMMED),
        'redirect_uri' => optional_param('redirect_uri', '', PARAM_URL),
        'code_challenge' => optional_param('code_challenge', '', PARAM_RAW_TRIMMED),
        'code_challenge_method' => optional_param('code_challenge_method', '', PARAM_ALPHANUMEXT),
        'state' => optional_param('state', '', PARAM_RAW_TRIMMED),
    ];
    $validation = oauth_lib::validate_authorize_request($params);
    if (isset($validation['error'])) {
        return null;
    }
    return ['client' => $validation['client'], 'params' => $params];
}

/**
 * Nimmt eine abgeschickte Ortswahl entgegen (Issue #494, Spec §5) - nur wenn
 * ueberhaupt "finish" mitgeschickt wurde, sonst wird die Seite ohne
 * Formularverarbeitung nur angezeigt.
 *
 * @param array{client: \stdClass, params: array<string, string>}|null $oauthreturn
 * @return array{type: string, text: string}|null
 */
function local_coursepilot_handle_ortswahl_finish(?array $oauthreturn): ?array {
    if (!optional_param('finish', 0, PARAM_BOOL)) {
        return null;
    }
    require_sesskey();
    try {
        $changed = location_selection::apply(local_coursepilot_read_ortswahl_selection());
        if ($oauthreturn !== null) {
            // Zurueck zur Zustimmungsseite (Issue #563) statt hier stehen zu
            // bleiben - egal ob sich etwas geaendert hat, die Lehrkraft war
            // mitten im Verbindungsaufbau.
            redirect(new moodle_url('/local/coursepilot/oauth/authorize.php', $oauthreturn['params']));
        }
        return empty($changed)
            ? ['type' => \core\output\notification::NOTIFY_INFO, 'text' => get_string('ortswahlfinishnochange', 'local_coursepilot')]
            : ['type' => \core\output\notification::NOTIFY_SUCCESS, 'text' => get_string('ortswahlfinishsuccess', 'local_coursepilot', implode(', ', $changed))];
    } catch (moodle_exception $e) {
        // Bei einem Fehler auf der Ortswahlseite bleiben (auch im OAuth-Fluss)
        // - ein Ruecksprung wuerde die Fehlermeldung verschlucken.
        return ['type' => \core\output\notification::NOTIFY_ERROR, 'text' => $e->getMessage()];
    }
}

/**
 * Liest die Formulareingabe je Ziel - roh, {@see \local_coursepilot\location_selection::apply()}
 * validiert Instanz, Pfad und Sperren.
 *
 * @return array<string, array{type: string, instanceid: int, path: string, confirmed: bool}>
 * @throws moodle_exception ortswahlselectioninvalid bei einem unbekannten Typ.
 */
function local_coursepilot_read_ortswahl_selection(): array {
    $selection = [];
    foreach (location_selection::TARGETS as $target) {
        $type = optional_param($target . '_type', pointer_location::MOODLE, PARAM_ALPHA);
        $selection[$target] = [
            'type' => $type,
            'instanceid' => optional_param($target . '_instanceid', 0, PARAM_INT),
            'path' => optional_param($target . '_path', '', PARAM_RAW_TRIMMED),
            'confirmed' => optional_param($target . '_confirmed', 0, PARAM_BOOL),
        ];
        if ($type !== pointer_location::MOODLE && $type !== pointer_location::EXTERN) {
            throw new moodle_exception('ortswahlselectioninvalid', 'local_coursepilot');
        }
    }
    return $selection;
}

/**
 * Rendert den gesamten Seiteninhalt zwischen Header und Footer.
 *
 * @param array{type: string, text: string}|null $finishresult Ergebnis von {@see local_coursepilot_handle_ortswahl_finish()}.
 * @param \stdClass $user
 * @param array{client: \stdClass, params: array<string, string>}|null $oauthreturn Ergebnis von {@see local_coursepilot_read_oauth_passthrough()}.
 */
function local_coursepilot_render_ortswahl_page(?array $finishresult, \stdClass $user, ?array $oauthreturn): void {
    global $OUTPUT;

    local_coursepilot_render_oauth_banner($oauthreturn);
    if ($finishresult !== null) {
        echo $OUTPUT->notification($finishresult['text'], $finishresult['type']);
    }
    echo html_writer::tag('p', get_string('ortswahlintro', 'local_coursepilot'));
    local_coursepilot_render_altbestand_warning();
    local_coursepilot_render_ortswahl_setup_state($user, $oauthreturn);
    local_coursepilot_render_ortswahl_current_locations();
    local_coursepilot_render_ortswahl_history();
}

/**
 * Hinweisband und Ruecksprung-Link, wenn die Seite mitten im OAuth-
 * Verbindungsaufbau aufgerufen wurde (Issue #563).
 *
 * @param array{client: \stdClass, params: array<string, string>}|null $oauthreturn
 */
function local_coursepilot_render_oauth_banner(?array $oauthreturn): void {
    if ($oauthreturn === null) {
        return;
    }
    global $OUTPUT;

    $clientname = $oauthreturn['client']->clientname ?: $oauthreturn['client']->clientid;
    echo $OUTPUT->notification(
        get_string('ortswahloauthflowinfo', 'local_coursepilot', $clientname),
        \core\output\notification::NOTIFY_INFO
    );
    $backurl = new moodle_url('/local/coursepilot/oauth/authorize.php', $oauthreturn['params']);
    echo html_writer::div(html_writer::link($backurl, get_string('ortswahloauthflowback', 'local_coursepilot')), 'mb-3');
}

/**
 * Warnt, wenn noch Altbestand offen ist (Issue #498, Spec §486 §5) -
 * angezeigt unabhaengig davon, ob gerade ein neuer Wechsel bevorsteht; ein
 * Abschliessen trotz dieser Warnung verdraengt den offenen Altbestand
 * ({@see \local_coursepilot\location_selection::apply()}), seine Dateien bleiben
 * dabei unberuehrt.
 */
function local_coursepilot_render_altbestand_warning(): void {
    global $OUTPUT;
    if (previous_location::open()) {
        echo $OUTPUT->notification(get_string('ortswahlaltbestandopen', 'local_coursepilot'), \core\output\notification::NOTIFY_WARNING);
    }
}

/**
 * Rendert je Bereitschaftszustand ({@see location_selection::setup_state()}) den
 * passenden Leerzustand oder das Dateifenster.
 *
 * @param \stdClass $user
 * @param array{client: \stdClass, params: array<string, string>}|null $oauthreturn
 */
function local_coursepilot_render_ortswahl_setup_state(\stdClass $user, ?array $oauthreturn): void {
    global $PAGE;

    $state = location_selection::setup_state((int) $user->id);
    if ($state['state'] === location_selection::STATE_NOT_ENABLED) {
        local_coursepilot_render_ortswahl_not_enabled($user);
    } else if ($state['state'] === location_selection::STATE_NO_INSTANCE) {
        local_coursepilot_render_ortswahl_no_instance();
    } else {
        require_once(__DIR__ . '/ortswahl_render.php');
        local_coursepilot_render_ortswahl_editor($PAGE, $user, $oauthreturn['params'] ?? []);
    }
}

/**
 * Leerzustand "nicht freigeschaltet" (Issue #494): kopierbarer Text an die
 * Administration mit nur den fehlenden Schritten.
 *
 * @param \stdClass $user
 */
function local_coursepilot_render_ortswahl_not_enabled(\stdClass $user): void {
    global $OUTPUT;

    echo $OUTPUT->notification(get_string('ortswahlnotenabledtext', 'local_coursepilot'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->heading(get_string('ortswahlmissingstepsheading', 'local_coursepilot'), 4);
    echo html_writer::tag('p', get_string('ortswahlmissingstepsintro', 'local_coursepilot'));
    echo html_writer::tag('textarea', s(location_selection::missing_steps_text((int) $user->id)), [
        'class' => 'form-control', 'rows' => 4, 'readonly' => 'readonly', 'id' => 'coursepilot-ortswahl-missingsteps',
    ]);
    echo html_writer::link(
        new moodle_url('/admin/settings.php', ['section' => 'supportcontact']),
        get_string('ortswahlcoresupportlink', 'local_coursepilot'),
        ['class' => 'btn btn-link px-0 mt-2']
    );
}

/**
 * Leerzustand "keine Instanz" (Issue #494): Schritt-fuer-Schritt-Anleitung
 * plus optionalem Schul-Hinweis.
 */
function local_coursepilot_render_ortswahl_no_instance(): void {
    global $OUTPUT;

    echo $OUTPUT->notification(get_string('ortswahlnoinstanceheading', 'local_coursepilot'), \core\output\notification::NOTIFY_INFO);
    echo html_writer::tag('p', get_string('ortswahlnoinstanceintro', 'local_coursepilot'));
    echo html_writer::start_tag('ol');
    echo html_writer::tag('li', get_string('ortswahlnoinstancestep1', 'local_coursepilot'));
    echo html_writer::tag('li', get_string('ortswahlnoinstancestep2', 'local_coursepilot'));
    echo html_writer::tag('li', get_string('ortswahlnoinstancestep3', 'local_coursepilot'));
    echo html_writer::end_tag('ol');

    $hint = location_selection::school_hint();
    if ($hint !== '') {
        echo $OUTPUT->heading(get_string('ortswahlschoolhintheading', 'local_coursepilot'), 5);
        echo html_writer::tag('p', s($hint));
    }
}

/**
 * Die aktuellen Orte beider Ziele (Issue #494). Das Markup selbst - inklusive
 * Escaping der Anzeigenamen (Issue #511, Sicherheitsbefund HIGH) - teilt sich
 * {@see local_coursepilot_current_locations_list_items()} mit connections.php.
 */
function local_coursepilot_render_ortswahl_current_locations(): void {
    global $OUTPUT;

    require_once(__DIR__ . '/ortswahl_render.php');
    echo $OUTPUT->heading(get_string('ortswahlcurrentheading', 'local_coursepilot'), 4);
    echo html_writer::tag('ul', local_coursepilot_current_locations_list_items());
}

/**
 * Der Ortsverlauf (Issue #494, Grundstruktur).
 */
function local_coursepilot_render_ortswahl_history(): void {
    global $OUTPUT;

    echo $OUTPUT->heading(get_string('ortswahlhistoryheading', 'local_coursepilot'), 4);
    $history = location_selection::history();
    if (empty($history)) {
        echo html_writer::tag('p', get_string('ortswahlhistoryempty', 'local_coursepilot'));
        return;
    }

    $table = new html_table();
    $table->head = [
        get_string('ortswahlhistorydate', 'local_coursepilot'),
        get_string('ortswahlhistorytarget', 'local_coursepilot'),
        get_string('ortswahlhistoryfrom', 'local_coursepilot'),
        get_string('ortswahlhistoryto', 'local_coursepilot'),
    ];
    foreach (array_reverse($history) as $entry) {
        $target = $entry['ziel'] === 'kontextbereich'
            ? get_string('ortswahltabkontextbereich', 'local_coursepilot')
            : get_string('ortswahltabmaterialbestand', 'local_coursepilot');
        $table->data[] = [
            userdate((int) ($entry['datum'] ?? 0)),
            $target,
            s((string) ($entry['von'] ?? '')),
            s((string) ($entry['nach'] ?? '')),
        ];
    }
    echo html_writer::table($table);
}
