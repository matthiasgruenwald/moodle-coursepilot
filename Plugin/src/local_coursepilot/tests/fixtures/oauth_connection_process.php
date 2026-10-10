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
 * Synthetic process for native public OAuth boundary concurrency regressions.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Standalone child process, runs before or without a Moodle bootstrap.

require_once(__DIR__ . '/phpunit_process_bootstrap.php');
$result = $argv[1] !== 'revoke'
    ? \local_coursepilot\oauth_lib::rotate_refresh_token($argv[2], 'client-a')
    : \local_coursepilot\oauth_lib::revoke_token((int) $argv[3]);
echo json_encode($result);
