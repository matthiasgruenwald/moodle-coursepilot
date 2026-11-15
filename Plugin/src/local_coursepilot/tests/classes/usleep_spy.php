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
 * Records the microseconds that local_coursepilot\webdav code asks usleep() to wait.
 *
 * The recording function itself lives in tests/fixtures/webdav_usleep_spy.php, in the namespace of the
 * code under test, where PHP resolves the unqualified usleep() call to it.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class usleep_spy {
    /** @var int[] Requested waits in microseconds. */
    public static array $calls = [];

    /** @var bool Whether the waits are only recorded (true) or also performed (false). */
    public static bool $mute = false;

    /**
     * Forgets the recorded waits and performs waits again.
     */
    public static function reset(): void {
        self::$calls = [];
        self::$mute = false;
    }
}
