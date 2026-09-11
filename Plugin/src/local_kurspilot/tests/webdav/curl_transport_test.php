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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * {@see curl_transport} gegen Moodles echte `\curl`-Klasse (Issue #489,
 * Spec #486 §4, ADR 0022): Moodles Hostsperre wird zur Fehlerklasse
 * `gesperrt`, ohne `ignoresecurity`, und eine gewoehnliche Antwort wird
 * unveraendert durchgereicht. Alles jenseits dieser curl-spezifischen
 * Uebersetzung ist bereits ueber den In-Memory-Fake in
 * {@see webdav_client_test} abgedeckt.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
