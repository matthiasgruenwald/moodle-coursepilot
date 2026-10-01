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
     * @param string $clientname
     * @param array<string, string> $params response_type/client_id/redirect_uri/code_challenge/code_challenge_method
     * @param string $state
     * @param \moodle_url $formurl
     * @param \moodle_url $ortswahlurl
     * @return array<string, mixed>
     */
    public static function page_data(
        string $clientname,
        array $params,
        string $state,
        \moodle_url $formurl,
        \moodle_url $ortswahlurl
    ): array {
        $allowpersonaldata = (bool) get_config('local_coursepilot', 'allowpersonaldata');

        return [
            'consenttext' => self::consent_text($clientname, $allowpersonaldata),
            'kontextbereich' => self::location_data('kontextbereich'),
            'materialbestand' => self::location_data('materialbestand'),
            'ortswahlurl' => $ortswahlurl->out(false),
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
     * @param string $target
     * @return array{text: string}
     */
    private static function location_data(string $target): array {
        $location = location_selection::current($target);
        $currentkey = $target === 'kontextbereich'
            ? 'consentlocationkontextbereichcurrent'
            : 'consentlocationmaterialbestandcurrent';
        $text = get_string($currentkey, 'local_coursepilot', $location['display'])
            . ' — ' . location_selection::zugelassen_label($location);
        return ['text' => $text];
    }

    /**
     * @param array<string, string> $params
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
