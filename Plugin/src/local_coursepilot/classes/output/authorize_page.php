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
 * Template data for oauth/authorize.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

use local_coursepilot\location_selection;

/**
 * Prepares display-neutral state for the OAuth consent template.
 */
final class authorize_page {
    /**
     * Validates consent input before rendering or issuing an authorization code.
     *
     * @param string[] $params OAuth authorization parameters.
     * @param string $state Client state carried unchanged in redirects.
     * @param string $action Consent action, allow, deny or empty for display.
     * @return page_response Consent view or redirect to the registered client.
     */
    public static function response(array $params, string $state, string $action): page_response {
        global $PAGE;
        \local_coursepilot\remote_access::require_granted();
        $validation = \local_coursepilot\oauth_lib::validate_authorize_request($params);
        if (isset($validation['error'])) {
            throw new \moodle_exception('authorizeerror', 'local_coursepilot', '', $validation['error_description']);
        }
        $client = $validation['client'];
        $clientname = $client->clientname ?: $client->clientid;
        $title = get_string('authorizetitleclient', 'local_coursepilot', $clientname);
        $PAGE->set_title($title);

        if ($action === 'deny') {
            require_sesskey();
            return new page_response(redirecturl: new \moodle_url(\local_coursepilot\oauth_lib::denial_redirect_url(
                $params['redirect_uri'],
                $state !== '' ? $state : null
            )));
        }

        if ($action === 'allow') {
            require_sesskey();

            global $USER;
            $code = \local_coursepilot\oauth_lib::issue_code(
                $params['client_id'],
                (int) $USER->id,
                $params['redirect_uri'],
                $params['code_challenge']
            );
            $url = \local_coursepilot\oauth_lib::build_redirect_url($params['redirect_uri'], [
                'code' => $code,
                'state' => $state !== '' ? $state : null,
            ]);
            return new page_response(redirecturl: new \moodle_url($url));
        }

        $locationselectionurl = new \moodle_url('/local/coursepilot/location_selection.php', array_merge($params, [
            'state' => $state,
            'oauthflow' => 1,
        ]));
        $formurl = new \moodle_url('/local/coursepilot/oauth/authorize.php');
        return new page_response(
            'local_coursepilot/authorize',
            self::page_data($clientname, $params, $state, $formurl, $locationselectionurl),
            heading: $title
        );
    }

    /**
     * Provides page data.
     *
     * @param string $clientname
     * @param string[] $params response_type/client_id/redirect_uri/code_challenge/code_challenge_method
     * @param string $state
     * @param \moodle_url $formurl
     * @param \moodle_url $locationselectionurl
     * @return array<string, mixed>
     */
    public static function page_data(
        string $clientname,
        array $params,
        string $state,
        \moodle_url $formurl,
        \moodle_url $locationselectionurl
    ): array {
        $allowpersonaldata = (bool) get_config('local_coursepilot', 'allowpersonaldata');

        return [
            'consenttext' => self::consent_text($clientname, $allowpersonaldata),
            'context_area' => self::location_data('context_area'),
            'material_store' => self::location_data('material_store'),
            'locationselectionurl' => $locationselectionurl->out(false),
            'formurl' => $formurl->out(false),
            'hiddenfields' => self::hidden_fields($params, $state),
            'sesskey' => sesskey(),
            'actions' => [
                ['actionvalue' => 'allow', 'label' => get_string('consentconfirm', 'local_coursepilot')],
                ['actionvalue' => 'deny', 'label' => get_string('consentdeny', 'local_coursepilot')],
            ],
        ];
    }

    /**
     * Provides consent text.
     *
     * @param string $clientname
     * @param bool $allowpersonaldata
     * @return string HTML - the source strings already contain markup (<strong>, <br>).
     */
    private static function consent_text(string $clientname, bool $allowpersonaldata): string {
        return get_string('consentintro', 'local_coursepilot', $clientname) . '<br><br>'
            . get_string('consentgranted', 'local_coursepilot') . '<br>'
            . get_string('consentdenied', 'local_coursepilot') . '<br><br>'
            . get_string('consenttransfer', 'local_coursepilot') . '<br><br>'
            . ($allowpersonaldata
                ? get_string('consentpersonaldataon', 'local_coursepilot')
                : get_string('consentpersonaldataoff', 'local_coursepilot'))
            . '<br><br>'
            . get_string('consentabbreviate', 'local_coursepilot') . '<br><br>'
            . get_string('consentrevoke', 'local_coursepilot');
    }

    /**
     * Provides location data.
     *
     * @param string $target
     * @return array{text: string}
     */
    private static function location_data(string $target): array {
        $location = location_selection::current($target);
        $currentkey = $target === 'context_area'
            ? 'consentlocationcontextareacurrent'
            : 'consentlocationmaterialstorecurrent';
        $text = get_string($currentkey, 'local_coursepilot', $location['display'])
            . ' — ' . location_selection::allowed_label($location);
        return ['text' => $text];
    }

    /**
     * Provides hidden fields.
     *
     * @param string[] $params
     * @param string $state
     * @return list<array{name: string, value: string}>
     */
    private static function hidden_fields(array $params, string $state): array {
        $fields = [];
        foreach ($params as $name => $value) {
            $fields[] = ['name' => $name, 'value' => $value];
        }
        $fields[] = ['name' => 'state', 'value' => $state];
        return $fields;
    }
}
