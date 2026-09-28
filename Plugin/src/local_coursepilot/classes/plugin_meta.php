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

namespace local_coursepilot;

/**
 * Die eine kanonische Quelle fuer $plugin->version/->release (#577): vorher
 * las sowohl dispatcher::handle() (MCP-Handshake-serverInfo) als auch
 * get_version_info::execute() unabhaengig voneinander dieselbe version.php -
 * zwei Kopien derselben Logik, die im Code-Review als Duplicated Code
 * benannt wurden. Jetzt liest genau eine Stelle die Datei ein, beide Aufrufer
 * fragen hier nach.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class plugin_meta {

    /**
     * $plugin aus version.php der laufenden Dateien - nicht aus
     * config_plugins, damit ein Deploy ohne upgrade.php-Lauf sichtbar bleibt
     * (siehe get_version_info::execute()).
     *
     * @return \stdClass
     */
    public static function current(): \stdClass {
        global $CFG;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/coursepilot/version.php');
        return $plugin;
    }
}
