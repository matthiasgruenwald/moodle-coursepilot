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
// Synthetic process for native public OAuth boundary concurrency regressions.

require_once(__DIR__ . '/phpunit_process_bootstrap.php');
$result = $argv[1] !== 'revoke'
    ? \local_coursepilot\oauth_lib::rotate_refresh_token($argv[2], 'client-a')
    : \local_coursepilot\oauth_lib::revoke_token((int) $argv[3]);
echo json_encode($result);
