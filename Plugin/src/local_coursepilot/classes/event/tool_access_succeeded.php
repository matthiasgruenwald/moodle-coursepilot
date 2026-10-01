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

namespace local_coursepilot\event;

/**
 * Ein Coursepilot-Werkzeugaufruf ueber den MCP-Endpunkt ist erfolgreich
 * durchgelaufen (#339).
 *
 * Ueber die Moodle-Ereignis-API ausgeloest, damit der Zugriff nativ in den
 * Protokollberichten der Administration erscheint - kein zweites Werkzeug
 * noetig. Wird nur ausgeloest, wenn die Protokollstufe
 * ({@see \local_coursepilot\access_log}) mindestens "Lesezugriffe und Fehler"
 * ist.
 *
 * @property-read array $other {
 *      - string toolname: Name des aufgerufenen MCP-Werkzeugs.
 *      - string|null path: Dateipfad, wenn das Werkzeug einen Kontext- oder
 *        Materialordner-Pfad berührt hat (Spec 0018 §9.2), sonst null.
 * }
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class tool_access_succeeded extends \core\event\base {

    /**
     * @return string
     */
    public function get_description() {
        return "Das Coursepilot-Werkzeug '{$this->other['toolname']}' wurde von Nutzer/in mit ID {$this->userid} erfolgreich aufgerufen.";
    }

    /**
     * @return string
     */
    public static function get_name() {
        return get_string('event_tool_access_succeeded', 'local_coursepilot');
    }

    /**
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->context = \context_system::instance();
    }

    /**
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['toolname'])) {
            throw new \coding_exception('The \'toolname\' value must be set in other.');
        }
    }

    /**
     * @return false
     */
    public static function get_other_mapping() {
        return false;
    }
}
