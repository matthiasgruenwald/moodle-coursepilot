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
 * Autorisierungsendpunkt (#336): Moodle-Login (require_login() - LDAP/SSO
 * der Instanz greift), Zustimmungsdialog mit den Textbausteinen aus #298,
 * PKCE/S256 Pflicht.
 *
 * Duenne Schale (#334-Muster): die eigentliche Entscheidungslogik
 * (response_type/PKCE/Client/Umleitungsziel pruefen, Code ausstellen, das
 * Ablehnungs-Umleitungsziel bauen) lebt als reine, per PHPUnit pruefbare
 * Methoden in {@see \local_coursepilot\oauth_lib}. Diese Datei tut nur noch
 * Moodle-Ein-/Ausgabe: Login erzwingen, Formular rendern, redirect().
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use local_coursepilot\oauth_lib;
use local_coursepilot\output\authorize_page;

require_login(null, false);

$context = context_system::instance();
require_capability('local/coursepilot:useremote', $context);

$PAGE->set_url('/local/coursepilot/oauth/authorize.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
// Generischer Titel, solange der Client noch nicht validiert ist (Fehlerfall
// unten kennt noch keinen Client-Namen) - nach erfolgreicher Validierung
// wird er unten durch den client-spezifischen Titel ersetzt (#336-Review:
// Zustimmungsdialog nennt laut #298 den Client-Namen in der Ueberschrift).
$PAGE->set_title(get_string('authorizetitle', 'local_coursepilot'));

$params = [
    'response_type' => optional_param('response_type', '', PARAM_ALPHA),
    'client_id' => optional_param('client_id', '', PARAM_RAW_TRIMMED),
    'redirect_uri' => optional_param('redirect_uri', '', PARAM_URL),
    'code_challenge' => optional_param('code_challenge', '', PARAM_RAW_TRIMMED),
    'code_challenge_method' => optional_param('code_challenge_method', '', PARAM_ALPHANUMEXT),
];
$state = optional_param('state', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHA);

$validation = oauth_lib::validate_authorize_request($params);
if (isset($validation['error'])) {
    // Fehlerhafte Anfragen (unbekannter Client, kein PKCE, nicht
    // registriertes Umleitungsziel) haben keinen verifizierten redirect_uri,
    // auf den ein Fehler-Redirect sicher waere - Moodle-Fehlerseite statt
    // Redirect (RFC 6749, 4.1.2.1 gilt erst ab verifiziertem Ziel).
    throw new moodle_exception('authorizeerror', 'local_coursepilot', '', $validation['error_description']);
}
$client = $validation['client'];
$clientname = $client->clientname ?: $client->clientid;
$title = get_string('authorizetitleclient', 'local_coursepilot', $clientname);
$PAGE->set_title($title);

if ($action === 'deny') {
    require_sesskey();
    redirect(oauth_lib::denial_redirect_url($params['redirect_uri'], $state !== '' ? $state : null));
}

if ($action === 'allow') {
    require_sesskey();

    global $USER;
    $code = oauth_lib::issue_code(
        $params['client_id'],
        (int) $USER->id,
        $params['redirect_uri'],
        $params['code_challenge']
    );
    redirect(oauth_lib::build_redirect_url($params['redirect_uri'], [
        'code' => $code,
        'state' => $state !== '' ? $state : null,
    ]));
}

// Ortswahl-Link mit Ruecksprung (Issue #563, loest die mit #558 dokumentierte
// Sackgasse auf): die OAuth-Anfrageparameter reisen als Querystring zur
// Ortswahlseite mit; die Ortswahlseite validiert sie erneut selbst und
// fuehrt nach dem Abschliessen genau hierher zurueck, statt zu Claude
// weiterzuleiten.
$ortswahlurl = new moodle_url('/local/coursepilot/ortswahl.php', array_merge($params, [
    'state' => $state,
    'oauthflow' => 1,
]));
$formurl = new moodle_url('/local/coursepilot/oauth/authorize.php');

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render_from_template(
    'local_coursepilot/authorize',
    authorize_page::page_data($clientname, $params, $state, $formurl, $ortswahlurl)
);
echo $OUTPUT->footer();
