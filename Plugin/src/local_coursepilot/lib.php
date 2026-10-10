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
 * Moodle callback library.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

/**
 * Links connection self-service (#338) only in the current user's profile.
 * Moodle invokes this callback for each viewed profile; $iscurrentuser
 * prevents links appearing in another person's profile.
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 * @return bool
 */
function local_coursepilot_myprofile_navigation(
    \core_user\output\myprofile\tree $tree,
    $user,
    $iscurrentuser,
    $course
): bool {
    if (!$iscurrentuser) {
        return false;
    }

    $node = new \core_user\output\myprofile\node(
        'miscellaneous',
        'local_coursepilot_connections',
        get_string('myconnections', 'local_coursepilot'),
        null,
        new moodle_url('/local/coursepilot/connections.php')
    );
    $tree->add_node($node);

    // Location selection (#494) beside the connections link, only in the
    // current user's own profile for the same reason as above.
    $locationselectionnode = new \core_user\output\myprofile\node(
        'miscellaneous',
        'local_coursepilot_location_selection',
        get_string('locationselection', 'local_coursepilot'),
        null,
        new moodle_url(\local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE)
    );
    $tree->add_node($locationselectionnode);

    return true;
}

/**
 * Adds location-selection and connection links to the current user's
 * preferences (#524, Spec #486 §5, live acceptance #505 finding 12).
 * Previously both appeared only under Miscellaneous in the profile, not
 * on /user/preferences.php where teachers look for configuration.
 *
 * Only the current user with remote access (#579) sees these links,
 * matching the location-selection page. Moodle also invokes this callback
 * on other users' settings pages in administration; $user/$usercontext
 * then identify the viewed person, not the logged-in person.
 *
 * @param \navigation_node $navigation
 * @param \stdClass $user
 * @param \context_user $usercontext
 * @param \stdClass $course
 * @param \context_course $coursecontext
 * @return void
 */
function local_coursepilot_extend_navigation_user_settings(
    \navigation_node $navigation,
    \stdClass $user,
    \context_user $usercontext,
    \stdClass $course,
    \context_course $coursecontext
): void {
    global $USER;

    if ((int) $user->id !== (int) $USER->id) {
        return;
    }
    if (!\local_coursepilot\remote_access::is_granted()) {
        return;
    }

    $coursepilot = $navigation->add(
        get_string('coursepilotsettingsheading', 'local_coursepilot'),
        null,
        \navigation_node::TYPE_CONTAINER,
        null,
        'local_coursepilot_settings'
    );
    $coursepilot->add(
        get_string('locationselection', 'local_coursepilot'),
        new moodle_url(\local_coursepilot\webdav\webdav_setup_steps::LOCATION_SELECTION_PAGE),
        \navigation_node::TYPE_SETTING,
        null,
        'local_coursepilot_settings_location_selection'
    );
    $coursepilot->add(
        get_string('myconnections', 'local_coursepilot'),
        new moodle_url('/local/coursepilot/connections.php'),
        \navigation_node::TYPE_SETTING,
        null,
        'local_coursepilot_settings_connections'
    );
}

/**
 * Links history (#397, Spec 0015 §10.6/§10.7) in course navigation only
 * with local/coursepilot:viewhistory. history.php additionally requires
 * that capability for direct URL visits.
 *
 * @param \navigation_node $navigation
 * @param \stdClass $course
 * @param \context_course $context
 * @return void
 */
function local_coursepilot_extend_navigation_course(
    \navigation_node $navigation,
    \stdClass $course,
    \context_course $context
): void {
    if (!has_capability('local/coursepilot:viewhistory', $context)) {
        return;
    }

    $navigation->add(
        get_string('historynavnode', 'local_coursepilot'),
        new moodle_url('/local/coursepilot/history.php', ['id' => $course->id]),
        \navigation_node::TYPE_SETTING,
        null,
        'local_coursepilot_history'
    );
}

/**
 * Standard Moodle admin status-check callback (<component>_status_checks()).
 * lib/classes/check/manager.php::get_status_checks() discovers it through
 * get_plugins_with_function(status_checks, lib.php). One check per cataloged
 * activity type (#399, Spec 0015 §11, ADR 0017).
 *
 * @return \core\check\check[]
 */
function local_coursepilot_status_checks(): array {
    return array_merge(
        array_map(
            static fn (string $modname): \local_coursepilot\check\activity_drift =>
                new \local_coursepilot\check\activity_drift($modname),
            \local_coursepilot\catalog\registry::known_modnames()
        ),
        [
            // Four WebDAV checks (#499, Spec #486 §12): one per setup step
            // and the allowed personal-data storage hosts.
            new \local_coursepilot\check\webdav_repository_check(),
            new \local_coursepilot\check\webdav_user_instances_check(),
            new \local_coursepilot\check\webdav_capability_check(),
            new \local_coursepilot\check\personal_data_hosts_check(),
        ]
    );
}
