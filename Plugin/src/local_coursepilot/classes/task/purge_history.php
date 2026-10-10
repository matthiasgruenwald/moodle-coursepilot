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

namespace local_coursepilot\task;

use local_coursepilot\history\retention;

/**
 * Enforces the history retention period and cleans historical file metadata (#640).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class purge_history extends \core\task\scheduled_task {
    /**
     * Returns name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskpurgehistory', 'local_coursepilot');
    }

    /**
     * Runs the purge history tool.
     *
     * @return void
     */
    public function execute(): void {
        retention::enforce();
    }
}
