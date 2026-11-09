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
 * Test fixture: replaceable plugin_supports() for local_coursepilot\external classes.
 *
 * The function lives in the namespace of the code under test, where PHP resolves the unqualified call to it.
 * Without a configured module it delegates to the real function (see tests/classes/plugin_support_override.php).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\external;

if (!function_exists('local_coursepilot\external\plugin_supports')) {
    /**
     * Answers false for configured activity modules, otherwise asks Moodle.
     *
     * @param string $type Plugin type.
     * @param string $name Plugin name.
     * @param string $feature Feature constant.
     * @param mixed $default Default answer.
     * @return mixed
     */
    function plugin_supports(string $type, string $name, string $feature, mixed $default = null): mixed {
        if ($type === 'mod' && isset(\local_coursepilot\tests\plugin_support_override::$unsupported[$name])) {
            return false;
        }
        return \plugin_supports($type, $name, $feature, $default);
    }
}
