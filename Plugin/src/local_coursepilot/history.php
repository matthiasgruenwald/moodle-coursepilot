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
 * Delegates course history and restoration to the tested page controller.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
// phpcs:ignore moodle.Files.RequireLogin.Missing -- The controller requires course login before reading history or restoring.
require(__DIR__ . '/../../config.php');

\local_coursepilot\output\history_page::response(
    optional_param('cmid', 0, PARAM_INT),
    optional_param('id', 0, PARAM_INT),
    optional_param('restore', 0, PARAM_INT),
    (bool) optional_param('confirmed', 0, PARAM_BOOL)
)->send();
