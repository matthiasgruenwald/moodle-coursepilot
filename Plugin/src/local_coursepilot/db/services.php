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
 * External service and webservice functions.
 *
 * Derived from the single {@see \local_coursepilot\tool_registry} (#378),
 * so the functions automatically match
 * {@see \local_coursepilot\privacy_surface::allowed_tools()}.
 * tests/privacy_surface_test.php continues to enforce that contract.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = \local_coursepilot\tool_registry::service_functions();

$services = [
    'Coursepilot' => [
        'functions' => \local_coursepilot\tool_registry::service_function_names(),
        'restrictedusers' => 0,
        'enabled' => 1,
        'shortname' => 'coursepilot',
    ],
];
