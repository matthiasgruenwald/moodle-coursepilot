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
use local_kurspilot\webdav\webdav_setup_steps;

defined('MOODLE_INTERNAL') || die();

/**
 * Statusprüfung Schritt 2 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): "Nutzerinstanzen erlaubt". `NA`, solange Schritt 1 (Repository aktiv)
 * aus ist - ohne aktives Repository ist diese Option ohne Wirkung. Sonst
 * `WARNING`, wenn sie fehlt, mit dem Hinweis aufs App-Passwort (Teil des
 * Schrittkatalog-Wortlauts).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webdav_user_instances_check extends check {

    public function get_id(): string {
        return 'webdav_user_instances';
    }

    public function get_name(): string {
        return get_string('webdavcheck2name', 'local_kurspilot');
    }

    public function get_result(): result {
        global $USER;

        $steps = webdav_setup_steps::catalog((int) $USER->id);
        if (!$steps[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['ok']) {
            return new result(result::NA, get_string('webdavcheck2na', 'local_kurspilot'));
        }

        $step = $steps[webdav_setup_steps::STEP_USER_INSTANCES];
        $actionlink = new action_link($step['targeturl'], get_string('webdavcheckactionlink', 'local_kurspilot'));

        if ($step['ok']) {
            return new result(result::OK, get_string('webdavcheck2ok', 'local_kurspilot'));
        }

        return new result(
            result::WARNING,
            get_string('webdavcheck2warning', 'local_kurspilot'),
            $step['instruction'],
            $actionlink
        );
    }
}
