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
 * Eine bereits geprueft-gueltige WebDAV-Nutzerinstanz samt frisch gelesenen
 * Zugangsdaten (Issue #490, Spec #486 §2/§3) - lebt nur fuer die Dauer eines
 * einzelnen Aufrufs, wird nirgendwo gespeichert. Baut Ressourcen-Adressen
 * innerhalb der Instanz und den passenden {@see webdav_client}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class resolved_webdav_instance {

    /**
     * @param string $baseurl https-Adresse der Instanz inkl. Basispfad, mit abschliessendem "/".
     * @param webdav_transport $transport Im Betrieb {@see curl_transport} mit den frisch
     *        gelesenen Zugangsdaten, im Test der Transport-Fake
     *        ({@see webdav_instance::set_transport()}) - dieselbe Austauschbarkeit,
     *        die {@see webdav_client} selbst schon kennt (Spec #486 Testing Decisions).
     */
    public function __construct(
        private readonly string $baseurl,
        private readonly webdav_transport $transport,
    ) {
    }

    /**
     * @return webdav_client Neuer Client auf dem uebergebenen Transport.
     */
    public function client(): webdav_client {
        return new webdav_client($this->transport);
    }

    /**
     * @param string $relativepath Bereits segmentweise geprueft, ohne fuehrenden/abschliessenden Schraegstrich.
     * @return string Adresse eines Ordners, mit abschliessendem "/".
     */
    public function directory_url(string $relativepath): string {
        return $this->url($relativepath) . '/';
    }

    /**
     * @param string $relativepath Bereits segmentweise geprueft, ohne fuehrenden/abschliessenden Schraegstrich.
     * @return string Adresse einer Datei, ohne abschliessenden Schraegstrich.
     */
    public function file_url(string $relativepath): string {
        return $this->url($relativepath);
    }

    /**
     * @param string $relativepath
     * @return string
     */
    private function url(string $relativepath): string {
        $trimmed = trim($relativepath, '/');
        $base = rtrim($this->baseurl, '/');
        if ($trimmed === '') {
            return $base;
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $trimmed)));
        return $base . '/' . $encoded;
    }
}
