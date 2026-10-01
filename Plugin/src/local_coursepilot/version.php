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
$plugin->version   = 2026100107;
// 2.0.x sagt Moodle 5.0 zu (geprueft). Ab 2.1 gilt Moodle 5.1 als
// Mindestversion (ADR 0027).
$plugin->requires  = 2025041400;
// Beta nach dem Praxistest (ADR 0027). Die Linie setzt Coursepilot 1.x fort:
// der Neubau ist Version 2, keine zweite Produktlinie.
$plugin->maturity  = MATURITY_BETA;
$plugin->release   = '2.0.0-beta';
