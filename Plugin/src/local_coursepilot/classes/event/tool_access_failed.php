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
 * Ein Zugriff ueber den Coursepilot-MCP-Endpunkt ist fehlgeschlagen (#339):
 * Authentifizierung, Berechtigung, unbekanntes Werkzeug/Verfahren oder ein
 * Fehler waehrend der Werkzeugausfuehrung.
 *
 * Der Grund landet als kurzer, fester Code/Text im Ereignis - nie das
 * Zugriffstoken selbst (das steht an keiner Stelle des Aufrufpfads in einer
 * Fehlermeldung, siehe dispatcher::error()/handle_tools_call()). Wird bereits
 * ab Protokollstufe "Nur Fehler" ausgeloest.
 *
 * @property-read array $other {
 *      - string reason: kurze Fehlerbeschreibung (kein Geheimnis).
 *      - string|null toolname: Name des betroffenen Werkzeugs, falls bekannt.
 *      - string|null path: Dateipfad, wenn der gescheiterte Zugriff einen
 *        berührt hat und er noch bekannt war (#501), sonst null.
 *      - string|null detail: Interner Diagnosehinweis, nur bei Protokollstufe
 *        "Alles" gesetzt (#457).
 * }
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class tool_access_failed extends \core\event\base {

    /**
     * @return string
     */
    public function get_description() {
        $tool = $this->other['toolname'] ?? null;
        $suffix = $tool !== null ? " (Werkzeug: {$tool})" : '';
        return "Ein Coursepilot-Zugriff ist fehlgeschlagen: {$this->other['reason']}{$suffix}.";
    }

    /**
     * @return string
     */
    public static function get_name() {
        return get_string('event_tool_access_failed', 'local_coursepilot');
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
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The \'reason\' value must be set in other.');
        }
    }

    /**
     * @return false
     */
    public static function get_other_mapping() {
        return false;
    }
}
