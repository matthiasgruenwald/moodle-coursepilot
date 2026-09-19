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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * {@see curl_transport} gegen Moodles echte `\curl`-Klasse (Issue #489,
 * Spec #486 §4, ADR 0022): Moodles Hostsperre wird zur Fehlerklasse
 * `gesperrt`, ohne `ignoresecurity`, und eine gewoehnliche Antwort wird
 * unveraendert durchgereicht. Alles jenseits dieser curl-spezifischen
 * Uebersetzung ist bereits ueber den In-Memory-Fake in
 * {@see webdav_client_test} abgedeckt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(curl_transport::class)]
final class curl_transport_test extends \advanced_testcase {

    /**
     * Ein Security-Helper, der jede Adresse sperrt - simuliert Moodles
     * Hostsperre, ohne von echten Netz- oder Admin-Einstellungen abzuhaengen.
     * Erbt bewusst von der konkreten `curl_security_helper`, nicht nur der
     * Basisklasse: `\curl::set_security()` akzeptiert nur diese (filelib.php).
     *
     * @return \core\files\curl_security_helper
     */
    private function always_blocking_helper(): \core\files\curl_security_helper {
        return new class extends \core\files\curl_security_helper {
            public function url_is_blocked($urlstring, $notused = null) {
                return true;
            }
            public function get_blocked_url_string() {
                return 'Von Moodles Hostsperre blockiert.';
            }
        };
    }

    public function test_blocked_host_becomes_gesperrt_without_leaking_the_password(): void {
        $this->resetAfterTest();
        $secret = 'g3h31m-' . uniqid();
        $curl = new \curl(['securityhelper' => $this->always_blocking_helper()]);
        $transport = new curl_transport($curl, 'lehrkraft', $secret);

        try {
            $transport->request('PROPFIND', 'https://gesperrt.example/dav/', ['Depth' => '1']);
            $this->fail('BLOCKED erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::BLOCKED, $e->errorclass);
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
        // Moodle protokolliert eine blockierte Adresse selbst schon als debugging() (core\event\url_blocked).
        $this->assertDebuggingCalled();
    }

    public function test_blocking_is_never_bypassed_with_ignoresecurity(): void {
        $this->resetAfterTest();
        // Kein ignoresecurity in den Einstellungen dieses \curl - die reale
        // Hostsperre greift unveraendert (ADR 0022).
        $curl = new \curl(['securityhelper' => $this->always_blocking_helper()]);
        $transport = new curl_transport($curl, 'lehrkraft', 'pw');

        try {
            $transport->request('GET', 'https://gesperrt.example/dav/x.md');
            $this->fail('BLOCKED erwartet.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::BLOCKED, $e->errorclass);
        }
        $this->assertDebuggingCalled();
    }

    /**
     * Sicherheitsbefund HIGH (Issue #510): Moodles `\curl` schaltet die
     * Zertifikatspruefung standardmaessig ab und folgt Weiterleitungen - ohne
     * Gegeneinstellung koennte das Basic-Passwort ueber eine unverschluesselte
     * oder fremde Adresse mitgelesen werden. Da `\curl::mock_response()` den
     * echten Optionsaufbau umgeht (Moodle liefert die gemockte Antwort vor
     * `apply_opt()`), belegt dieser Test die von {@see curl_transport}
     * gebauten Optionen direkt per Reflection auf die private Methode.
     */
    public function test_transport_options_verify_certificate_forbid_redirects_and_limit_time_and_size(): void {
        $curl = new \curl();
        $transport = new curl_transport($curl, 'lehrkraft', 'pw');

        $method = new \ReflectionMethod(curl_transport::class, 'transport_options');
        $options = $method->invoke($transport, []);

        $this->assertTrue($options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
        $this->assertSame(0, $options['CURLOPT_FOLLOWLOCATION']);
        $this->assertGreaterThan(0, $options['CURLOPT_TIMEOUT']);
        $this->assertGreaterThan(0, $options['CURLOPT_MAXFILESIZE']);
        // Fortschritts-Abbruch als Ergaenzung zu CURLOPT_MAXFILESIZE (das nur
        // bei vorab bekannter Content-Length greift) - begrenzt auch einen
        // Server ohne Content-Length (Issue #510).
        $this->assertFalse($options['CURLOPT_NOPROGRESS']);
        $this->assertIsCallable($options['CURLOPT_XFERINFOFUNCTION']);
    }

    public function test_successful_response_is_wrapped_unmodified(): void {
        $this->resetAfterTest();
        \curl::mock_response('Hallo Welt');
        $curl = new \curl();
        $transport = new curl_transport($curl, 'lehrkraft', 'pw');

        $response = $transport->request('GET', 'https://example.invalid/dav/x.md');

        $this->assertSame(200, $response->statuscode);
        $this->assertSame('Hallo Welt', $response->body);
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function verbs(): array {
        return [
            'PROPFIND' => ['PROPFIND', '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"/>'],
            'PUT' => ['PUT', 'Inhalt'],
            'MKCOL' => ['MKCOL', null],
            'MOVE' => ['MOVE', null],
            'DELETE' => ['DELETE', null],
        ];
    }

    /**
     * Alle sechs Verben (GET schon oben) muessen ueber `\curl` denselben Weg
     * nehmen, ohne eine PHP-Ausnahme durch eine falsche Methodensignatur -
     * `CURLOPT_CUSTOMREQUEST` per `post()`/`get()`/`delete()` (filelib.php
     * 4107-4256).
     *
     * @dataProvider verbs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('verbs')]
    public function test_every_verb_reaches_curl_without_error(string $method, ?string $body): void {
        $this->resetAfterTest();
        \curl::mock_response('Hallo Welt');
        $curl = new \curl();
        $transport = new curl_transport($curl, 'lehrkraft', 'pw');

        $response = $transport->request($method, 'https://example.invalid/dav/x.md', ['Depth' => '1'], $body);

        $this->assertSame(200, $response->statuscode);
    }
}
