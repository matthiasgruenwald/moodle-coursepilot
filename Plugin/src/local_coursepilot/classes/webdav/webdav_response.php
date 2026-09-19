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
 * Eine einzelne WebDAV-Antwort, wie sie {@see webdav_transport::request()}
 * liefert (Issue #489, Spec #486 §4, ADR 0022) - roh, ohne jede Deutung
 * anhand von Statuscode/Rumpf. Diese Deutung (die benannten Fehlerklassen)
 * sitzt in {@see webdav_client::classify()}. Einzige Ausnahme ist `gesperrt`:
 * Moodles Hostsperre erkennt {@see curl_transport} schon vor jeder Antwort
 * und wirft dafuer direkt eine {@see webdav_error}, statt ueberhaupt ein
 * webdav_response zu liefern.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_response {

    /**
     * @param int $statuscode HTTP-Status.
     * @param array<string, string> $headers Antwortkoepfe, Schluessel
     *        kleingeschrieben (z.B. 'etag', 'content-type').
     * @param string $body Rumpf, unveraendert.
     */
    public function __construct(
        public readonly int $statuscode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * Ein Antwortkopf, gross-/kleinschreibungsunabhaengig gelesen.
     *
     * @param string $name
     * @return string|null
     */
    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }
}
