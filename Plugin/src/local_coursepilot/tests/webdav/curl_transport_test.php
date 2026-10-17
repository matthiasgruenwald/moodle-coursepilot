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
 * {@see curl_transport} against Moodle's real `\curl` class (Issue #489,
 * Spec #486 §4, ADR 0022): Moodle's host block becomes the error class
 * `BLOCKED`, without `ignoresecurity`, and an ordinary response is passed
 * through unchanged. Everything beyond this curl-specific
 * translation is already covered by the in-memory fake in
 * {@see webdav_client_test}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(curl_transport::class)]
final class curl_transport_test extends \advanced_testcase {
    /**
     * A security helper that blocks every address - simulates Moodle's
     * host block without depending on real network or admin settings.
     * Deliberately extends the concrete `curl_security_helper`, not just the
     * base class: `\curl::set_security()` only accepts that one (filelib.php).
     *
     * @return \core\files\curl_security_helper
     */
    private function always_blocking_helper(): \core\files\curl_security_helper {
        return new class extends \core\files\curl_security_helper {
            /**
             * Provides url is blocked.
             *
             * @param mixed $urlstring The urlstring.
             * @param mixed $notused The notused.
             */
            public function url_is_blocked($urlstring, $notused = null) {
                return true;
            }
            /**
             * Returns blocked url string.
             */
            public function get_blocked_url_string() {
                return 'Blocked by Moodle host block.';
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
            $this->fail('BLOCKED expected.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::BLOCKED, $e->errorclass);
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
        // Moodle itself already logs a blocked address as debugging() (core\event\url_blocked).
        $this->assertDebuggingCalled();
    }

    public function test_blocking_is_never_bypassed_with_ignoresecurity(): void {
        $this->resetAfterTest();
        // No ignoresecurity in the settings of this \curl - the real
        // host block applies unchanged (ADR 0022).
        $curl = new \curl(['securityhelper' => $this->always_blocking_helper()]);
        $transport = new curl_transport($curl, 'lehrkraft', 'pw');

        try {
            $transport->request('GET', 'https://gesperrt.example/dav/x.md');
            $this->fail('BLOCKED expected.');
        } catch (webdav_error $e) {
            $this->assertSame(webdav_error::BLOCKED, $e->errorclass);
        }
        $this->assertDebuggingCalled();
    }

    /**
     * Security finding HIGH (Issue #510): Moodle's `\curl` disables
     * certificate verification by default and follows redirects - without a
     * counter-setting the Basic password could be read over an unencrypted
     * or foreign address. Since `\curl::mock_response()` bypasses the
     * real option setup (Moodle returns the mocked response before
     * `apply_opt()`), this test verifies the options built by {@see curl_transport}
     * directly via reflection on the private method.
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
        // Progress abort as a supplement to CURLOPT_MAXFILESIZE (which only
        // applies with a Content-Length known up front) - also limits a
        // server without Content-Length (Issue #510).
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
     * Provides verbs.
     *
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
     * All six verbs (GET already above) must take the same path through `\curl`
     * without a PHP exception caused by a wrong method signature -
     * `CURLOPT_CUSTOMREQUEST` via `post()`/`get()`/`delete()` (filelib.php
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

    /**
     * A curl double that records the options of the request and answers with a scripted result.
     *
     * @param \stdClass $capture Receives the options of the request in its property "options".
     * @param int $errno Curl error number to report.
     * @param string[]|string[][] $headers Response headers as Moodle's curl reports them.
     * @return \curl
     */
    private function scripted_curl(\stdClass $capture, int $errno = 0, array $headers = []): \curl {
        return new class ($capture, $errno, $headers) extends \curl {
            /**
             * Creates the double.
             *
             * @param \stdClass $capture Receives the options of the request.
             * @param int $scriptederrno Reported curl error number.
             * @param string[]|string[][] $scriptedheaders Reported response headers.
             */
            public function __construct(
                /** @var \stdClass Receives the options of the request. */
                private readonly \stdClass $capture,
                /** @var int Reported curl error number. */
                private readonly int $scriptederrno,
                /** @var string[]|string[][] Reported response headers. */
                private readonly array $scriptedheaders,
            ) {
                parent::__construct();
            }

            /**
             * Records the options and answers.
             *
             * @param string $url The url.
             * @param mixed[] $params The params.
             * @param mixed[] $options The options.
             * @return string
             */
            public function get($url, $params = [], $options = []) {
                $this->capture->options = $options;
                return 'Antwort';
            }

            /**
             * Reports a made request.
             *
             * @return array<string, int>
             */
            public function get_info() {
                return ['http_code' => 207];
            }

            /**
             * Reports the scripted error number.
             *
             * @return int
             */
            public function get_errno() {
                return $this->scriptederrno;
            }

            /**
             * Reports the scripted headers (name fixed by the parent class).
             *
             * @return string[]|string[][]
             */
            public function getResponse() { // phpcs:ignore moodle.NamingConventions.ValidFunctionName.LowercaseMethod
                return $this->scriptedheaders;
            }
        };
    }

    /**
     * The progress callback lets normal transfers run and aborts a download or upload above the limit.
     */
    public function test_progress_callback_aborts_only_oversized_transfers(): void {
        $capture = new \stdClass();
        (new curl_transport($this->scripted_curl($capture), 'lehrkraft', 'pw'))->request('GET', 'https://example.invalid/dav/x.md');
        $abort = $capture->options['CURLOPT_XFERINFOFUNCTION'];
        $limit = 50 * 1024 * 1024;

        $this->assertSame(0, $abort(null, 0, 1024, 0, 2048));
        $this->assertSame(0, $abort(null, 0, $limit, 0, $limit));
        $this->assertSame(1, $abort(null, 0, $limit + 1, 0, 0));
        $this->assertSame(1, $abort(null, 0, 0, 0, $limit + 1));
    }

    /**
     * A connection failure carries the error number but neither address nor credentials.
     */
    public function test_connection_failure_names_only_the_error_number(): void {
        $transport = new curl_transport($this->scripted_curl(new \stdClass(), 28), 'lehrkraft', 'geheim-pw');

        try {
            $transport->request('GET', 'https://example.invalid/dav/x.md');
            $this->fail('A failed connection must be reported.');
        } catch (webdav_transport_exception $e) {
            $this->assertSame('WebDAV connection failed (errno 28).', $e->getMessage());
        }
    }

    /**
     * Response headers are lowercased; a repeated header contributes its last value.
     */
    public function test_response_headers_are_lowercased_and_repeated_ones_use_the_last_value(): void {
        $headers = ['Content-Type' => ['text/html', 'application/xml'], 'ETag' => '"abc"'];
        $transport = new curl_transport($this->scripted_curl(new \stdClass(), 0, $headers), 'lehrkraft', 'pw');

        $response = $transport->request('GET', 'https://example.invalid/dav/x.md');

        $this->assertSame(207, $response->statuscode);
        $this->assertSame('Antwort', $response->body);
        $this->assertSame('application/xml', $response->header('content-type'));
        $this->assertSame('"abc"', $response->header('etag'));
    }
}
