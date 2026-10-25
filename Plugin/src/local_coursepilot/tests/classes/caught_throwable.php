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
 * Captures what a call throws so a test can assert on the outcome instead of ending in an error.
 *
 * A mutation or regression that makes the call fail differently (for example with a TypeError)
 * then shows up as a failed assertion about the expected exception.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
trait caught_throwable {
    /**
     * Runs the call and returns whatever it throws.
     *
     * @param callable $call
     * @return \Throwable|null The thrown object, or null if the call completed.
     */
    protected function caught(callable $call): ?\Throwable {
        try {
            $call();
        } catch (\Throwable $e) {
            return $e;
        }
        return null;
    }
}
