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

/**
 * External inspectability: shows allowlist, forbidden name fragments and
 * comparison to actual registered functions (#300, item 6). Also includes
 * discovery-URL self-check (#340) instead of creating a separate diagnostics page.
 *
 * Third caller of the shared validation besides tests and mcp.php.
 * Requires moodle/site:config for linked and direct URL access, following
 * admin/connections.php.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\instance_check;
use local_coursepilot\output\surface_page;
use local_coursepilot\privacy_surface;

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/coursepilot/surface.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('surface', 'local_coursepilot'));
$PAGE->set_heading(get_string('surface', 'local_coursepilot'));

$registered = privacy_surface::registered_functions();
$violations = privacy_surface::check($registered);
$selfcheck = instance_check::self_check($CFG->wwwroot);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template(
    'local_coursepilot/surface',
    surface_page::page_data($violations, $registered, $selfcheck)
);
echo $OUTPUT->footer();
