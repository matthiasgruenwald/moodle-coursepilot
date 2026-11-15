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
 * Connection self-service: teachers see and revoke only their own
 * active remote connections. connection::active_tokens_for_user()/revoke_token()
 * enforce ownership in database queries, not merely in display. Linked
 * from the profile by local_coursepilot_myprofile_navigation() in lib.php.
 *
 * Thin I/O shell: {@see \local_coursepilot\connection} owns the connection lifecycle.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\output\connections_page;

require_login(null, false);

global $USER;
$context = context_system::instance();
\local_coursepilot\remote_access::require_granted();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursepilot/connections.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myconnections', 'local_coursepilot'));
$PAGE->set_heading(get_string('myconnections', 'local_coursepilot'));

connections_page::response(optional_param('revoke', 0, PARAM_INT))->send();
