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
 * Template data for admin/connections.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

use local_coursepilot\admin\connection_ablageort;

/**
 * Prepares display-neutral state for the admin connections template.
 */
final class admin_connections_page {

    /**
     * @param \stdClass[] $tokens
     * @return array<string, mixed>
     */
    public static function page_data(array $tokens): array {
        $rows = [];
        foreach ($tokens as $token) {
            $storagelocation = connection_ablageort::describe((int) $token->userid);
            $lines = array_merge(array_values($storagelocation['targets']), $storagelocation['markers']);

            $rows[] = [
                'person' => fullname($token) . ' (' . $token->email . ')',
                'clientname' => $token->clientname ?: $token->clientid,
                'since' => userdate($token->timecreated),
                'expires' => userdate($token->expires),
                'ablageortlines' => array_map(static fn(string $line): array => ['text' => $line], $lines),
                'revokeurl' => (new \moodle_url('/local/coursepilot/admin/connections.php', [
                    'revoke' => $token->id,
                    'sesskey' => sesskey(),
                ]))->out(false),
            ];
        }

        return [
            'empty' => $rows === [],
            'rows' => $rows,
            'revokeallurl' => (new \moodle_url('/local/coursepilot/admin/connections.php'))->out(false),
            'sesskey' => sesskey(),
            'revokeallonsubmit' => 'return confirm(' . json_encode(
                get_string('connectionrevokeallconfirm', 'local_coursepilot')
            ) . ');',
        ];
    }
}
