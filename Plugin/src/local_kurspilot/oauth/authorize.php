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
 * Autorisierungsendpunkt (#336): Moodle-Login (require_login() - LDAP/SSO
 * der Instanz greift), Zustimmungsdialog mit den Textbausteinen aus #298,
 * PKCE/S256 Pflicht.
 *
 * Duenne Schale (#334-Muster): die eigentliche Entscheidungslogik
 * (response_type/PKCE/Client/Umleitungsziel pruefen, Code ausstellen, das
 * Ablehnungs-Umleitungsziel bauen) lebt als reine, per PHPUnit pruefbare
 * Methoden in {@see \local_kurspilot\oauth_lib}. Diese Datei tut nur noch
 * Moodle-Ein-/Ausgabe: Login erzwingen, Formular rendern, redirect().
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use local_kurspilot\oauth_lib;
use local_kurspilot\ortswahl_lib;
use local_kurspilot\webdav\webdav_setup_steps;

require_login(null, false);

$context = context_system::instance();
require_capability('local/kurspilot:useremote', $context);

$PAGE->set_url('/local/kurspilot/oauth/authorize.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
// Generischer Titel, solange der Client noch nicht validiert ist (Fehlerfall
// unten kennt noch keinen Client-Namen) - nach erfolgreicher Validierung
// wird er unten durch den client-spezifischen Titel ersetzt (#336-Review:
// Zustimmungsdialog nennt laut #298 den Client-Namen in der Ueberschrift).
$PAGE->set_title(get_string('authorizetitle', 'local_kurspilot'));

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
    print_error('authorizeerror', 'local_kurspilot', '', $validation['error_description']);
}
$client = $validation['client'];
$clientname = $client->clientname ?: $client->clientid;
$title = get_string('authorizetitleclient', 'local_kurspilot', $clientname);
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

echo $OUTPUT->header();
echo $OUTPUT->heading($title);

$allowpersonaldata = (bool) get_config('local_kurspilot', 'allowpersonaldata');
echo html_writer::div(
    get_string('consentintro', 'local_kurspilot', $clientname) . '<br><br>'
    . get_string('consentgranted', 'local_kurspilot') . '<br>'
    . get_string('consentdenied', 'local_kurspilot') . '<br><br>'
    . get_string('consenttransfer', 'local_kurspilot') . '<br><br>'
    . ($allowpersonaldata
        ? get_string('consentpersonaldataon', 'local_kurspilot')
        : get_string('consentpersonaldataoff', 'local_kurspilot'))
    . '<br><br>'
    . get_string('consentabbreviate', 'local_kurspilot') . '<br><br>'
    . get_string('consentrevoke', 'local_kurspilot'),
    'kurspilot-consent'
);

// Ortswahl (#446, #494): der Dialog zeigt den aufgeloesten Ort nur noch an
// und verlinkt zur Ortswahlseite - er schreibt selbst keinen Kontextpointer
// mehr (das war Issue #446, abgeloest durch die eigene Ortswahlseite #494).
echo $OUTPUT->heading(get_string('consentlocationheading', 'local_kurspilot'), 3);
echo html_writer::div(get_string('consentlocationintro', 'local_kurspilot'), 'kurspilot-consent-location-intro');
$kontextbereich = ortswahl_lib::current('kontextbereich');
$materialbestand = ortswahl_lib::current('materialbestand');
echo html_writer::start_tag('ul');
echo html_writer::tag('li', get_string('consentlocationkontextbereichcurrent', 'local_kurspilot', $kontextbereich['display']));
echo html_writer::tag('li', get_string('consentlocationmaterialbestandcurrent', 'local_kurspilot', $materialbestand['display']));
echo html_writer::end_tag('ul');
echo html_writer::link(
    new moodle_url(webdav_setup_steps::ORTSWAHL_PAGE),
    get_string('consentlocationchangelink', 'local_kurspilot'),
    ['class' => 'btn btn-link p-0']
);
echo html_writer::empty_tag('br');
echo html_writer::empty_tag('br');

$formurl = new moodle_url('/local/kurspilot/oauth/authorize.php');
foreach (['allow' => 'consentconfirm', 'deny' => 'consentdeny'] as $actionvalue => $labelstring) {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false), 'style' => 'display:inline']);
    foreach ($params as $name => $value) {
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'state', 'value' => $state]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $actionvalue]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string($labelstring, 'local_kurspilot')]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
