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
 * Location-selection page (#494, Spec #486 §5/§10), beside connection
 * self-service in the profile: chooses context and material locations
 * for the logged-in teacher and their owned WebDAV instances only.
 *
 * Thin shell (#334): {@see \local_coursepilot\location_selection} owns all
 * testable logic; this file only accepts form input and renders markup
 * (#494: test the class rather than the page).
 *
 * #507 (Spec #486 review): page setup and form handling use functions
 * below 50 lines.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\webdav\webdav_setup_steps;

require_login(null, false);
$context = context_system::instance();
\local_coursepilot\remote_access::require_granted();

global $USER, $OUTPUT, $PAGE;

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url(webdav_setup_steps::LOCATION_SELECTION_PAGE));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('locationselectiontitle', 'local_coursepilot'));
$PAGE->set_heading(get_string('locationselectionheading', 'local_coursepilot'));

\local_coursepilot\output\location_selection_page::response(
    (bool) optional_param('oauthflow', 0, PARAM_BOOL),
    (bool) optional_param('finish', 0, PARAM_BOOL)
)->send();
