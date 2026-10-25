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

use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\webdav\recording_curl;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact curl calls, options and response translation of the WebDAV curl transport (Issue #489, #510).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(curl_transport::class)]
final class curl_transport_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Curl double for the transport.
     *
     * @return recording_curl
     */
    private function curl(): recording_curl {
        return new recording_curl();
    }

    public function test_get_and_delete_use_their_own_curl_calls_and_everything_else_posts_a_custom_request(): void {
        $curl = $this->curl();
        $transport = new curl_transport($curl, 'user', 'pw');

        $transport->request('GET', 'https://h.example/a');
        $transport->request('DELETE', 'https://h.example/b');
        $transport->request('PROPFIND', 'https://h.example/c', [], '<xml/>');
        $transport->request('MKCOL', 'https://h.example/d');

        $this->assertSame(['GET', 'DELETE', 'POST', 'POST'], array_column($curl->calls, 0));
        $this->assertArrayNotHasKey('CURLOPT_CUSTOMREQUEST', $curl->calls[0][3]);
        $this->assertArrayNotHasKey('CURLOPT_CUSTOMREQUEST', $curl->calls[1][3]);
        $this->assertSame('PROPFIND', $curl->calls[2][3]['CURLOPT_CUSTOMREQUEST']);
        $this->assertSame('<xml/>', $curl->calls[2][2]);
        $this->assertSame('MKCOL', $curl->calls[3][3]['CURLOPT_CUSTOMREQUEST']);
        $this->assertSame('', $curl->calls[3][2]);
        $this->assertSame([], $curl->calls[0][2]);
        $this->assertSame([], $curl->calls[1][2]);
    }

    public function test_every_request_carries_the_protective_options_and_formatted_headers(): void {
        $curl = $this->curl();
        $transport = new curl_transport($curl, 'user', 'pw');

        $transport->request('GET', 'https://h.example/a', ['Depth' => '1', 'X-Two' => 'two']);

        $options = $curl->calls[0][3];
        $callback = $options['CURLOPT_XFERINFOFUNCTION'];
        unset($options['CURLOPT_XFERINFOFUNCTION']);
        $this->assertSame([
            'CURLOPT_HTTPAUTH' => CURLAUTH_BASIC,
            'CURLOPT_USERPWD' => 'user:pw',
            'CURLOPT_HTTPHEADER' => ['Depth: 1', 'X-Two: two'],
            'CURLOPT_SSL_VERIFYPEER' => true,
            'CURLOPT_SSL_VERIFYHOST' => 2,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_MAXFILESIZE' => 50 * 1024 * 1024,
            'CURLOPT_NOPROGRESS' => false,
        ], $options);
        $limit = 50 * 1024 * 1024;
        $this->assertSame(0, $callback(null, 0, $limit, 0, $limit));
        $this->assertSame(1, $callback(null, 0, $limit + 1, 0, 0));
        $this->assertSame(1, $callback(null, 0, 0, 0, $limit + 1));
    }

    public function test_the_response_carries_status_headers_and_body_as_exact_types(): void {
        $curl = $this->curl();
        $curl->fakeinfo = ['http_code' => '207'];
        $curl->fakeraw = 'payload';
        $curl->fakeheaders = ['Content-Type' => 'text/xml', 'X-List' => ['one', 'two'], 'X-Number' => 5];
        $transport = new curl_transport($curl, 'u', 'p');

        $response = $transport->request('GET', 'https://h.example/a');

        $this->assertSame(207, $response->statuscode);
        $this->assertSame('payload', $response->body);
        $this->assertSame(['content-type' => 'text/xml', 'x-list' => 'two', 'x-number' => '5'], $response->headers);
    }

    public function test_a_missing_status_is_zero_and_a_failed_body_is_empty(): void {
        $curl = $this->curl();
        $curl->fakeinfo = ['total_time' => 1.0];
        $curl->fakeraw = false;
        $transport = new curl_transport($curl, 'u', 'p');

        $response = $transport->request('GET', 'https://h.example/a');

        $this->assertSame(0, $response->statuscode);
        $this->assertSame('', $response->body);
    }

    public function test_a_curl_error_is_a_transport_exception_with_its_number(): void {
        $curl = $this->curl();
        $curl->fakeerrno = 28;
        $transport = new curl_transport($curl, 'u', 'p');

        $e = $this->caught(fn() => $transport->request('GET', 'https://h.example/a'));

        $this->assertInstanceOf(webdav_transport_exception::class, $e);
        $this->assertSame('WebDAV connection failed (errno 28).', $e->getMessage());
    }

    public function test_a_blocked_host_leaves_no_transfer_info_and_is_the_blocked_error(): void {
        $curl = $this->curl();
        $curl->fakeinfo = [];
        $transport = new curl_transport($curl, 'u', 'p');

        $e = $this->caught(fn() => $transport->request('GET', 'https://h.example/a'));

        $this->assertInstanceOf(webdav_error::class, $e);
        $this->assertSame(webdav_error::BLOCKED, $e->errorclass);
    }
}
