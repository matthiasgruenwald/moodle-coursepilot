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

namespace local_coursepilot\output;

use local_coursepilot\oauth_lib;
use local_coursepilot\location_selection;
use local_coursepilot\pointer_location;
use moodle_url;

/**
 * Processes location-selection forms and preserves verified OAuth return navigation.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class location_selection_page {
    /**
     * Selects the page or OAuth return after applying a submitted location change.
     *
     * @param bool $oauthflow Whether a consent request was passed through.
     * @param bool $finish Whether the finish action was submitted.
     * @return page_response The editor, failure notification or verified OAuth redirect.
     */
    public static function response(bool $oauthflow, bool $finish): page_response {
        global $USER, $PAGE;
        \local_coursepilot\remote_access::require_granted();
        $oauthreturn = self::oauth_return($oauthflow);
        $result = self::finish($finish, $oauthreturn);
        if ($result instanceof page_response) {
            return $result;
        }
        if (location_selection::setup_state((int) $USER->id)['state'] === location_selection::STATE_READY) {
            $PAGE->requires->js_call_amd('local_coursepilot/location_selection', 'init', [
                \local_coursepilot\output\location_selection::amd_configuration($USER),
            ]);
        }
        return new page_response(
            'local_coursepilot/location_selection_page',
            \local_coursepilot\output\location_selection::page_data($USER, $result, $oauthreturn)
        );
    }

    /**
     * Reads OAuth parameters passed from consent to location selection.
     * Otherwise changing location during connection
     * setup would not return to consent. Missing/invalid parameters keep
     * the standalone page behavior, without banner or return navigation.
     *
     * @param bool $oauthflow Whether OAuth parameters should be validated.
     * @return array{client: \stdClass, params: array<string, string>}|null
     */
    private static function oauth_return(bool $oauthflow): ?array {
        if (!$oauthflow) {
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
     * Handles submitted location selection only when finish
     * is present; otherwise displays the page without processing the form.
     *
     * @param bool $finish Whether the finish action was submitted.
     * @param mixed[]|null $oauthreturn Verified client and consent parameters, or standalone mode.
     * @return array{type: string, text: string}|page_response|null
     */
    private static function finish(bool $finish, ?array $oauthreturn): array|page_response|null {
        if (!$finish) {
            return null;
        }
        require_sesskey();
        try {
            $providedtemplates = [];
            $changed = location_selection::apply(self::read_selection(), $providedtemplates);
            if ($providedtemplates) {
                \core\notification::success(get_string(
                    'activitytypetemplatesprovided',
                    'local_coursepilot',
                    implode(', ', $providedtemplates)
                ));
            }
            if ($oauthreturn !== null) {
                // Return to consent whether or not locations changed: the teacher
                // is in the middle of establishing a connection.
                $url = new moodle_url('/local/coursepilot/oauth/authorize.php', $oauthreturn['params']);
                return new page_response(redirecturl: $url);
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
        } catch (\moodle_exception $e) {
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
     * @throws \moodle_exception locationselectionselectioninvalid for an unknown type.
     */
    private static function read_selection(): array {
        $selection = [];
        foreach (location_selection::TARGETS as $target) {
            $type = optional_param($target . '_type', pointer_location::MOODLE, PARAM_ALPHA);
            $selection[$target] = [
                'type' => $type,
                'instanceid' => optional_param($target . '_instanceid', 0, PARAM_INT),
                'path' => optional_param($target . '_path', '', PARAM_RAW_TRIMMED),
                'confirmed' => (bool) optional_param($target . '_confirmed', 0, PARAM_BOOL),
            ];
            if ($type !== pointer_location::MOODLE && $type !== pointer_location::EXTERNAL) {
                throw new \moodle_exception('locationselectionselectioninvalid', 'local_coursepilot');
            }
        }
        return $selection;
    }
}
