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

namespace local_coursepilot\webdav;

/**
 * Verbindungsfehler eines {@see webdav_transport} (Zeitueberschreitung,
 * DNS-Fehler) - unterschieden von einer gedeuteten HTTP-Antwort, weil hier
 * gar keine Antwort zustande kam. {@see webdav_client} faengt sie und macht
 * daraus die Fehlerklasse `nicht erreichbar` (Issue #489, Spec #486 §4).
 *
 * Traegt bewusst keine Server- oder Zugangsdaten in der Meldung (Spec §3:
 * "Das Geheimnis verlaesst keinen neuen Weg").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_transport_exception extends \RuntimeException {
}
