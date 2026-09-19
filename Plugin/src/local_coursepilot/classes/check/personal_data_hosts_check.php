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

namespace local_coursepilot\check;

use core\check\check;
use core\check\result;
use core\output\action_link;
use local_coursepilot\personal_data_hosts;

defined('MOODLE_INTERNAL') || die();

/**
 * Statusprüfung Schritt 4 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): "Zugelassene Speicher". Eine leere Liste (`local_coursepilot |
 * personaldatahosts`) heisst `INFO` ("markierte Dateien nur in Moodle") -
 * kein Mangel, nur der datensparsame Standardzustand.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class personal_data_hosts_check extends check {

    public function get_id(): string {
        return 'webdav_personal_data_hosts';
    }

    public function get_name(): string {
        return get_string('webdavcheck4name', 'local_coursepilot');
    }

    public function get_result(): result {
        $actionlink = new action_link(
            new \moodle_url('/admin/settings.php', ['section' => 'local_coursepilot']),
            get_string('webdavcheckactionlink', 'local_coursepilot')
        );

        if (empty(personal_data_hosts::configured())) {
            return new result(result::INFO, get_string('webdavcheck4info', 'local_coursepilot'), '', $actionlink);
        }

        return new result(result::OK, get_string('webdavcheck4ok', 'local_coursepilot'));
    }
}
