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
 * Der austauschbare Transport-Seam von {@see webdav_client} (Issue #489,
 * Spec #486 §4/Testing Decisions, ADR 0022). Im Betrieb genau eine
 * Implementierung, {@see curl_transport}, auf Moodles \curl. Im Test der
 * wiederverwendbare In-Memory-Fake
 * `\local_coursepilot\tests\webdav\fake_webdav_transport`.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
interface webdav_transport {

    /**
     * Eine einzelne HTTP-Anfrage. Der Transport deutet den Status nicht -
     * das ist Sache von {@see webdav_client}.
     *
     * @param string $method PROPFIND|GET|PUT|MKCOL|MOVE|DELETE.
     * @param string $url Vollstaendige https-Adresse.
     * @param array<string, string> $headers Zusaetzliche Anfragekoepfe
     *        (z.B. Depth, If-Match, If-None-Match, Destination), ohne
     *        Anmeldekopf - den setzt der Transport selbst.
     * @param string|null $body Rumpf, z.B. PROPFIND-XML oder Dateiinhalt.
     * @return webdav_response
     * @throws webdav_transport_exception bei Verbindungsfehlern
     *         (Zeitueberschreitung, DNS) - siehe {@see webdav_client}, das
     *         daraus die Fehlerklasse `nicht erreichbar` macht.
     * @throws webdav_error direkt, wenn der Transport selbst schon eine
     *         benannte Fehlerklasse kennt (z.B. `gesperrt` durch Moodles
     *         Hostsperre in {@see curl_transport}).
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response;
}
