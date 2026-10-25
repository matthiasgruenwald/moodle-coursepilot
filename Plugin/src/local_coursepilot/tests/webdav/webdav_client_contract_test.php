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
use local_coursepilot\tests\webdav\recording_transport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact requests, retry budget, error classes and multistatus rules of the WebDAV client (Spec #486 §4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_client::class)]
final class webdav_client_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Transport that records requests and answers from a queue.
     *
     * @param webdav_response[] $responses Answers in order; the last one repeats.
     * @return recording_transport
     */
    private function transport(array $responses): recording_transport {
        return new recording_transport($responses);
    }

    /**
     * Builds a response.
     *
     * @param int $status HTTP status.
     * @param string $body Response body.
     * @param string[] $headers Lower-case names.
     * @return webdav_response
     */
    private function response(int $status, string $body = '', array $headers = []): webdav_response {
        return new webdav_response($status, $headers, $body);
    }

    /**
     * Multistatus with the given response fragments.
     *
     * @param string $responses
     * @return string
     */
    private function multistatus(string $responses): string {
        return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">' . $responses . '</d:multistatus>';
    }

    public function test_propfind_defaults_to_depth_one_and_sends_its_headers(): void {
        $stub = $this->transport([$this->response(207, $this->multistatus(''))]);
        $client = new webdav_client($stub);

        $client->propfind('https://h.example/dav/');
        $client->propfind('https://h.example/dav/', 0);

        $this->assertSame(['Depth' => '1', 'Content-Type' => 'application/xml'], $stub->requests[0][2]);
        $this->assertSame(['Depth' => '0', 'Content-Type' => 'application/xml'], $stub->requests[1][2]);
        $e = $this->caught(fn() => $client->propfind('https://h.example/dav/', 2));
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    public function test_mkcol_stays_public_and_a_chain_joins_the_base_with_exactly_one_slash(): void {
        $stub = $this->transport([$this->response(201)]);
        $client = new webdav_client($stub);
        $mkcol = new \ReflectionMethod($client, 'mkcol');
        $mkcol->invoke($client, 'https://h.example/dav/x/');
        $this->assertTrue($mkcol->isPublic());
        $stub->requests = [];

        $client->mkcol_chain('https://h.example/dav//', ['a b', 'c']);

        $this->assertSame(
            ['https://h.example/dav/a%20b/', 'https://h.example/dav/a%20b/c/'],
            array_column($stub->requests, 1)
        );
    }

    public function test_the_retry_gives_up_exactly_when_the_budget_is_used_up(): void {
        $stub = $this->transport([$this->response(503)]);
        $times = [0.0, 10.0, 10.2];
        $clock = static function () use (&$times): float {
            return array_shift($times);
        };
        $slept = [];
        $client = new webdav_client($stub, $clock, static function (float $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $e = $this->caught(fn() => $client->get('https://h.example/dav/f'));

        $this->assertInstanceOf(webdav_error::class, $e);
        $this->assertSame(webdav_error::UNCLEAR, $e->errorclass);
        $this->assertSame('Retry given up after 10s.', $e->getMessage());
        $this->assertCount(1, $stub->requests);
        $this->assertSame([], $slept);
    }

    public function test_a_retry_below_the_budget_waits_the_documented_delay_and_tries_again(): void {
        $stub = $this->transport([$this->response(503), $this->response(200, 'ok')]);
        $times = [0.0, 9.9];
        $clock = static function () use (&$times): float {
            return array_shift($times);
        };
        $slept = [];
        $client = new webdav_client($stub, $clock, static function (float $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });

        $this->assertSame('ok', $client->get('https://h.example/dav/f'));
        $this->assertSame([0.2], $slept);
        $this->assertCount(2, $stub->requests);
    }

    /**
     * Provides statuses and the error class they get.
     *
     * @return array<string, array{int, string}>
     */
    public static function redirect_statuses(): array {
        return [
            'below the redirect range' => [299, webdav_error::UNCLEAR],
            'first redirect status' => [300, webdav_error::REDIRECTED],
            'last redirect status' => [399, webdav_error::REDIRECTED],
            'first client error' => [418, webdav_error::UNCLEAR],
        ];
    }

    /**
     * Statuses map to redirect or retryable.
     *
     * @param int $status HTTP status.
     * @param string $expected Error class.
     */
    #[DataProvider('redirect_statuses')]
    public function test_only_three_hundred_to_three_ninety_nine_is_a_redirect(int $status, string $expected): void {
        $client = new webdav_client($this->transport([$this->response(200)]));
        $classify = new \ReflectionMethod($client, 'classify');

        $this->assertSame($expected, $classify->invoke($client, $this->response($status), [200]));
    }

    /**
     * Provides 404 answers and whether they are a real DAV answer.
     *
     * @return array<string, array{string, array<string, string>, bool}>
     */
    public static function not_found_bodies(): array {
        return [
            'xml content type' => ['', ['content-type' => 'application/xml; charset=utf-8'], true],
            'xml declaration without content type' => ['<?xml version="1.0"?><error/>', [], true],
            'xml declaration after whitespace' => ["\n  <?xml version=\"1.0\"?><error/>", [], true],
            'dav namespace in a body without declaration' => ['<d:error xmlns:d="DAV:"/>', [], true],
            'html guest page' => ['<html><body>Not here</body></html>', ['content-type' => 'text/html'], false],
            'empty body' => ['', [], false],
        ];
    }

    /**
     * A 404 is a real DAV answer only when it says so.
     *
     * @param string $body Response body.
     * @param array<string, string> $headers Response headers.
     * @param bool $isdav Whether it is a DAV answer.
     */
    #[DataProvider('not_found_bodies')]
    public function test_a_404_is_not_found_only_for_a_dav_answer(string $body, array $headers, bool $isdav): void {
        $client = new webdav_client($this->transport([$this->response(200)]));
        $classify = new \ReflectionMethod($client, 'classify');

        $this->assertSame(
            $isdav ? webdav_error::NOT_FOUND : webdav_error::UNCLEAR,
            $classify->invoke($client, $this->response(404, $body, $headers), [200])
        );
    }

    public function test_the_xml_error_mode_is_restored_after_listing(): void {
        $stub = $this->transport([$this->response(207, $this->multistatus(''))]);
        $client = new webdav_client($stub);

        foreach ([false, true] as $initial) {
            libxml_use_internal_errors($initial);
            $client->propfind('https://h.example/dav/');
            $this->assertSame($initial, libxml_use_internal_errors($initial));
        }
        libxml_use_internal_errors(false);
    }

    public function test_only_properties_with_an_empty_or_ok_status_are_listed(): void {
        $entry = static fn(string $name, string $status): string => '<d:response><d:href>/dav/' . $name . '</d:href><d:propstat>'
            . '<d:prop><d:getcontentlength>3</d:getcontentlength></d:prop>' . $status . '</d:propstat></d:response>';
        $body = $this->multistatus(
            '<d:response><d:href>/dav/</d:href><d:propstat><d:prop/></d:propstat></d:response>'
            . $entry('nostatus.txt', '')
            . $entry('ok.txt', '<d:status>HTTP/1.1 200 OK</d:status>')
            . $entry('missing.txt', '<d:status>HTTP/1.1 404 Not Found</d:status>')
        );
        $client = new webdav_client($this->transport([$this->response(207, $body)]));

        $names = array_column($client->propfind('https://h.example/dav/'), 'name');

        $this->assertSame(['nostatus.txt', 'ok.txt'], $names);
    }

    public function test_unparseable_listing_text_is_an_unclear_error_not_an_empty_folder(): void {
        $client = new webdav_client($this->transport([$this->response(207, 'not xml at all')]));

        $e = $this->caught(fn() => $client->propfind('https://h.example/dav/'));

        $this->assertInstanceOf(webdav_error::class, $e);
        $this->assertSame(webdav_error::UNCLEAR, $e->errorclass);
        $this->assertSame('PROPFIND body not readable as XML despite success status.', $e->getMessage());
    }
}
