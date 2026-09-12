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
use local_kurspilot\personal_data_hosts;

defined('MOODLE_INTERNAL') || die();

/**
 * Statusprüfung Schritt 4 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): "Zugelassene Speicher". Eine leere Liste (`local_kurspilot |
 * personaldatahosts`) heisst `INFO` ("markierte Dateien nur in Moodle") -
 * kein Mangel, nur der datensparsame Standardzustand.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class personal_data_hosts_check extends check {

    public function get_id(): string {
        return 'webdav_personal_data_hosts';
    }

    public function get_name(): string {
        return get_string('webdavcheck4name', 'local_kurspilot');
    }

    public function get_result(): result {
        $actionlink = new action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'local_kurspilot']),
            get_string('webdavcheckactionlink', 'local_kurspilot')
        );

        if (empty(personal_data_hosts::configured())) {
            return new result(result::INFO, get_string('webdavcheck4info', 'local_kurspilot'), '', $actionlink);
        }

        return new result(result::OK, get_string('webdavcheck4ok', 'local_kurspilot'));
    }
}
