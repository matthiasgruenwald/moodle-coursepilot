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
 * Moodle-Callback-Bibliothek.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Verlinkt die Selbstverwaltungsseite (#338) im eigenen Profil - nur in der
 * eigenen Ansicht, nie im fremden Profil (Moodle ruft diesen Callback pro
 * betrachtetem Profil auf; $iscurrentuser unterscheidet).
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

    // Ortswahlseite (#494), neben "Meine Verbindungen" - nur in der eigenen
    // Profilansicht, aus demselben Grund wie oben.
    $ortswahlnode = new \core_user\output\myprofile\node(
        'miscellaneous',
        'local_coursepilot_ortswahl',
        get_string('ortswahl', 'local_coursepilot'),
        null,
        new moodle_url(\local_coursepilot\webdav\webdav_setup_steps::ORTSWAHL_PAGE)
    );
    $tree->add_node($ortswahlnode);

    return true;
}

/**
 * Verlinkt die Ortswahl- und die Verbindungsseite zusaetzlich auf der
 * Einstellungsseite der eigenen Person (Issue #524, Spec #486 §5, gefunden
 * in der Live-Abnahme #505 Befund #12): bislang standen beide Links nur im
 * eigenen Profil unter "Verschiedenes" - dort, wo eine Lehrkraft nach
 * Konfiguration sucht (`/user/preferences.php`), fehlte jeder Eintrag.
 *
 * Nur in der eigenen Ansicht und nur mit dem Recht, das auch die
 * Ortswahlseite selbst verlangt (`local/coursepilot:useremote`) - Moodle ruft
 * diesen Callback auch beim Betrachten fremder Einstellungsseiten
 * (Administration) auf, $user/$usercontext beziehen sich dann auf die
 * betrachtete, nicht die angemeldete Person.
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
    if (!has_capability('local/coursepilot:useremote', \context_system::instance())) {
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
        get_string('ortswahl', 'local_coursepilot'),
        new moodle_url(\local_coursepilot\webdav\webdav_setup_steps::ORTSWAHL_PAGE),
        \navigation_node::TYPE_SETTING,
        null,
        'local_coursepilot_settings_ortswahl'
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
 * Verlinkt die Verlaufsseite (#397, Spec 0015 §10.6/§10.7) in der
 * Kursnavigation - nur sichtbar mit local/coursepilot:viewhistory, damit die
 * Seite fuer Nutzer ohne diese Faehigkeit gar nicht erst als Link auftaucht
 * (require_capability() auf history.php selbst greift zusaetzlich, auch bei
 * direktem URL-Aufruf).
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
 * Standard-Moodle-Callback fuer die Admin-Statusprüfung
 * ("<component>_status_checks()", siehe lib/classes/check/manager.php:
 * get_status_checks() ruft get_plugins_with_function('status_checks',
 * 'lib.php') auf) - eine Prüfung je katalogisierter Aktivitätsart (Ticket
 * #399, Spec 0015 §11, ADR 0017).
 *
 * @return \core\check\check[]
 */
function local_coursepilot_status_checks(): array {
    return array_merge(
        array_map(
            static fn (string $modname): \local_coursepilot\check\activity_drift => new \local_coursepilot\check\activity_drift($modname),
            \local_coursepilot\catalog\registry::known_modnames()
        ),
        [
            // Die vier WebDAV-Statusprüfungen (Issue #499, Spec #486 §12) -
            // eine je Schritt des Schrittkatalogs plus die zugelassenen Speicher.
            new \local_coursepilot\check\webdav_repository_check(),
            new \local_coursepilot\check\webdav_user_instances_check(),
            new \local_coursepilot\check\webdav_capability_check(),
            new \local_coursepilot\check\personal_data_hosts_check(),
        ]
    );
}
