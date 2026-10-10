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
 * Location-selection page (#494, Spec #486 §5/§10), beside connection
 * self-service in the profile: chooses context and material locations
 * for the logged-in teacher and their owned WebDAV instances only.
 *
 * Thin shell (#334): {@see \local_coursepilot\location_selection} owns all
 * testable logic; this file only accepts form input and renders markup
 * (#494: test the class rather than the page).
 *
 * #507 (Spec #486 review): page setup and form handling use functions
 * below 50 lines.
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
\local_coursepilot\remote_access::require_granted();

global $USER, $OUTPUT, $PAGE;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(webdav_setup_steps::LOCATION_SELECTION_PAGE));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('locationselectiontitle', 'local_coursepilot'));
$PAGE->set_heading(get_string('locationselectionheading', 'local_coursepilot'));

$oauthreturn = local_coursepilot_read_oauth_passthrough();
$finishresult = local_coursepilot_handle_location_selection_finish($oauthreturn);

if (location_selection::setup_state((int) $USER->id)['state'] === location_selection::STATE_READY) {
    // AMD module instead of inline script (#551, Spec 0023): configure through
    // js_call_amd(), never through embedded page-source data.
    $PAGE->requires->js_call_amd('local_coursepilot/location_selection', 'init', [
        location_selection_output::amd_configuration($USER),
    ]);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'local_coursepilot/location_selection_page',
    location_selection_output::page_data($USER, $finishresult, $oauthreturn)
);
echo $OUTPUT->footer();

/**
 * Reads OAuth parameters passed from consent to location selection
 * (#563, resolves #558): otherwise changing location during connection
 * setup would not return to consent. Missing/invalid parameters keep
 * the standalone page behavior, without banner or return navigation.
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
 * Handles submitted location selection (#494, Spec §5) only when finish
 * is present; otherwise displays the page without processing the form.
 *
 * @param ?array $oauthreturn Type: array{client:\stdClass,params:array<string,string>}|null.
 * @return array{type: string, text: string}|null
 */
function local_coursepilot_handle_location_selection_finish(?array $oauthreturn): ?array {
    if (!optional_param('finish', 0, PARAM_BOOL)) {
        return null;
    }
    require_sesskey();
    try {
        $providedtemplates = [];
        $changed = location_selection::apply(local_coursepilot_read_location_selection(), $providedtemplates);
        if ($providedtemplates) {
            \core\notification::success(get_string(
                'activitytypetemplatesprovided',
                'local_coursepilot',
                implode(', ', $providedtemplates)
            ));
        }
        if ($oauthreturn !== null) {
            // Return to consent (#563) whether or not locations changed: the teacher
            // is in the middle of establishing a connection.
            redirect(new moodle_url('/local/coursepilot/oauth/authorize.php', $oauthreturn['params']));
        }
        return empty($changed)
            ? [
                'type' => \core\output\notification::NOTIFY_INFO,
                'text' => get_string('locationselectionfinishnochange', 'local_coursepilot'),
            ]
            : [
                'type' => \core\output\notification::NOTIFY_SUCCESS,
                'text' => get_string('locationselectionfinishsuccess', 'local_coursepilot', implode(', ', array_map(
                    static fn (string $target): string => get_string(
                        'locationselectiontab' . str_replace('_', '', $target),
                        'local_coursepilot'
                    ),
                    $changed
                ))),
            ];
    } catch (moodle_exception $e) {
        // Stay on location selection on failure, including OAuth flow, so
        // redirecting cannot hide the error message.
        return ['type' => \core\output\notification::NOTIFY_ERROR, 'text' => $e->getMessage()];
    }
}

/**
 * Reads raw form input per target. {@see \local_coursepilot\location_selection::apply()}
 * validates instance, path and selection guards.
 *
 * @return array<string, array{type: string, instanceid: int, path: string, confirmed: bool}>
 * @throws moodle_exception locationselectionselectioninvalid for an unknown type.
 */
function local_coursepilot_read_location_selection(): array {
    $selection = [];
    foreach (location_selection::TARGETS as $target) {
        $type = optional_param($target . '_type', pointer_location::MOODLE, PARAM_ALPHA);
        $selection[$target] = [
            'type' => $type,
            'instanceid' => optional_param($target . '_instanceid', 0, PARAM_INT),
            'path' => optional_param($target . '_path', '', PARAM_RAW_TRIMMED),
            'confirmed' => optional_param($target . '_confirmed', 0, PARAM_BOOL),
        ];
        if ($type !== pointer_location::MOODLE && $type !== pointer_location::EXTERNAL) {
            throw new moodle_exception('locationselectionselectioninvalid', 'local_coursepilot');
        }
    }
    return $selection;
}
