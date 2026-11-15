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
 * Test fixture: replaceable random_bytes(), random_string() and time() for local_coursepilot classes.
 *
 * The functions live in the namespace of the code under test, where PHP resolves the unqualified calls to
 * them. Without a configured override they delegate to the real functions (see tests/classes/native_override.php).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot;

if (!function_exists('local_coursepilot\random_bytes')) {
    /**
     * Returns the fixed bytes configured for this length, otherwise real random bytes.
     *
     * @param int $length Requested length.
     * @return string
     */
    function random_bytes(int $length): string {
        return \local_coursepilot\tests\native_override::$bytes[$length] ?? \random_bytes($length);
    }

    /**
     * Returns the fixed string configured for this length, otherwise a real random string.
     *
     * @param int $length Requested length.
     * @return string
     */
    function random_string(int $length = 15): string {
        return \local_coursepilot\tests\native_override::$strings[$length] ?? \random_string($length);
    }

    /**
     * Returns the frozen time when one is configured, otherwise the real time.
     *
     * @return int
     */
    function time(): int {
        return \local_coursepilot\tests\native_override::$now ?? \time();
    }
}
