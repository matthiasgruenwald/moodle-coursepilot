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
 * Coursepilot: MCP-Endpunkt auf dem Moodle-Server.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_coursepilot';
$plugin->version   = 2026092101;
// Moodle 5.0 wird zugesagt. Der Sicherheitssupport fuer 5.0 endet am
// 05.10.2026; dann wird auf 5.1 als Mindestversion gehoben (ADR 0024).
$plugin->requires  = 2025041400;
// Alpha bis der eigene Produktivbetrieb etwas anderes zeigt. Die Linie setzt
// Coursepilot 1.x fort: der Neubau ist Version 2, keine zweite Produktlinie.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '2.0.0-alpha';
