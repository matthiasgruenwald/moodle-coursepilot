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

/**
 * The project's own slim WebDAV client (issue #489, spec #486 §4, ADR 0022).
 * Six verbs, named error classes instead of status codes, silent retry on
 * `unclear/throttled`, conditional writes. Not yet connected to any tool -
 * pure foundation, fully tested through the swappable
 * {@see webdav_transport}.
 *
 * Location selection and the context tools (later tickets) use the same
 * client, not `get_listing()` (spec §4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_client {

    /**
     * @var float Maximum retry duration for `unclear/throttled` (spec
     *      §4, issue #529). Deliberately kept short: a measurement against a
     *      single Nextcloud instance showed 15-34s recovery time after a
     *      large append (presumably Nextcloud's own brute-force/rate
     *      protection, triggered by a missing `trusted_proxies` behind a
     *      reverse proxy, or tight `pm.max_children`) - a budget that
     *      covers this one measured value does not cover a foreign,
     *      possibly worse-configured instance and needlessly blocks every
     *      tool call for too long. The real answer to an exceeded
     *      throttle is the pending write
     *      ({@see pending_write_translation}), not a longer wait.
     */
    private const RETRY_BUDGET_SECONDS = 10.0;

    /** @var float Wait time between two retry attempts. */
    private const RETRY_DELAY_SECONDS = 0.2;

    /** @var int[] HTTP statuses that count as success unless specified otherwise. */
    private const DEFAULT_SUCCESS = [200, 201, 204, 207];

    /** @var string Minimal PROPFIND body: all properties. */
    private const PROPFIND_BODY = '<?xml version="1.0" encoding="utf-8"?><propfind xmlns="DAV:"><allprop/></propfind>';

    /**
     * @param webdav_transport $transport The swappable transport seam.
     *        In production {@see curl_transport}, in tests the reusable
     *        in-memory fake.
     * @param callable $clock () => float, seconds since some fixed
     *        zero point. Only needed for the retry clock - replaceable in
     *        tests so nothing is actually waited for.
     * @param callable $sleeper (float $seconds) => void.
     */
    public function __construct(
        private readonly webdav_transport $transport,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleeper = $sleeper ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    /** @var callable */
    private $clock;

    /** @var callable */
    private $sleeper;

    /**
     * Lists one level (depth 1) or reads the properties of a
     * single resource (depth 0).
     *
     * @param string $url
     * @param int $depth 0 or 1.
     * @return array<int, array{name: string, type: string, size: int,
     *         timemodified: int, etag: ?string, mimetype: string}>
     * @throws webdav_error
     */
    public function propfind(string $url, int $depth = 1): array {
        if ($depth !== 0 && $depth !== 1) {
            throw new \InvalidArgumentException('PROPFIND only supports depth 0 or 1.');
        }
        $response = $this->send('PROPFIND', $url, [
            'Depth' => (string) $depth,
            'Content-Type' => 'application/xml',
        ], self::PROPFIND_BODY);
        return $this->parse_multistatus($response->body, $url, $depth);
    }

    /**
     * @param string $url
     * @return string File body.
     * @throws webdav_error
     */
    public function get(string $url): string {
        return $this->send('GET', $url)->body;
    }

    /**
     * Creates a new file - never an overwrite (`If-None-Match: *`).
     *
     * @param string $url
     * @param string $content
     * @throws webdav_error CONFLICT (412) if something already exists there.
     */
    public function put_new(string $url, string $content): void {
        $this->send('PUT', $url, ['If-None-Match' => '*'], $content);
    }

    /**
     * Conditionally overwrites an existing file (spec §4).
     *
     * If an ETag is available, `If-Match: <ETag>` carries the comparison - a
     * server-checked 412 becomes CONFLICT. If an ETag is missing (IServ),
     * `getlastmodified` serves as a weak, client-side-checked substitute: a
     * differing modification time becomes CONFLICT locally, *before* anything
     * is written at all. Lost updates are thus only weakly
     * detectable (spec §4 names this as open).
     *
     * @param string $url
     * @param string $content
     * @param string|null $etag Last-read ETag, if the server supplies one.
     * @param int|null $expectedlastmodified Last-read modification time, if no ETag is available.
     * @throws webdav_error CONFLICT on a detected collision.
     */
    public function put_overwrite(string $url, string $content, ?string $etag, ?int $expectedlastmodified = null): void {
        $headers = [];
        if ($etag !== null) {
            $headers['If-Match'] = $etag;
        } elseif ($expectedlastmodified !== null) {
            $current = $this->propfind($url, 0);
            $actual = $current[0]['timemodified'] ?? null;
            if ($actual !== $expectedlastmodified) {
                throw new webdav_error(webdav_error::CONFLICT, 'Modification time differs from the expected state (no ETag).');
            }
        }
        $this->send('PUT', $url, $headers, $content);
    }

    /**
     * A single directory. An already existing directory (405)
     * counts as success - {@see mkcol_chain()} builds level by level without
     * failing on an already existing level on every run.
     *
     * @param string $url
     * @throws webdav_error
     */
    public function mkcol(string $url): void {
        $this->send('MKCOL', $url, [], null, [201, 405]);
    }

    /**
     * Builds a folder chain level by level via MKCOL (spec §4).
     *
     * @param string $baseurl Root from which the chain is created.
     * @param string[] $segments Folder names, unencoded.
     * @throws webdav_error
     */
    public function mkcol_chain(string $baseurl, array $segments): void {
        $url = rtrim($baseurl, '/') . '/';
        foreach ($segments as $segment) {
            $url .= rawurlencode($segment) . '/';
            $this->mkcol($url);
        }
    }

    /**
     * @param string $sourceurl
     * @param string $destinationurl Full destination address.
     * @throws webdav_error
     */
    public function move(string $sourceurl, string $destinationurl): void {
        $this->send('MOVE', $sourceurl, ['Destination' => $destinationurl]);
    }

    /**
     * @param string $url
     * @throws webdav_error
     */
    public function delete(string $url): void {
        $this->send('DELETE', $url);
    }

    /**
     * The one request path shared by all six verbs: https requirement,
     * error classification, silent retry on `unclear/throttled`
     * (including 429/503), for at most {@see RETRY_BUDGET_SECONDS}.
     *
     * @param string $method
     * @param string $url
     * @param array<string, string> $headers
     * @param string|null $body
     * @param int[] $successcodes
     * @return webdav_response
     * @throws webdav_error
     */
    private function send(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $successcodes = self::DEFAULT_SUCCESS,
    ): webdav_response {
        $this->assert_https($url);
        $start = ($this->clock)();

        while (true) {
            try {
                $response = $this->transport->request($method, $url, $headers, $body);
            } catch (webdav_transport_exception $e) {
                throw new webdav_error(webdav_error::UNREACHABLE, $e->getMessage());
            }

            $errorclass = $this->classify($response, $successcodes);
            if ($errorclass === null) {
                return $response;
            }
            if ($errorclass !== webdav_error::UNCLEAR) {
                throw new webdav_error($errorclass, 'HTTP ' . $response->statuscode);
            }

            $elapsed = ($this->clock)() - $start;
            if ($elapsed >= self::RETRY_BUDGET_SECONDS) {
                throw new webdav_error(webdav_error::UNCLEAR, 'Retry given up after ' . self::RETRY_BUDGET_SECONDS . 's.');
            }
            ($this->sleeper)(self::RETRY_DELAY_SECONDS);
        }
    }

    /**
     * @param string $url
     * @throws \InvalidArgumentException
     */
    private function assert_https(string $url): void {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \InvalidArgumentException('The WebDAV client only accepts https addresses.');
        }
    }

    /**
     * @param webdav_response $response
     * @param int[] $successcodes
     * @return string|null A {@see webdav_error} constant, or null on success.
     */
    private function classify(webdav_response $response, array $successcodes): ?string {
        $code = $response->statuscode;
        if (in_array($code, $successcodes, true)) {
            return null;
        }
        if ($code === 401 || $code === 403) {
            return webdav_error::AUTH_REJECTED;
        }
        if ($code === 507) {
            return webdav_error::STORAGE_FULL;
        }
        if ($code === 409 || $code === 412) {
            return webdav_error::CONFLICT;
        }
        if ($code === 404) {
            return $this->is_dav_xml_body($response) ? webdav_error::NOT_FOUND : webdav_error::UNCLEAR;
        }
        if ($code >= 300 && $code < 400) {
            // The transport does not follow redirects (curl_transport::transport_options()) -
            // a 3xx response is therefore a named error, never silently retryable: otherwise
            // credentials could reach a server-chosen, possibly unencrypted
            // address (issue #510).
            return webdav_error::REDIRECTED;
        }
        // 429, 503 and every other unnamed status: silently retryable, never silently success.
        return webdav_error::UNCLEAR;
    }

    /**
     * Distinguishes a real DAV 404 (XML body) from a throttled
     * HTML guest page that also answers with 404 (spec §4).
     *
     * @param webdav_response $response
     * @return bool
     */
    private function is_dav_xml_body(webdav_response $response): bool {
        if (stripos($response->header('content-type') ?? '', 'xml') !== false) {
            return true;
        }
        $trimmed = ltrim($response->body);
        return str_starts_with($trimmed, '<?xml') || stripos($trimmed, 'DAV:') !== false;
    }

    /**
     * @param string $url
     * @return string Decoded path part, without trailing slash.
     */
    private function normalised_path(string $url): string {
        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        return rtrim(rawurldecode($path), '/');
    }

    /**
     * Evaluates a PROPFIND multistatus body (spec §4): name
     * (percent-decoded), type, size, modification time and ETag if
     * present. The MIME type comes purely from the extension ({@see mimeinfo()}) -
     * no content sniffing here, that would force an additional GET for every file
     * with an unknown extension when listing and thereby violate the
     * existing zero-GET contract of {@see \local_coursepilot\external\list_context_files_test::test_switch_on_never_fetches_marked_file_content()}
     * (issue #560 - sniffing instead sits in {@see webdav_storage_port::read()},
     * where the content is fetched anyway).
     *
     * @param string $body
     * @param string $requesturl
     * @param int $depth
     * @return array<int, array{name: string, type: string, size: int,
     *         timemodified: int, etag: ?string, mimetype: string}>
     * @throws webdav_error UNCLEAR if the body is not readable as XML despite
     *         a 2xx status - never silently an empty folder (issue #510),
     *         otherwise handover hint and legacy-content detection are lost.
     */
    private function parse_multistatus(string $body, string $requesturl, int $depth): array {
        $previous = libxml_use_internal_errors(true);
        // LIBXML_NONET: no network access while parsing, not even for an
        // external DTD/entity linked in the body (issue #510).
        $sxe = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($sxe === false) {
            throw new webdav_error(webdav_error::UNCLEAR, 'PROPFIND body not readable as XML despite success status.');
        }

        $requestpath = $this->normalised_path($requesturl);
        $entries = [];
        foreach ($sxe->children('DAV:')->response as $responsenode) {
            $davresponse = $responsenode->children('DAV:');
            $path = $this->normalised_path((string) $davresponse->href);
            if ($depth === 1 && $path === $requestpath) {
                // The resolved level itself, not its content.
                continue;
            }

            $prop = $this->successful_prop($davresponse);
            if ($prop === null) {
                continue;
            }

            $name = basename($path);
            if ($name === '') {
                continue;
            }

            $iscollection = isset($prop->resourcetype->children('DAV:')->collection);
            $lastmodifiedraw = (string) $prop->getlastmodified;
            $etagraw = (string) $prop->getetag;

            $entries[] = [
                'name' => $name,
                'type' => $iscollection ? 'folder' : 'file',
                'size' => $iscollection ? 0 : (int) (string) $prop->getcontentlength,
                'timemodified' => $lastmodifiedraw !== '' ? (int) strtotime($lastmodifiedraw) : 0,
                'etag' => $etagraw !== '' ? $etagraw : null,
                'mimetype' => $iscollection ? '' : mimeinfo('type', $name),
            ];
        }
        return $entries;
    }

    /**
     * Content sniffing for an already available file content, exactly as
     * Moodle core does it for local files ({@see \file_storage::mimetype_from_file()},
     * `lib/filestorage/file_storage.php`) - only applied to a string instead of a
     * file path (issue #560). No GET of its own: the caller must already have
     * fetched the content for another purpose (e.g.
     * {@see webdav_storage_port::read()}), otherwise sniffing on mere
     * listing would be an additional, expensive and unwanted network contact.
     *
     * @param string $content The (partial) content of the file.
     * @return string|null The sniffed mimetype, or null if nothing
     *         usable could be determined (empty content) - the caller
     *         then stays with `document/unknown`.
     */
    public static function sniff_mimetype_from_content(string $content): ?string {
        if ($content === '') {
            return null;
        }

        $mimetype = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        if ($mimetype === false) {
            return null;
        }
        if ($mimetype === 'image/svg') {
            // Wie file_storage::mimetype_from_file(): https://bugs.php.net/bug.php?id=79045.
            $mimetype = 'image/svg+xml';
        }
        return mimeinfo_from_type('type', $mimetype);
    }

    /**
     * The first `propstat` with status 200 of a `response` node, or
     * null if none was successful (e.g. a property the
     * server does not know for this entry).
     *
     * @param \SimpleXMLElement $davresponse
     * @return \SimpleXMLElement|null
     */
    private function successful_prop(\SimpleXMLElement $davresponse): ?\SimpleXMLElement {
        foreach ($davresponse->propstat as $propstat) {
            $psdav = $propstat->children('DAV:');
            $status = (string) $psdav->status;
            if ($status === '' || str_contains($status, ' 200 ')) {
                return $psdav->prop->children('DAV:');
            }
        }
        return null;
    }
}
