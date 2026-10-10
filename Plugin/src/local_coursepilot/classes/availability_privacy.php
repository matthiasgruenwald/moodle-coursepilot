<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

/**
 * Masks the personal data in Moodle availability conditions before
 * output to an AI (#341).
 *
 * Standalone port of local_coursepilot\availability_privacy:
 * according to spec 0012 ("no dependency on
 * local_coursepilot") local_coursepilot has no runtime dependency on the other plugin -
 * the spike test instance carries only local_coursepilot, a
 * class reference to local_coursepilot would be a fatal error there (finding from
 * the PHPUnit run for #341). Shared pure function INSIDE
 * local_coursepilot, no special handling in the catalog code.
 *
 * Rules:
 * - Empty string -> empty string.
 * - Unparseable JSON -> empty string (cannot be judged safely).
 * - Nested "c" condition lists are processed recursively.
 * - For type==="profile" conditions: "v" is replaced by "***".
 *   "sf"/"cf" and "op" are kept so the AI knows that a
 *   personal-data restriction exists (omitting would be worse than
 *   masking).
 * - All other types/fields remain unchanged.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class availability_privacy {
    /**
     * @param string $availability Raw Moodle availability JSON.
     * @return string Masked JSON, or empty string.
     */
    public static function sanitize(string $availability): string {
        if ($availability === '') {
            return '';
        }
        $data = json_decode($availability, true);
        if ($data === null) {
            return '';
        }
        $data = self::sanitize_node($data);
        return json_encode($data);
    }

    /**
     * @param array $node
     * @return array
     */
    private static function sanitize_node(array $node): array {
        if (!isset($node['c']) || !is_array($node['c'])) {
            return $node;
        }
        $node['c'] = array_map([self::class, 'sanitize_condition'], $node['c']);
        return $node;
    }

    /**
     * @param array $condition
     * @return array
     */
    private static function sanitize_condition(array $condition): array {
        if (isset($condition['c']) && is_array($condition['c'])) {
            return self::sanitize_node($condition);
        }
        if (($condition['type'] ?? '') === 'profile') {
            $condition['v'] = '***';
        }
        return $condition;
    }
}
