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
 * Records the header lines and status code that code in local_coursepilot\output sends (the CLI SAPI discards them).
 *
 * The recording function itself lives in tests/fixtures/output_header_spy.php, in the namespace of the
 * code under test, where PHP resolves the unqualified header() call to it.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class header_spy {
    /** @var string[] Header lines in sending order. */
    public static array $headers = [];

    /** @var int|null Last status code that was set. */
    public static ?int $status = null;

    /**
     * Forgets the recorded lines and status.
     */
    public static function reset(): void {
        self::$headers = [];
        self::$status = null;
    }
}
