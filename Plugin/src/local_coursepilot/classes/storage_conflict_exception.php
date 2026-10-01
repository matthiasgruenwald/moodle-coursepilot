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
 * Konflikt beim bedingten Schreiben ueber den Ablage-Vertrag (Issue #536,
 * Spec 0021): der beim Aufruf mitgegebene Pruefwert passt nicht (mehr) zum
 * aktuellen Stand der Datei - jemand hat sie zwischendurch geaendert, oder
 * sie ist inzwischen verschwunden. Eine einzige Meldung fuer beide
 * kuenftigen Orte (Spec 0021: "bei Konflikt ... dieselbe klare Meldung",
 * unabhaengig vom Bereich).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class storage_conflict_exception extends \moodle_exception {

    /**
     * @param string $path Client-Pfad der betroffenen Datei, fuer die Meldung.
     */
    public function __construct(string $path) {
        parent::__construct('storageconflict', 'local_coursepilot', '', $path);
    }
}
