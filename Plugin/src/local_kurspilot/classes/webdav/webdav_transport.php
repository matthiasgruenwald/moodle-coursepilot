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

namespace local_kurspilot\webdav;

/**
 * Der austauschbare Transport-Seam von {@see webdav_client} (Issue #489,
 * Spec #486 §4/Testing Decisions, ADR 0022). Im Betrieb genau eine
 * Implementierung, {@see curl_transport}, auf Moodles \curl. Im Test der
 * wiederverwendbare In-Memory-Fake
 * `\local_kurspilot\tests\webdav\fake_webdav_transport`.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
