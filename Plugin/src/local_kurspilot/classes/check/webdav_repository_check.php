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
use local_kurspilot\admin\pointer_scan;
use local_kurspilot\webdav\webdav_setup_steps;

defined('MOODLE_INTERNAL') || die();

/**
 * Statusprüfung Schritt 1 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): "WebDAV-Repository aktiv". Aus heisst `INFO` ("optional"), solange
 * kein Kontextpointer extern zeigt - niemand nutzt es, also ist "aus" der
 * datensparsame Normalzustand. Zeigt trotzdem ein Pointer extern, ist das
 * Repository fuer diese Personen unerreichbar geworden: `WARNING` mit
 * Anzahl.
 *
 * Nutzt denselben Schrittkatalog ({@see webdav_setup_steps}) und denselben
 * Wortlaut wie der Admin-Text der Ortswahlseite (Akzeptanzkriterium).
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class webdav_repository_check extends check {

    public function get_id(): string {
        return 'webdav_repository';
    }

    public function get_name(): string {
        return get_string('webdavcheck1name', 'local_kurspilot');
    }

    public function get_result(): result {
        global $USER;

        $step = webdav_setup_steps::catalog((int) $USER->id)[webdav_setup_steps::STEP_REPOSITORY_ACTIVE];
        $actionlink = new action_link($step['targeturl'], get_string('webdavcheckactionlink', 'local_kurspilot'));

        if ($step['ok']) {
            return new result(result::OK, get_string('webdavcheck1ok', 'local_kurspilot'));
        }

        $affected = pointer_scan::userids_with_external_target();
        if (empty($affected)) {
            return new result(
                result::INFO,
                get_string('webdavcheck1infooptional', 'local_kurspilot'),
                $step['instruction'],
                $actionlink
            );
        }

        return new result(
            result::WARNING,
            get_string('webdavcheck1warning', 'local_kurspilot', count($affected)),
            $step['instruction'],
            $actionlink
        );
    }
}
