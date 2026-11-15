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

namespace local_coursepilot\tests\webdav;

use local_coursepilot\webdav\webdav_response;
use local_coursepilot\webdav\webdav_transport;

/**
 * In-memory WebDAV fake (#489, Spec #486 testing decisions), shared by
 * webdav_client and all higher-level tests without endpoint-specific logic.
 * Unlike curl::mock_response(), which fixes HTTP 200 and only supplies a
 * body, maintain a file tree and evaluate conditional headers.
 *
 * Place under tests/classes for Moodle’s PHPUnit autoloader to load
 * local_coursepilot\tests\webdav classes without manual includes.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class fake_webdav_transport implements webdav_transport {
    /** @var array<string, array{content: string, etag: ?string, lastmodified: int, collection: bool}> Path => entry. */
    private array $store = ['' => ['content' => '', 'etag' => null, 'lastmodified' => 0, 'collection' => true]];

    /** @var bool Whether PUT/PROPFIND return ETags (disabled for IServ). */
    private bool $etagsenabled = true;

    /** @var string|null Normalized path displaying the IServ area menu instead of storage contents. */
    private ?string $iservrootpath = null;

    /** @var int Remaining throttled responses (404 with HTML) before recovery. */
    private int $throttleremaining = 0;

    /** @var bool Whether PUT/MKCOL return 507 (storage full). */
    private bool $full = false;

    /** @var int|null 401 or 403 to reject authentication on every request. */
    private ?int $denyauthstatus = null;

    /** @var array{path: string, statuscode: int}|null The next request to this path returns this status once. */
    private ?array $failonce = null;

    /** @var int Monotonic timestamp clock for deterministically distinct modification times. */
    private int $clocktick = 1_700_000_000;

    /**
     * Request log for assertions such as PUT with If-None-Match: *.
     *
     * @var array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    private array $log = [];

    /**
     * Creates the fake webdav transport.
     *
     * @param string $secretpassword Fake-instance password used only to verify that responses,
     *        exceptions and error messages never leak it.
     */
    public function __construct(
        /** @var string Fake-instance password used only to verify that responses, */
        private readonly string $secretpassword = 'g3h31m-nie-sichtbar',
    ) {
    }

    /**
     * Returns see constructor.
     *
     * @return string See constructor.
     */
    public function secret(): string {
        return $this->secretpassword;
    }

    /**
     * Provides requests.
     *
     * @return array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public function requests(): array {
        return $this->log;
    }

    /**
     * Create a stored file for test setup without HTTP.
     *
     * @param string $path For example, "/folder/file.md".
     * @param string $content
     * @return array{etag: ?string, lastmodified: int} Created version for subsequent conditional test requests.
     */
    public function seed_file(string $path, string $content): array {
        $entry = [
            'content' => $content,
            'etag' => $this->etagsenabled ? $this->make_etag($content) : null,
            'lastmodified' => $this->tick(),
            'collection' => false,
        ];
        $this->store[$this->normalise($path)] = $entry;
        return ['etag' => $entry['etag'], 'lastmodified' => $entry['lastmodified']];
    }

    /**
     * Seeds folder.
     *
     * @param string $path For example, "/folder".
     */
    public function seed_folder(string $path): void {
        $this->store[$this->normalise($path)] = [
            'content' => '',
            'etag' => null,
            'lastmodified' => $this->tick(),
            'collection' => true,
        ];
    }

    /**
     * IServ returns no ETags; weak getlastmodified is the only comparison value.
     */
    public function without_etags(): void {
        $this->etagsenabled = false;
    }

    /**
     * PROPFIND at this path shows the IServ area menu
     * (Files/, Groups/, Print/, Temp/, Windows/) instead of stored content.
     *
     * @param string $path Default "/", the instance root.
     */
    public function as_iserv_root(string $path = '/'): void {
        $this->iservrootpath = $this->normalise($path);
    }

    /**
     * The next failures requests, regardless of method, return 404 and an
     * HTML guest page. Then the fake resumes normal responses.
     *
     * @param int $failures
     */
    public function throttle(int $failures): void {
        $this->throttleremaining = $failures;
    }

    /**
     * PUT and MKCOL subsequently return 507 (storage full).
     */
    public function fill_storage(): void {
        $this->full = true;
    }

    /**
     * All requests return the rejected-authentication status. Both 401
     * (unauthenticated) and 403 (unauthorized) have the same meaning in Spec §4.
     *
     * @param int $statuscode 401 or 403.
     */
    public function deny_auth(int $statuscode = 401): void {
        $this->denyauthstatus = $statuscode;
    }

    /**
     * Only the next request to path returns statuscode, regardless of method.
     * Unlike {@see deny_auth()} and {@see throttle()}, later requests recover.
     * This distinguishes successful listing from failed IServ root detection.
     *
     * @param string $path For example, "/Coursepilot".
     * @param int $statuscode
     */
    public function fail_once(string $path, int $statuscode = 401): void {
        $this->failonce = ['path' => $this->normalise($path), 'statuscode' => $statuscode];
    }

    /**
     * Provides request.
     *
     * @param string $method The method.
     * @param string $url The url.
     * @param mixed[] $headers The headers.
     * @param ?string $body The body.
     * @return webdav_response
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        $this->log[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        if ($this->throttleremaining > 0) {
            $this->throttleremaining--;
            return new webdav_response(404, ['content-type' => 'text/html; charset=utf-8'], $this->guest_page_html());
        }
        if ($this->denyauthstatus !== null) {
            return new webdav_response($this->denyauthstatus, [], '');
        }

        $path = $this->normalise($this->path_of($url));
        if ($this->failonce !== null && $this->failonce['path'] === $path) {
            $statuscode = $this->failonce['statuscode'];
            $this->failonce = null;
            return new webdav_response($statuscode, [], '');
        }
        return match ($method) {
            'PROPFIND' => $this->handle_propfind($url, $path, $headers['Depth'] ?? '1'),
            'GET' => $this->handle_get($path),
            'PUT' => $this->handle_put($path, $headers, $body ?? ''),
            'MKCOL' => $this->handle_mkcol($path),
            'MOVE' => $this->handle_move($path, $headers['Destination'] ?? ''),
            'DELETE' => $this->handle_delete($path),
            default => throw new \InvalidArgumentException('Unbekanntes WebDAV-Verb: ' . $method),
        };
    }

    /**
     * Handles propfind.
     *
     * @param string $requesturl The requesturl.
     * @param string $path The path.
     * @param string $depth The depth.
     * @return webdav_response
     */
    private function handle_propfind(string $requesturl, string $path, string $depth): webdav_response {
        if ($this->iservrootpath !== null && $path === $this->iservrootpath) {
            return $this->iserv_menu_response($requesturl, $path);
        }
        if (!isset($this->store[$path])) {
            return $this->not_found_dav_response();
        }

        $entries = [$path => $this->store[$path]];
        if ($depth === '1' && $this->store[$path]['collection']) {
            foreach ($this->store as $childpath => $entry) {
                if ($childpath !== $path && $this->parent_of($childpath) === $path) {
                    $entries[$childpath] = $entry;
                }
            }
        }
        return new webdav_response(
            207,
            ['content-type' => 'application/xml; charset=utf-8'],
            $this->multistatus_xml($requesturl, $entries)
        );
    }

    /**
     * Handles get.
     *
     * @param string $path The path.
     * @return webdav_response
     */
    private function handle_get(string $path): webdav_response {
        if (!isset($this->store[$path]) || $this->store[$path]['collection']) {
            return $this->not_found_dav_response();
        }
        $entry = $this->store[$path];
        $headers = ['content-type' => 'text/plain; charset=utf-8'];
        if ($entry['etag'] !== null) {
            $headers['etag'] = $entry['etag'];
        }
        return new webdav_response(200, $headers, $entry['content']);
    }

    /**
     * Handles put.
     *
     * @param string $path The path.
     * @param mixed[] $headers The headers.
     * @param string $body The body.
     * @return webdav_response
     */
    private function handle_put(string $path, array $headers, string $body): webdav_response {
        if ($this->full) {
            return new webdav_response(507, [], '');
        }

        // Unlike MKCOL, PUT does not check parent existence. Real base paths
        // are already resolved and present (Spec §2), not constructs of the fake.
        $existing = $this->store[$path] ?? null;
        $ifnonematch = $headers['If-None-Match'] ?? null;
        if ($ifnonematch === '*' && $existing !== null) {
            return new webdav_response(412, [], '');
        }
        $ifmatch = $headers['If-Match'] ?? null;
        if ($ifmatch !== null && ($existing === null || $existing['etag'] !== $ifmatch)) {
            return new webdav_response(412, [], '');
        }

        $wasnew = $existing === null;
        $etag = $this->etagsenabled ? $this->make_etag($body) : null;
        $this->store[$path] = [
            'content' => $body,
            'etag' => $etag,
            'lastmodified' => $this->tick(),
            'collection' => false,
        ];
        $responseheaders = $etag !== null ? ['etag' => $etag] : [];
        return new webdav_response($wasnew ? 201 : 204, $responseheaders, '');
    }

    /**
     * Handles mkcol.
     *
     * @param string $path The path.
     * @return webdav_response
     */
    private function handle_mkcol(string $path): webdav_response {
        if ($this->full) {
            return new webdav_response(507, [], '');
        }
        if (isset($this->store[$path])) {
            // Already exists; mkcol_chain() treats this as success.
            return new webdav_response(405, [], '');
        }
        $parent = $this->parent_of($path);
        if ($parent !== '' && (!isset($this->store[$parent]) || !$this->store[$parent]['collection'])) {
            return new webdav_response(409, [], '');
        }
        $this->store[$path] = ['content' => '', 'etag' => null, 'lastmodified' => $this->tick(), 'collection' => true];
        return new webdav_response(201, [], '');
    }

    /**
     * Handles move.
     *
     * @param string $path The path.
     * @param string $destinationurl The destinationurl.
     * @return webdav_response
     */
    private function handle_move(string $path, string $destinationurl): webdav_response {
        if (!isset($this->store[$path])) {
            return $this->not_found_dav_response();
        }
        if ($destinationurl === '') {
            return new webdav_response(409, [], '');
        }
        $destination = $this->normalise($this->path_of($destinationurl));
        $this->store[$destination] = $this->store[$path];
        unset($this->store[$path]);
        return new webdav_response(201, [], '');
    }

    /**
     * Handles delete.
     *
     * @param string $path The path.
     * @return webdav_response
     */
    private function handle_delete(string $path): webdav_response {
        if (!isset($this->store[$path])) {
            return $this->not_found_dav_response();
        }
        unset($this->store[$path]);
        return new webdav_response(204, [], '');
    }

    /**
     * Provides not found dav response.
     *
     * @return webdav_response
     */
    private function not_found_dav_response(): webdav_response {
        $body = '<?xml version="1.0" encoding="utf-8"?><d:error xmlns:d="DAV:"><d:resource-not-found/></d:error>';
        return new webdav_response(404, ['content-type' => 'application/xml; charset=utf-8'], $body);
    }

    /**
     * Provides guest page html.
     *
     * @return string
     */
    private function guest_page_html(): string {
        return '<!doctype html><html><head><title>Anmelden</title></head><body>Gast-Portal, bitte anmelden.</body></html>';
    }

    /**
     * Provides iserv menu response.
     *
     * @param string $requesturl The requesturl.
     * @param string $rootpath The rootpath.
     * @return webdav_response
     */
    private function iserv_menu_response(string $requesturl, string $rootpath): webdav_response {
        $areas = ['Files', 'Groups', 'Print', 'Temp', 'Windows'];
        $entries = [$rootpath => ['content' => '', 'etag' => null, 'lastmodified' => $this->clocktick, 'collection' => true]];
        foreach ($areas as $area) {
            $entries[$rootpath . '/' . $area] = [
                'content' => '',
                'etag' => null,
                'lastmodified' => $this->clocktick,
                'collection' => true,
            ];
        }
        return new webdav_response(
            207,
            ['content-type' => 'application/xml; charset=utf-8'],
            $this->multistatus_xml($requesturl, $entries)
        );
    }

    /**
     * Provides multistatus xml.
     *
     * @param string $requesturl href of the resolved level itself, used for client comparison.
     * @param mixed[] $entries Path => entry.
     *        Type: array<string,array{content:string,etag:?string,lastmodified:int,collection:bool}>.
     */
    private function multistatus_xml(string $requesturl, array $entries): string {
        $base = $this->origin($requesturl);
        $xml = '<?xml version="1.0" encoding="utf-8"?><d:multistatus xmlns:d="DAV:">';
        foreach ($entries as $path => $entry) {
            $href = $base . ($path === '' ? '/' : $this->encode_path($path) . ($entry['collection'] ? '/' : ''));
            $xml .= '<d:response><d:href>' . htmlspecialchars($href, ENT_XML1) . '</d:href>';
            $xml .= '<d:propstat><d:prop>';
            $xml .= '<d:resourcetype>' . ($entry['collection'] ? '<d:collection/>' : '') . '</d:resourcetype>';
            if (!$entry['collection']) {
                $xml .= '<d:getcontentlength>' . strlen($entry['content']) . '</d:getcontentlength>';
            }
            $xml .= '<d:getlastmodified>' . gmdate('D, d M Y H:i:s', $entry['lastmodified']) . ' GMT</d:getlastmodified>';
            if ($entry['etag'] !== null) {
                $xml .= '<d:getetag>' . htmlspecialchars($entry['etag'], ENT_XML1) . '</d:getetag>';
            }
            $xml .= '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>';
            $xml .= '</d:response>';
        }
        $xml .= '</d:multistatus>';
        return $xml;
    }

    /**
     * Returns request URL scheme and host without a trailing slash.
     *
     * @param string $url The url.
     * @return string Request URL scheme and host without a trailing slash.
     */
    private function origin(string $url): string {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?? 'https';
        $host = parse_url($url, PHP_URL_HOST) ?? 'fake.example';
        return $scheme . '://' . $host;
    }

    /**
     * Returns percent-encoded path, encoding each segment individually.
     *
     * @param string $path The path.
     * @return string Percent-encoded path, encoding each segment individually.
     */
    private function encode_path(string $path): string {
        if ($path === '') {
            return '';
        }
        $segments = explode('/', ltrim($path, '/'));
        return '/' . implode('/', array_map('rawurlencode', $segments));
    }

    /**
     * Provides path of.
     *
     * @param string $url The url.
     * @return string
     */
    private function path_of(string $url): string {
        return (string) (parse_url($url, PHP_URL_PATH) ?? '');
    }

    /**
     * Returns decoded path without a trailing slash; root is "".
     *
     * @param string $path The path.
     * @return string Decoded path without a trailing slash; root is "".
     */
    private function normalise(string $path): string {
        return rtrim(rawurldecode($path), '/');
    }

    /**
     * Provides parent of.
     *
     * @param string $path The path.
     * @return string
     */
    private function parent_of(string $path): string {
        $pos = strrpos($path, '/');
        return $pos === false ? '' : substr($path, 0, $pos);
    }

    /**
     * Makes etag.
     *
     * @param string $content The content.
     * @return string
     */
    private function make_etag(string $content): string {
        return '"' . substr(sha1($content), 0, 16) . '"';
    }

    /**
     * Provides tick.
     *
     * @return int
     */
    private function tick(): int {
        return $this->clocktick++;
    }
}
