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
 * Der einzige Transport im Betrieb: Moodles \curl (`lib/filelib.php`), nicht
 * `\webdav_client` (ADR 0022, Issue #489). Sechs Verben ueber
 * `CURLOPT_CUSTOMREQUEST`, feste `CURLAUTH_BASIC`-Anmeldung, keine
 * `ignoresecurity`-Umgehung - Moodles Hostsperre gilt unveraendert, weil der
 * `\curl`-Aufrufer sie nur mit einer ausdruecklichen Einstellung abschalten
 * kann, die dieser Client nie setzt.
 *
 * Moodles `\curl::request()` wirft bei einer gesperrten Adresse nicht,
 * sondern liefert die Blockmeldung als Rueckgabewert und laesst
 * `get_info()` leer - das einzige Unterscheidungsmerkmal zu einer
 * Verbindungsstoerung (dort traegt `get_info()` einen `http_code`, nur
 * `get_errno()` ist ungleich null). Beide Faelle werden hier uebersetzt,
 * bevor irgendeine Fehlermeldung diese Klasse verlaesst.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class curl_transport implements webdav_transport {

    /**
     * @param \curl $curl Vorkonfigurierte Moodle-curl-Instanz. Tests koennen
     *        hier einen eigenen `securityhelper` einspeisen (siehe
     *        `\curl`-Konstruktor), ohne `ignoresecurity` zu setzen.
     * @param string $username
     * @param string $password
     */
    public function __construct(
        private readonly \curl $curl,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        $options = [
            'CURLOPT_HTTPAUTH' => CURLAUTH_BASIC,
            'CURLOPT_USERPWD' => $this->username . ':' . $this->password,
            'CURLOPT_HTTPHEADER' => $this->format_headers($headers),
        ];

        if (!in_array($method, ['GET', 'DELETE'], true)) {
            $options['CURLOPT_CUSTOMREQUEST'] = $method;
        }

        $raw = match ($method) {
            'GET' => $this->curl->get($url, [], $options),
            'DELETE' => $this->curl->delete($url, [], $options),
            default => $this->curl->post($url, $body ?? '', $options),
        };

        $info = $this->curl->get_info();
        if (empty($info)) {
            // Moodles Hostsperre hat die Anfrage gar nicht erst gestellt.
            throw new webdav_error(webdav_error::BLOCKED, 'WebDAV-Adresse durch Moodles Hostsperre blockiert.');
        }
        if ($this->curl->get_errno() !== 0) {
            throw new webdav_transport_exception('WebDAV-Verbindung fehlgeschlagen (errno ' . $this->curl->get_errno() . ').');
        }

        return new webdav_response((int) ($info['http_code'] ?? 0), $this->response_headers(), (string) $raw);
    }

    /**
     * @param array<string, string> $headers
     * @return string[] "Name: Wert"-Zeilen fuer CURLOPT_HTTPHEADER.
     */
    private function format_headers(array $headers): array {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }

    /**
     * @return array<string, string> Antwortkoepfe der letzten Anfrage, Schluessel kleingeschrieben.
     */
    private function response_headers(): array {
        $headers = [];
        foreach ($this->curl->getResponse() as $key => $value) {
            $headers[strtolower((string) $key)] = is_array($value) ? (string) end($value) : (string) $value;
        }
        return $headers;
    }
}
