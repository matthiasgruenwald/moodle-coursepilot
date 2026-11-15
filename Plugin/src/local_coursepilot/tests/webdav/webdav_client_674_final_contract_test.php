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
use local_coursepilot\tests\usleep_spy;
use local_coursepilot\tests\webdav\recording_transport;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/webdav_usleep_spy.php');

/**
 * Injected clock, default sleeper, fail-fast errors and mimetype sniffing of the WebDAV client (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_client::class)]
final class webdav_client_674_final_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Builds a response.
     *
     * @param int $status HTTP status.
     * @param string $body Response body.
     * @return webdav_response
     */
    private function response(int $status, string $body = ''): webdav_response {
        return new webdav_response($status, [], $body);
    }

    /**
     * Sleeper that fails the test instead of letting a runaway retry loop run.
     *
     * @param int $limit Number of waits that are tolerated.
     * @param float[] $slept Collects the waits.
     * @return callable
     */
    private function limited_sleeper(int $limit, array &$slept): callable {
        return function (float $seconds) use ($limit, &$slept): void {
            $slept[] = $seconds;
            if (count($slept) > $limit) {
                throw new \RuntimeException('Retry loop did not stop');
            }
        };
    }

    /**
     * The retry budget is measured with the injected clock, not with real time.
     */
    public function test_the_injected_clock_decides_when_the_retry_gives_up(): void {
        $stub = new recording_transport([$this->response(503)]);
        $times = [0.0, 0.1, 0.2, 10.5];
        $clock = static function () use (&$times): float {
            return array_shift($times);
        };
        $slept = [];
        $client = new webdav_client($stub, $clock, $this->limited_sleeper(3, $slept));

        $e = $this->caught(fn() => $client->get('https://h.example/dav/f'));

        $this->assertInstanceOf(webdav_error::class, $e);
        $this->assertSame(webdav_error::UNCLEAR, $e->errorclass);
        $this->assertSame([0.2, 0.2], $slept);
        $this->assertCount(3, $stub->requests);
    }

    /**
     * A named error class ends the request at once, without waiting or retrying.
     */
    public function test_a_named_error_is_thrown_without_retry(): void {
        $stub = new recording_transport([$this->response(401)]);
        $slept = [];
        $client = new webdav_client($stub, static fn(): float => 0.0, $this->limited_sleeper(0, $slept));

        $e = $this->caught(fn() => $client->get('https://h.example/dav/f'));

        $this->assertInstanceOf(webdav_error::class, $e);
        $this->assertSame(webdav_error::AUTH_REJECTED, $e->errorclass);
        $this->assertSame('HTTP 401', $e->getMessage());
        $this->assertSame([], $slept);
        $this->assertCount(1, $stub->requests);
    }

    /**
     * Without an injected sleeper the retry waits the delay in microseconds through usleep().
     */
    public function test_the_default_sleeper_waits_the_delay_in_microseconds(): void {
        $this->resetAfterTest();
        usleep_spy::reset();
        usleep_spy::$mute = true;
        $stub = new recording_transport([$this->response(503), $this->response(200, 'ok')]);
        $client = new webdav_client($stub);

        try {
            $this->assertSame('ok', $client->get('https://h.example/dav/f'));
            $property = new \ReflectionProperty(webdav_client::class, 'sleeper');
            $property->getValue($client)(1.0);
            $property->getValue($client)(0.5);
            $calls = usleep_spy::$calls;
        } finally {
            usleep_spy::reset();
        }

        $this->assertSame([200000, 1000000, 500000], $calls);
    }

    /**
     * Content sniffing answers the Moodle mimetype of a real file content.
     */
    public function test_sniffing_maps_a_png_content_to_its_moodle_mimetype(): void {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->assertSame('image/png', webdav_client::sniff_mimetype_from_content($png));
        $this->assertNull(webdav_client::sniff_mimetype_from_content(''));
    }
}
