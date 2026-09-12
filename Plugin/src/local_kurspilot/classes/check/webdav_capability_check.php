<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\check;

use core\check\check;
use core\check\result;
use core\output\action_link;
use local_kurspilot\oauth_lib;
use local_kurspilot\webdav\webdav_setup_steps;

defined('MOODLE_INTERNAL') || die();

/**
 * Statusprüfung Schritt 3 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): "WebDAV-Recht im Nutzerkontext", geprüft an der **Wirkung** über
 * `has_capability` im eigenen Nutzerkontext jeder Person mit aktiver
 * Kurspilot-Verbindung - nicht an einer Rollenzuweisung, denn nur die
 * Wirkung zaehlt (mehrere Wege fuehren zur selben Capability).
 *
 * `NA` ohne Verbindungen oder solange Schritt 1 aus ist, `OK`, wenn alle
 * verbundenen Personen das Recht haben, sonst `WARNING` mit "n von m
 * verbundenen Lehrkräften fehlt ..." und den Namen in den Details.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webdav_capability_check extends check {

    public function get_id(): string {
        return 'webdav_capability';
    }

    public function get_name(): string {
        return get_string('webdavcheck3name', 'local_kurspilot');
    }

    public function get_result(): result {
        global $USER, $DB;

        $steps = webdav_setup_steps::catalog((int) $USER->id);
        $step = $steps[webdav_setup_steps::STEP_CAPABILITY];
        $actionlink = new action_link($step['targeturl'], get_string('webdavcheckactionlink', 'local_kurspilot'));

        if (!$steps[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['ok']) {
            return new result(result::NA, get_string('webdavcheck3na', 'local_kurspilot'));
        }

        $connecteduserids = array_unique(array_map(
            static fn (\stdClass $token): int => (int) $token->userid,
            oauth_lib::active_tokens()
        ));
        if (empty($connecteduserids)) {
            return new result(result::NA, get_string('webdavcheck3na', 'local_kurspilot'));
        }

        $missing = [];
        foreach ($connecteduserids as $userid) {
            // $userid explizit als dritter Parameter - sonst prueft
            // has_capability() die anmeldete Administrationsperson statt der
            // verbundenen Lehrkraft, deren Wirkung hier gefragt ist.
            if (!has_capability('repository/webdav:view', \context_user::instance($userid), $userid)) {
                $user = $DB->get_record('user', ['id' => $userid]);
                $missing[] = $user ? fullname($user) : (string) $userid;
            }
        }

        if (empty($missing)) {
            return new result(result::OK, get_string('webdavcheck3ok', 'local_kurspilot'));
        }

        return new result(
            result::WARNING,
            get_string('webdavcheck3warning', 'local_kurspilot', (object) [
                'missing' => count($missing),
                'total' => count($connecteduserids),
            ]),
            implode("\n", $missing),
            $actionlink
        );
    }
}
