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
 * Synthetic process for native anonymous OAuth budget concurrency regressions:
 * argv[1] is the request source; with argv[2] (a CIMD client_id URL) the
 * process asks the public token endpoint (#643), otherwise it registers (#642).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require_once(__DIR__ . '/phpunit_process_bootstrap.php');
if (isset($argv[2])) {
    $_SERVER['REMOTE_ADDR'] = $argv[1];
    echo \local_coursepilot\oauth_lib::handle_token('POST',
        ['grant_type' => 'authorization_code', 'client_id' => $argv[2]])['status'];
} else {
    echo \local_coursepilot\oauth_lib::handle_registration('POST',
        json_encode(['redirect_uris' => ['https://client.example/callback']]), $argv[1])['status'];
}
