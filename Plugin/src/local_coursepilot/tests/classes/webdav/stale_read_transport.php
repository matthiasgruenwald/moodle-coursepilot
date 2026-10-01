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

namespace local_coursepilot\tests\webdav;

use local_coursepilot\webdav\webdav_response;
use local_coursepilot\webdav\webdav_transport;

/**
 * Test-Decorator um {@see fake_webdav_transport} (Issue #491): erzeugt einen
 * echten Konflikt, den ein einzelner, synchroner Testaufruf sonst nie
 * hervorruft - `pointer_writer` liest die aktuellen Eigenschaften einer Datei
 * unmittelbar vor dem bedingten PUT, das Fenster fuer eine echte
 * Gleichzeitigkeit ist also mit dem Fake allein nicht nachstellbar.
 *
 * Dieser Decorator tut genau das: nach der ersten PROPFIND-Antwort auf die
 * beobachtete Adresse - der Stand, den `pointer_writer` fuer sein bedingtes
 * Schreiben zwischenspeichert - aendert er die Datei im Fake heimlich ein
 * zweites Mal ("Handaenderung"), sodass das anschliessende `PUT` mit dem
 * inzwischen veralteten `If-Match`/`getlastmodified`-Stand serverseitig auf
 * 412 laeuft.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class stale_read_transport implements webdav_transport {

    private bool $triggered = false;

    /**
     * @param fake_webdav_transport $fake Der zugrunde liegende Speicher - auch
     *        das Ziel der heimlichen Zweitaenderung.
     * @param string $urlsubstring Adressbestandteil der beobachteten Datei.
     * @param fake_webdav_transport $inner In Produktivcode waere das ein
     *        anderer Transport als `$fake`; im Test ist es bewusst
     *        dieselbe Instanz - der Decorator veraendert ihren Speicher direkt.
     */
    public function __construct(
        private readonly fake_webdav_transport $fake,
        private readonly string $urlsubstring,
        private readonly fake_webdav_transport $inner,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        $response = $this->inner->request($method, $url, $headers, $body);
        if (!$this->triggered && $method === 'PROPFIND' && str_contains($url, $this->urlsubstring)) {
            $this->triggered = true;
            $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            $this->fake->seed_file($path, 'handaenderung');
        }
        return $response;
    }
}
