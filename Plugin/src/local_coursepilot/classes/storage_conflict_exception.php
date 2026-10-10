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

/**
 * Conflict on a conditional write via the storage contract (issue #536,
 * spec 0021): the check value passed with the call no longer matches the
 * current state of the file - someone changed it in the meantime, or
 * it has since disappeared. A single message for both
 * future locations (spec 0021: "on conflict ... the same clear message",
 * independent of the area).
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class storage_conflict_exception extends \moodle_exception {
    /**
     * @param string $path Client path of the affected file, for the message.
     */
    public function __construct(string $path) {
        parent::__construct('storageconflict', 'local_coursepilot', '', $path);
    }
}
