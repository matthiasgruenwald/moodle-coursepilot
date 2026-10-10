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
 * Synthetic processes for the native history capture/cleanup concurrency regression.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Standalone child process, runs before or without a Moodle bootstrap.

require_once(__DIR__ . '/phpunit_process_bootstrap.php');
if ($argv[1] === 'capture') {
    \core\session\manager::set_user($DB->get_record('user', ['id' => (int) $argv[3]], '*', MUST_EXIST));
    echo \local_coursepilot\history\version_writer::capture((int) $argv[2], (int) $argv[3]);
} else {
    \local_coursepilot\history\retention::enforce();
    echo 'cleaned';
}
