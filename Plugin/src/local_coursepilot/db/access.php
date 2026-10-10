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
 * Capabilities (decision #296).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Course-related tool calls.
    'local/coursepilot:use' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
    // Remote MCP access (#296, #579): one of two grant methods alongside
    // selected system cohorts (local_coursepilot\remote_access, ADR 0026).
    // No archetype default: a globally assigned teacher role must not
    // automatically grant remote access. Schools explicitly enable it
    // in an existing system role or select a cohort.
    'local/coursepilot:useremote' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],
    // Activity-history access (#394, Spec 0015 §10.6): a separate capability
    // from local/coursepilot:use, as required by the history specification.
    'local/coursepilot:viewhistory' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
    // Restore an earlier activity version (#395, Spec 0015 §10.7). Like
    // local/coursepilot:use for other write tools, this capability grants
    // access to the operation itself; moodle/course:manageactivities
    // additionally controls actual editing.
    'local/coursepilot:restoreversion' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
        ],
    ],
];
