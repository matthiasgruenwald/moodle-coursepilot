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
 * Capabilities (Kartenentscheidung #296).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Kursbezogene Tool-Calls.
    'local/coursepilot:use' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
    // Fernzugriff ueber den MCP-Endpunkt - systemweit abschaltbar, ohne
    // einzelne Kurse anzufassen (#296, Punkt 1).
    'local/coursepilot:useremote' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
    // Einsicht in den Aenderungsverlauf einer Aktivitaet (#394, Spec 0015
    // §10.6) - eigene Faehigkeit statt local/coursepilot:use, weil Spec 0015
    // §10.6 fuer den Verlauf ausdruecklich eigene Faehigkeiten vorsieht.
    'local/coursepilot:viewhistory' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
    // Rueckkehr zu einer frueheren Version einer Aktivitaet (#395, Spec 0015
    // §10.7) - eigene Faehigkeit wie local/coursepilot:use bei allen anderen
    // Schreibwerkzeugen: prueft nur "darf dieses Werkzeug ueberhaupt nutzen",
    // die eigentliche Schreibberechtigung liefert zusaetzlich
    // moodle/course:manageactivities.
    'local/coursepilot:restoreversion' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
];
