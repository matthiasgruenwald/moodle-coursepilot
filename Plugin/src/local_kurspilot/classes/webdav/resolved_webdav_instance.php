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
 * Eine bereits geprueft-gueltige WebDAV-Nutzerinstanz samt frisch gelesenen
 * Zugangsdaten (Issue #490, Spec #486 §2/§3) - lebt nur fuer die Dauer eines
 * einzelnen Aufrufs, wird nirgendwo gespeichert. Baut Ressourcen-Adressen
 * innerhalb der Instanz und den passenden {@see webdav_client}.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class resolved_webdav_instance {

    /**
     * @param string $baseurl https-Adresse der Instanz inkl. Basispfad, mit abschliessendem "/".
     * @param webdav_transport $transport Im Betrieb {@see curl_transport} mit den frisch
     *        gelesenen Zugangsdaten, im Test der Transport-Fake
     *        ({@see webdav_instance::use_test_transport()}) - dieselbe Austauschbarkeit,
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
