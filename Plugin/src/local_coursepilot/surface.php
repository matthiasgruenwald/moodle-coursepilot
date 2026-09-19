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
 * Prueffaehigkeit von aussen: zeigt Allowlist, verbotene Namensbestandteile
 * und den Abgleich mit der real registrierten Oberflaeche (#300, Punkt 6),
 * sowie die Instanzpruefung per Selbstabruf der Discovery-Adresse (#340) -
 * bewusst keine eigene Diagnoseseite, sondern Erweiterung dieser Seite.
 *
 * Dritter Aufrufer derselben Prueffunktion neben Test und mcp.php.
 * Nur mit 'moodle/site:config' erreichbar - require_capability() unten
 * greift unabhaengig davon, ob die Seite verlinkt oder direkt per URL
 * aufgerufen wird (Muster aus admin/connections.php).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_coursepilot\instance_check;
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
echo html_writer::tag('p', get_string('surfaceintro', 'local_coursepilot'));

echo $OUTPUT->heading(get_string('surfacestatus', 'local_coursepilot'), 3);
if (!$violations) {
    echo $OUTPUT->notification(get_string('surfaceok', 'local_coursepilot'), \core\output\notification::NOTIFY_SUCCESS);
} else {
    echo $OUTPUT->notification(get_string('surfaceviolations', 'local_coursepilot'), \core\output\notification::NOTIFY_ERROR);
    $rows = [];
    foreach ($violations as $violation) {
        $rows[] = new html_table_row([
            s($violation['type']),
            s($violation['name']),
            s($violation['detail']),
        ]);
    }
    $table = new html_table();
    $table->head = ['Typ', 'Name', 'Detail'];
    $table->data = $rows;
    echo html_writer::table($table);
}

$list = function(array $items): string {
    return html_writer::alist(array_map('s', $items));
};

echo $OUTPUT->heading(get_string('surfaceallowed', 'local_coursepilot'), 3);
$rows = [];
foreach (privacy_surface::allowed_tools() as $tool => $function) {
    $rows[] = new html_table_row([s($tool), s($function)]);
}
$table = new html_table();
$table->head = ['MCP-Tool', 'Webservice-Funktion'];
$table->data = $rows;
echo html_writer::table($table);

echo $OUTPUT->heading(get_string('surfaceregistered', 'local_coursepilot'), 3);
echo $list($registered);

echo $OUTPUT->heading(get_string('surfaceforbidden', 'local_coursepilot'), 3);
echo $list(privacy_surface::FORBIDDEN_TOKENS);

echo $OUTPUT->heading(get_string('surfaceinstance', 'local_coursepilot'), 3);
echo html_writer::tag('p', get_string('surfaceinstanceintro', 'local_coursepilot'));
echo $list(array_map(
    static fn(string $requirement): string => get_string('surfacereq' . $requirement, 'local_coursepilot'),
    instance_check::REQUIREMENTS
));

echo html_writer::tag('p', get_string('selfcheckurl', 'local_coursepilot', s($selfcheck['url'])));
if ($selfcheck['ok']) {
    echo $OUTPUT->notification(
        get_string('selfcheckok', 'local_coursepilot'),
        \core\output\notification::NOTIFY_SUCCESS
    );
} else {
    echo $OUTPUT->notification(
        get_string($selfcheck['detail'], 'local_coursepilot'),
        \core\output\notification::NOTIFY_ERROR
    );
    echo html_writer::tag('p', get_string(
        'surfaceinstanceemergencyexit',
        'local_coursepilot',
        s(instance_check::EMERGENCY_EXIT_RULE)
    ));
}

echo $OUTPUT->footer();
