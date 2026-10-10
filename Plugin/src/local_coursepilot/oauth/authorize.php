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
 * Authorization endpoint (#336): Moodle login (instance LDAP/SSO via
 * require_login()), consent text from #298 and mandatory PKCE/S256.
 *
 * Thin I/O shell (#334): pure PHPUnit-testable methods in
 * {@see \local_coursepilot\oauth_lib} validate response_type, PKCE, client
 * and redirect, issue the code and construct denial redirects. This file
 * only requires login, renders the form and redirects.
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
\local_coursepilot\remote_access::require_granted();

$PAGE->set_url('/local/coursepilot/oauth/authorize.php');
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
// Use a generic title before client validation: the error path has no
// client name yet. Replace it below with the client-specific title after
// validation (#336 review: #298 requires the client name in the heading).
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
    // Invalid requests (unknown client, missing PKCE, unregistered redirect)
    // have no verified redirect_uri. Show a Moodle error instead of an unsafe
    // redirect; RFC 6749 §4.1.2.1 applies only after verifying the target.
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

// Location-selection link with return (#563, resolves the #558 dead end):
// OAuth parameters travel in the query string. Location selection validates
// them again and returns here on completion, instead of redirecting to Claude.
$locationselectionurl = new moodle_url('/local/coursepilot/location_selection.php', array_merge($params, [
    'state' => $state,
    'oauthflow' => 1,
]));
$formurl = new moodle_url('/local/coursepilot/oauth/authorize.php');

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
echo $OUTPUT->render_from_template(
    'local_coursepilot/authorize',
    authorize_page::page_data($clientname, $params, $state, $formurl, $locationselectionurl)
);
echo $OUTPUT->footer();
