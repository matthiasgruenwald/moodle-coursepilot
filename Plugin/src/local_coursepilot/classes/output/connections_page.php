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
 * Template data for connections.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

/**
 * Prepares display-neutral state for the teacher's connections template.
 */
final class connections_page {

    /**
     * @param \stdClass[] $tokens
     * @param array<string, array<string, string>> $currentlocations see location_selection::current_locations_data()
     * @param \moodle_url $ortswahlurl
     * @return array<string, mixed>
     */
    public static function page_data(array $tokens, array $currentlocations, \moodle_url $ortswahlurl): array {
        $rows = [];
        foreach ($tokens as $token) {
            $rows[] = [
                'clientname' => $token->clientname ?: $token->clientid,
                'since' => userdate($token->timecreated),
                'expires' => userdate($token->expires),
                'revokeurl' => (new \moodle_url('/local/coursepilot/connections.php', [
                    'revoke' => $token->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }

        return [
            'currentlocations' => $currentlocations,
            'ortswahlurl' => $ortswahlurl->out(false),
            'empty' => $rows === [],
            'rows' => $rows,
        ];
    }
}
