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
namespace local_coursepilot\tests;

/**
 * Configuration of the random_bytes(), random_string() and time() replacements of tests/fixtures/native_override_functions.php.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class native_override {
    /** @var array<int, string> Fixed random_bytes() result per requested length. */
    public static array $bytes = [];

    /** @var array<int, string> Fixed random_string() result per requested length. */
    public static array $strings = [];

    /** @var int|null Frozen time, or null for the real clock. */
    public static ?int $now = null;

    /**
     * Restores the real functions.
     */
    public static function reset(): void {
        self::$bytes = [];
        self::$strings = [];
        self::$now = null;
    }
}
