<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\webdav;

/**
 * The only transport in operation: Moodle's \curl (`lib/filelib.php`), not
 * `\webdav_client` (ADR 0022, Issue #489). Six verbs via
 * `CURLOPT_CUSTOMREQUEST`, fixed `CURLAUTH_BASIC` authentication, no
 * `ignoresecurity` bypass - Moodle's host block applies unchanged, because the
 * `\curl` caller can only disable it with an explicit setting that this client
 * never sets.
 *
 * Moodle's `\curl::request()` does not throw for a blocked address,
 * but returns the block message as the return value and leaves
 * `get_info()` empty - the only distinguishing feature from a
 * connection failure (there `get_info()` carries an `http_code`, only
 * `get_errno()` is non-zero). Both cases are translated here,
 * before any error message leaves this class.
 *
 * Moodle's `\curl` disables certificate verification by default
 * (`CURLOPT_SSL_VERIFYPEER = 0`, `filelib.php::resetopt()`) and follows
 * redirects (`CURLOPT_FOLLOWLOCATION = 1`, up to ten levels deep,
 * emulated on the PHP side). Without an explicit counter-setting, the
 * Basic authentication header could be read over an unencrypted or foreign address
 * (security finding HIGH, Issue #510). {@see
 * transport_options()} therefore sets certificate verification,
 * redirect block, total timeout and response size limit on every request -
 * a 3xx response is interpreted by {@see webdav_client::classify()} as the named
 * error `REDIRECTED`, an exceeded time/size limit is reported by
 * `\curl` as a non-zero `get_errno()` and becomes the
 * error class `UNREACHABLE` here.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class curl_transport implements webdav_transport {
    /** @var int Total timeout of a request in seconds (CURLOPT_TIMEOUT). */
    private const TOTAL_TIMEOUT_SECONDS = 30;

    /** @var int Size limit of a response in bytes (CURLOPT_MAXFILESIZE), 50 MB. */
    private const MAX_RESPONSE_BYTES = 50 * 1024 * 1024;

    /**
     * @param \curl $curl Preconfigured Moodle curl instance. Tests can
     *        inject their own `securityhelper` here (see the
     *        `\curl` constructor) without setting `ignoresecurity`.
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
            // Moodle's host block never made the request in the first place.
            throw new webdav_error(webdav_error::BLOCKED, 'WebDAV address blocked by Moodle host block.');
        }
        if ($this->curl->get_errno() !== 0) {
            throw new webdav_transport_exception('WebDAV connection failed (errno ' . $this->curl->get_errno() . ').');
        }

        return new webdav_response((int) ($info['http_code'] ?? 0), $this->response_headers(), (string) $raw);
    }

    /**
     * The curl options of every request - authentication plus the four protective measures
     * from the security finding (Issue #510): certificate verification explicitly
     * enabled (Moodle's `\curl` disables it by default otherwise),
     * no redirect, total timeout, response size limit.
     * As its own method so that a transport test can verify the set options without
     * real network access.
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
            // No redirect: a 3xx response is interpreted by webdav_client as the
            // named error REDIRECTED, never followed automatically -
            // otherwise credentials could end up at a server-determined,
            // possibly unencrypted address.
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_TIMEOUT' => self::TOTAL_TIMEOUT_SECONDS,
            // CURLOPT_MAXFILESIZE alone only applies if the server announces the
            // size up front via Content-Length - a server without
            // Content-Length (e.g. chunked) could otherwise stream without limit.
            // The progress abort below therefore additionally limits the
            // byte stream actually transferred (Issue #510).
            'CURLOPT_MAXFILESIZE' => self::MAX_RESPONSE_BYTES,
            'CURLOPT_NOPROGRESS' => false,
            'CURLOPT_XFERINFOFUNCTION' => \Closure::fromCallable([$this, 'abort_when_oversized']),
        ];
    }

    /**
     * curl progress callback: aborts the transfer as soon as more than
     * {@see MAX_RESPONSE_BYTES} have been uploaded or downloaded - protects
     * even without a Content-Length known up front (Issue #510). An abort
     * leads to a non-zero `get_errno()` and is turned by
     * {@see request()} into the error class `UNREACHABLE`.
     *
     * @param resource|\CurlHandle $resource
     * @param int $downloadsize
     * @param int $downloaded
     * @param int $uploadsize
     * @param int $uploaded
     * @return int 0 continue, non-zero abort.
     */
    private function abort_when_oversized($resource, int $downloadsize, int $downloaded, int $uploadsize, int $uploaded): int {
        return ($downloaded > self::MAX_RESPONSE_BYTES || $uploaded > self::MAX_RESPONSE_BYTES) ? 1 : 0;
    }

    /**
     * @param array<string, string> $headers
     * @return string[] "Name: value" lines for CURLOPT_HTTPHEADER.
     */
    private function format_headers(array $headers): array {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        return $lines;
    }

    /**
     * @return array<string, string> Response headers of the last request, keys lowercased.
     */
    private function response_headers(): array {
        $headers = [];
        foreach ($this->curl->getResponse() as $key => $value) {
            $headers[strtolower((string) $key)] = is_array($value) ? (string) end($value) : (string) $value;
        }
        return $headers;
    }
}
