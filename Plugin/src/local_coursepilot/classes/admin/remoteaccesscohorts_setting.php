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

namespace local_coursepilot\admin;

use local_coursepilot\remote_access;

// admin_setting_configmultiselect ist eine legacy-globale Klasse aus
// lib/adminlib.php - siehe personaldatahosts_setting.
global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * `local_coursepilot | remoteaccesscohorts` (#579, ADR 0026): Mehrfachauswahl
 * bestehender Systemkohorten fuer die Fernzugriffsfreigabe. Die Auswahl wird
 * erst beim Anzeigen geladen; unter dem Feld stehen die Mitgliederzahl je
 * gewaehlter Kohorte und gewaehlte, inzwischen geloeschte Kohorten.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class remoteaccesscohorts_setting extends \admin_setting_configmultiselect {

    public function __construct() {
        parent::__construct(
            'local_coursepilot/remoteaccesscohorts',
            get_string('settingremoteaccesscohorts', 'local_coursepilot'),
            get_string('settingremoteaccesscohorts_desc', 'local_coursepilot'),
            [],
            null
        );
    }

    /**
     * @return bool
     */
    public function load_choices() {
        global $DB;

        if (is_array($this->choices)) {
            return true;
        }
        $this->choices = $DB->get_records_menu(
            'cohort',
            ['contextid' => \context_system::instance()->id],
            'name',
            'id, name'
        );
        return true;
    }

    /**
     * @param mixed $data
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $notes = [];
        foreach (remote_access::selected_cohort_member_counts() as $cohort) {
            $notes[] = get_string('settingremoteaccesscohorts_members', 'local_coursepilot', [
                'name' => format_string($cohort->name),
                'members' => $cohort->members,
            ]);
        }
        foreach (remote_access::missing_cohort_ids() as $cohortid) {
            $notes[] = get_string('settingremoteaccesscohorts_missing', 'local_coursepilot', $cohortid);
        }
        $description = $this->description;
        if ($notes) {
            $this->description .= \html_writer::alist($notes);
        }
        $html = parent::output_html($data, $query);
        $this->description = $description;
        return $html;
    }
}
