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
 * Test fixture: records header() calls made by local_coursepilot\output classes (see tests/classes/header_spy.php).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

if (!function_exists('local_coursepilot\output\header')) {
    /**
     * Records the header line instead of sending it.
     *
     * @param string $header Header line.
     * @param bool $replace Ignored.
     * @param int $code Ignored.
     */
    function header(string $header, bool $replace = true, int $code = 0): void {
        \local_coursepilot\tests\header_spy::$headers[] = $header;
    }
}

if (!function_exists('local_coursepilot\output\http_response_code')) {
    /**
     * Records the status code instead of setting it.
     *
     * @param int $code Status code.
     * @return bool
     */
    function http_response_code(int $code): bool {
        \local_coursepilot\tests\header_spy::$status = $code;
        return true;
    }
}
