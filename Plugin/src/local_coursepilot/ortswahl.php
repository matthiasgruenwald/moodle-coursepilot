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

use local_coursepilot\oauth_lib;
use local_coursepilot\location_selection;
use local_coursepilot\output\location_selection as location_selection_output;
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
echo $OUTPUT->render_from_template(
    'local_coursepilot/ortswahl_page',
    location_selection_output::page_data($USER, $finishresult, $oauthreturn)
);
if (location_selection::setup_state((int) $USER->id)['state'] === location_selection::STATE_READY) {
    $PAGE->requires->js(new moodle_url('/local/coursepilot/javascript/ortswahl.js'));
}
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
