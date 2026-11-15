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
 * Shared bootstrap for synthetic child processes on Moodle 5.0 and 5.1.
 * Moodle bootstrap may enable display_errors even when CLI requested stderr.
 * Keep startup diagnostics on stderr so stdout remains the fixture's protocol.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Standalone child process, runs before or without a Moodle bootstrap.

ob_start();
try {
    $moodleroot = dirname(__DIR__, 4);
    $vendorroot = is_file($moodleroot . '/vendor/autoload.php') ? $moodleroot : dirname($moodleroot);
    require_once($vendorroot . '/vendor/autoload.php');
    define('PHPUNIT_UTIL', true);
    require_once($moodleroot . '/lib/phpunit/bootstrap.php');
} finally {
    fwrite(STDERR, ob_get_clean());
}
ini_set('display_errors', 'stderr');
