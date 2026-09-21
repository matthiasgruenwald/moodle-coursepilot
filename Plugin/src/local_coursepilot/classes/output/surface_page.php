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
 * Template data for surface.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

use local_coursepilot\instance_check;
use local_coursepilot\privacy_surface;

/**
 * Prepares display-neutral state for the surface.php (status) template.
 */
final class surface_page {

    /**
     * @param array<int, array{type: string, name: string, detail: string}> $violations
     * @param string[] $registered
     * @param array{ok: bool, detail: string, url: string, httpcode: ?int} $selfcheck
     * @return array<string, mixed>
     */
    public static function page_data(array $violations, array $registered, array $selfcheck): array {
        return [
            'hasviolations' => $violations !== [],
            'violations' => array_map(static fn(array $violation): array => [
                'type' => $violation['type'],
                'name' => $violation['name'],
                'detail' => $violation['detail'],
            ], $violations),
            'allowedtools' => self::allowed_tools_rows(),
            'registered' => self::list_items($registered),
            'forbidden' => self::list_items(privacy_surface::FORBIDDEN_TOKENS),
            'requirements' => self::list_items(array_map(
                static fn(string $requirement): string => get_string('surfacereq' . $requirement, 'local_coursepilot'),
                instance_check::REQUIREMENTS
            )),
            'selfcheckurltext' => get_string('selfcheckurl', 'local_coursepilot', $selfcheck['url']),
            'selfcheckok' => $selfcheck['ok'],
            'selfcheckerror' => $selfcheck['ok'] ? null : get_string($selfcheck['detail'], 'local_coursepilot'),
            'emergencyexithint' => $selfcheck['ok'] ? null : get_string(
                'surfaceinstanceemergencyexit',
                'local_coursepilot',
                instance_check::EMERGENCY_EXIT_RULE
            ),
        ];
    }

    /**
     * @return list<array{tool: string, function: string}>
     */
    private static function allowed_tools_rows(): array {
        $rows = [];
        foreach (privacy_surface::allowed_tools() as $tool => $function) {
            $rows[] = ['tool' => $tool, 'function' => $function];
        }
        return $rows;
    }

    /**
     * @param string[] $items
     * @return list<array{text: string}>
     */
    private static function list_items(array $items): array {
        return array_map(static fn(string $item): array => ['text' => $item], $items);
    }
}
