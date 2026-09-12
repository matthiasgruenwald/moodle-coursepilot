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
 * Moodles `\curl` schaltet die Zertifikatspruefung standardmaessig ab
 * (`CURLOPT_SSL_VERIFYPEER = 0`, `filelib.php::resetopt()`) und folgt
 * Weiterleitungen (`CURLOPT_FOLLOWLOCATION = 1`, bis zu zehn Ebenen tief,
 * PHP-seitig emuliert). Ohne ausdrueckliche Gegeneinstellung koennte das
 * Basic-Anmeldekopf ueber eine unverschluesselte oder fremde Adresse
 * mitgelesen werden (Sicherheitsbefund HIGH, Issue #510). {@see
 * transport_options()} setzt deshalb bei jeder Anfrage Zertifikatspruefung,
 * Weiterleitungssperre, Gesamt-Zeitgrenze und Groessengrenze der Antwort -
 * eine 3xx-Antwort deutet {@see webdav_client::classify()} als benannten
 * Fehler `REDIRECTED`, eine ueberschrittene Zeit-/Groessengrenze meldet
 * `\curl` als von null verschiedenen `get_errno()` und wird hier zur
 * Fehlerklasse `UNREACHABLE`.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class curl_transport implements webdav_transport {

    /** @var int Gesamt-Zeitgrenze einer Anfrage in Sekunden (CURLOPT_TIMEOUT). */
    private const TOTAL_TIMEOUT_SECONDS = 30;

    /** @var int Groessengrenze einer Antwort in Byte (CURLOPT_MAXFILESIZE), 50 MB. */
    private const MAX_RESPONSE_BYTES = 50 * 1024 * 1024;

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
        $options = $this->transport_options($headers);

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
     * Die curl-Optionen jeder Anfrage - Anmeldung sowie die vier Schutzmassnahmen
     * aus dem Sicherheitsbefund (Issue #510): Zertifikatspruefung ausdruecklich
     * eingeschaltet (Moodles `\curl` schaltet sie sonst standardmaessig ab),
     * keine Weiterleitung, Gesamt-Zeitgrenze, Groessengrenze der Antwort.
     * Als eigene Methode, damit ein Transport-Test die gesetzten Optionen ohne
     * echten Netzzugriff belegen kann.
     *
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function transport_options(array $headers): array {
        return [
            'CURLOPT_HTTPAUTH' => CURLAUTH_BASIC,
            'CURLOPT_USERPWD' => $this->username . ':' . $this->password,
            'CURLOPT_HTTPHEADER' => $this->format_headers($headers),
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            // Keine Weiterleitung: eine 3xx-Antwort wird von webdav_client als
            // benannter Fehler REDIRECTED gedeutet, nie automatisch verfolgt -
            // sonst koennten Anmeldedaten an eine vom Server bestimmte,
            // moeglicherweise unverschluesselte Adresse gelangen.
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_TIMEOUT' => self::TOTAL_TIMEOUT_SECONDS,
            // CURLOPT_MAXFILESIZE allein greift nur, wenn der Server die
            // Groesse vorab per Content-Length nennt - ein Server ohne
            // Content-Length (z.B. chunked) koennte sonst unbegrenzt streamen.
            // Der Fortschritts-Abbruch unten begrenzt deshalb zusaetzlich den
            // tatsaechlich uebertragenen Bytestrom (Issue #510).
            'CURLOPT_MAXFILESIZE' => self::MAX_RESPONSE_BYTES,
            'CURLOPT_NOPROGRESS' => false,
            'CURLOPT_XFERINFOFUNCTION' => \Closure::fromCallable([$this, 'abort_when_oversized']),
        ];
    }

    /**
     * curl-Fortschritts-Rueckruf: bricht die Uebertragung ab, sobald mehr als
     * {@see MAX_RESPONSE_BYTES} hoch- oder heruntergeladen wurden - schuetzt
     * auch ohne vorab bekannte Content-Length (Issue #510). Ein Abbruch
     * fuehrt zu einem von null verschiedenen `get_errno()` und wird von
     * {@see request()} zur Fehlerklasse `UNREACHABLE`.
     *
     * @param resource|\CurlHandle $resource
     * @param int $downloadsize
     * @param int $downloaded
     * @param int $uploadsize
     * @param int $uploaded
     * @return int 0 weitermachen, ungleich 0 abbrechen.
     */
    private function abort_when_oversized($resource, int $downloadsize, int $downloaded, int $uploadsize, int $uploaded): int {
        return ($downloaded > self::MAX_RESPONSE_BYTES || $uploaded > self::MAX_RESPONSE_BYTES) ? 1 : 0;
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
