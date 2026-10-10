<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.
// Shared bootstrap for synthetic child processes on Moodle 5.0 and 5.1.
// Moodle bootstrap may enable display_errors even when CLI requested stderr.
// Keep startup diagnostics on stderr so stdout remains the fixture's protocol.

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
