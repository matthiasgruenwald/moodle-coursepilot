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

namespace local_coursepilot;

use local_coursepilot\webdav\resolved_webdav_instance;
use local_coursepilot\webdav\webdav_client;
use local_coursepilot\webdav\webdav_error;
use local_coursepilot\webdav\webdav_instance;
use local_coursepilot\webdav\webdav_transport;

/**
 * Second adapter of the storage contract (Issue #537, Spec 0021): WebDAV.
 * Fulfils {@see storage_port} completely via an already named
 * WebDAV user instance and a relative base folder within it - both are
 * passed to the constructor, not read from the context pointer. Which
 * adapter applies to an area is decided by {@see storage_anchor::port()}.
 *
 * The check "repository instance belongs to the token holder" (ADR 0021)
 * lives in exactly one place: {@see webdav_instance::resolve_owned()}, which
 * this adapter calls anew for every operation - credentials are read
 * fresh each time, never cached. The core host block
 * ({@see \curl_transport}, in operation behind {@see webdav_instance}) remains
 * unchanged. Tests pass their fake transport to the constructor.
 *
 * The check value of this adapter is a hash formed from ETag/modification time
 * ({@see pointer_reader::external_checkvalue()}) - WebDAV has no
 * Moodle `contenthash`.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_storage_port implements storage_port {
    /**
     * Reads one level for the location selection. This stays with the WebDAV adapter:
     * the page only evaluates the returned entries as a selection.
     *
     * @param int $instanceid
     * @param string $path Relative to the instance root.
     * @return array{entries: array<int, array{name: string, type: string}>, iserv: bool}
     * @throws webdav_error
     */
    public static function browse_location(int $instanceid, string $path): array {
        $entries = self::list_location($instanceid, $path);
        if ($path === '') {
            return ['entries' => $entries, 'iserv' => webdav_instance::is_iserv_listing($entries)];
        }
        try {
            $instance = webdav_instance::resolve_owned($instanceid);
            $root = $instance->client()->propfind($instance->directory_url(''), 1);
            $iserv = webdav_instance::is_iserv_listing($root);
        } catch (webdav_error $e) {
            access_log::log_failure('WebDAV ' . $e->errorclass . ' during IServ detection: ' . $e->getMessage());
            $iserv = false;
        }
        return ['entries' => $entries, 'iserv' => $iserv];
    }

    /**
     * Lists a candidate location without pointer checks or IServ detection.
     * Missing directories are empty; other transport failures remain explicit.
     *
     * @param int $instanceid Owned repository instance.
     * @param string $path Validated instance-relative directory.
     * @return array<int, array{name: string, type: string}>
     * @throws webdav_error
     */
    public static function list_location(int $instanceid, string $path): array {
        $instance = webdav_instance::resolve_owned($instanceid);
        try {
            return $instance->client()->propfind($instance->directory_url($path), 1);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing($e, [], static fn (webdav_error $error): webdav_error => $error);
        }
    }

    /**
     * Creates missing folder levels for an explicit location selection.
     * Existing directories remain untouched; no pointer is resolved here.
     *
     * @param int $instanceid Owned repository instance.
     * @param string[] $segments Validated instance-relative folder segments.
     * @throws webdav_error
     */
    public static function create_location_directory(int $instanceid, array $segments): void {
        if (empty($segments)) {
            return;
        }
        $instance = webdav_instance::resolve_owned($instanceid);
        $instance->client()->mkcol_chain($instance->directory_url(''), $segments);
    }

    /**
     * @var string[] moodle_exception error keys from
     *      {@see webdav_instance::resolve_owned()} that create a pending write
     *      just like a {@see webdav_error} (Issue #540, ADR
     *      0023) - no fingerprint/root/IServ check here (this
     *      adapter knows no context pointer), hence shorter than
     *      {@see pointer_writer}'s LOCATION_FAILURE_CODES.
     */
    private const LOCATION_FAILURE_CODES = [
        'webdavinstancemissing',
        'webdavinstanceforeign',
        'webdavnotenabled',
        'webdavauthunsupported',
        'contextrootmissing',
    ];

    /**
     * Creates the webdav storage port.
     *
     * @param int $instanceid The WebDAV user instance, exclusively from
     *        a server-side source (never from client input) -
     *        instance ownership is checked by {@see webdav_instance::resolve_owned()}.
     * @param string $baserelativepath Base folder within the instance in
     *        which this adapter works. Empty means: the instance root.
     * @param ?webdav_transport $transport The transport.
     * @param ?pointer_location $location The location.
     */
    public function __construct(
        /** @var int The WebDAV user instance, exclusively from */
        private readonly int $instanceid,
        /** @var string Base folder within the instance in */
        private readonly string $baserelativepath = '',
        /** @var ?webdav_transport The transport. */
        private readonly ?webdav_transport $transport = null,
        /** @var ?pointer_location The location. */
        private readonly ?pointer_location $location = null,
    ) {
    }

    /**
     * Reads the webdav storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function read(storage_area $area, string $path): ?array {
        [$folders, $filename] = $this->split_file_path($area, $path);
        $resolved = $this->resolved_instance();
        $client = $resolved->client();
        $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

        try {
            $meta = $client->propfind($fileurl, 0);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing(
                $e,
                null,
                static fn (webdav_error $err): storage_failure => new storage_failure($err->errorclass, $err)
            );
        }

        $entry = $meta[0] ?? null;
        if ($entry === null || $entry['type'] === 'folder') {
            // Contract (storage_port::read()): null for a missing file
            // *or a folder* - no GET on a collection.
            return null;
        }

        try {
            $content = $client->get($fileurl);
        } catch (webdav_error $e) {
            throw new storage_failure($e->errorclass, $e);
        }
        $mimetype = $entry['mimetype'];
        if ($mimetype === 'document/unknown') {
            // Location neutrality (Issue #560): Moodle core also sniffs the
            // content for an unknown extension. No additional
            // GET here - the content is already available.
            $mimetype = webdav_client::sniff_mimetype_from_content($content) ?? $mimetype;
        }
        return [
            'content' => $content,
            'checksum' => pointer_reader::external_checkvalue($entry['etag'] ?? null, $entry['timemodified'] ?? 0),
            'size' => $entry['size'],
            'mimetype' => $mimetype,
            'timemodified' => $entry['timemodified'],
        ];
    }

    /**
     * Lists the webdav storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function list(storage_area $area, string $path): array {
        $clientdirectory = storage_anchor::normalise_client_path($area, $path);
        $resolved = $this->resolved_instance();
        $directorysegments = $clientdirectory === '' ? [] : explode('/', $clientdirectory);
        $directoryurl = $resolved->directory_url(implode('/', $this->relative_segments($directorysegments)));

        try {
            $raw = $resolved->client()->propfind($directoryurl, 1);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing(
                $e,
                [],
                static fn (webdav_error $err): storage_failure => new storage_failure($err->errorclass, $err)
            );
        }

        return array_map(static fn (array $entry): array => [
            'name' => $entry['name'],
            'type' => $entry['type'],
            'size' => $entry['size'],
            'mimetype' => $entry['mimetype'],
            // Folders carry no checksum, like Private Files (same field set).
            'checksum' => $entry['type'] === 'folder'
                ? ''
                : pointer_reader::external_checkvalue($entry['etag'], $entry['timemodified']),
            'timemodified' => $entry['timemodified'],
        ], $raw);
    }

    /**
     * Writes the webdav storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @param string $content The content.
     * @param ?string $expectedchecksum The expectedchecksum.
     */
    public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = implode('/', [...$folders, $filename]);

        try {
            $resolved = $this->resolved_instance();
            $client = $resolved->client();
            $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

            $existing = $this->current_entry($client, $fileurl);
            $this->require_checksum_match($existing, $expectedchecksum, $clientpath);
            $this->ensure_directory($resolved, $folders);
            $this->put($client, $fileurl, $content, $existing, $clientpath);

            $written = $this->current_entry($client, $fileurl);
        } catch (webdav_error $e) {
            throw new storage_failure($e->errorclass, $e);
        } catch (\moodle_exception $e) {
            throw $this->location_failure($e);
        }

        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $written['size'] ?? strlen($content),
            'checksum' => pointer_reader::external_checkvalue($written['etag'] ?? null, $written['timemodified'] ?? 0),
        ];
    }

    /**
     * Appends the webdav storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @param string $content The content.
     * @param ?string $expectedchecksum The expectedchecksum.
     */
    public function append(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        $clientpath = implode('/', [...$folders, $filename]);

        try {
            $resolved = $this->resolved_instance();
            $client = $resolved->client();
            $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

            $existing = $this->current_entry($client, $fileurl);
            $this->require_checksum_match($existing, $expectedchecksum, $clientpath);
            $this->ensure_directory($resolved, $folders);

            $newcontent = $existing === null ? $content : ($client->get($fileurl) . $content);
            $this->put($client, $fileurl, $newcontent, $existing, $clientpath);

            $written = $this->current_entry($client, $fileurl);
        } catch (webdav_error $e) {
            throw new storage_failure($e->errorclass, $e);
        } catch (\moodle_exception $e) {
            throw $this->location_failure($e);
        }

        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => strlen($newcontent),
            'checksum' => pointer_reader::external_checkvalue($written['etag'] ?? null, $written['timemodified'] ?? 0),
        ];
    }

    /**
     * Caller and conflict errors pass through; unavailable locations are outages.
     *
     * @param \moodle_exception $e Original location or caller error.
     * @return \Throwable
     */
    private function location_failure(\moodle_exception $e): \Throwable {
        if (!in_array($e->errorcode, self::LOCATION_FAILURE_CODES, true)) {
            return $e;
        }
        return new storage_failure($e->errorcode, $e);
    }

    /**
     * Deletes the webdav storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function delete(storage_area $area, string $path): bool {
        [$folders, $filename] = $this->split_file_path($area, $path);
        $resolved = $this->resolved_instance();
        $fileurl = $resolved->file_url($this->relative_path($folders, $filename));

        try {
            $resolved->client()->delete($fileurl);
            return true;
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing(
                $e,
                false,
                static fn (webdav_error $err): storage_failure => new storage_failure($err->errorclass, $err)
            );
        }
    }

    /**
     * Resolves the instance fresh - instance ownership (ADR 0021), WebDAV
     * enablement and https+Basic are checked exclusively by
     * {@see webdav_instance::resolve_owned()}, called anew here for every
     * operation so that credentials are never cached.
     *
     * @return resolved_webdav_instance
     * @throws \moodle_exception webdavinstancemissing/webdavinstanceforeign/
     *         webdavnotenabled/webdavauthunsupported
     */
    private function resolved_instance(): resolved_webdav_instance {
        if ($this->location !== null) {
            return webdav_instance::resolve($this->location, $this->transport);
        }
        return webdav_instance::resolve_owned($this->instanceid, $this->transport);
    }

    /**
     * Splits a client path generously (only `.`/`..` segments
     * forbidden, no naming rule) - for read/delete, analogous to
     * {@see storage_anchor::resolve_file()}.
     *
     * @param storage_area $area
     * @param string $path
     * @return array{0: string[], 1: string}
     * @throws \moodle_exception invalidpathkey of the area
     */
    private function split_file_path(storage_area $area, string $path): array {
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        if ($clientpath === '') {
            throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
        }
        $segments = explode('/', $clientpath);
        $filename = array_pop($segments);
        return [$segments, $filename];
    }

    /**
     * Returns segments of the base folder, without empty parts.
     *
     * @return string[] Segments of the base folder, without empty parts.
     */
    private function base_segments(): array {
        return array_values(array_filter(
            explode('/', trim($this->baserelativepath, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));
    }

    /**
     * Returns base folder segments followed by $extra.
     *
     * @param string[] $extra
     * @return string[] Base folder segments followed by $extra.
     */
    private function relative_segments(array $extra): array {
        return [...$this->base_segments(), ...$extra];
    }

    /**
     * Returns full instance-relative path of a file.
     *
     * @param string[] $folders
     * @param string $filename
     * @return string Full instance-relative path of a file.
     */
    private function relative_path(array $folders, string $filename): string {
        return implode('/', [...$this->relative_segments($folders), $filename]);
    }

    /**
     * Builds missing subfolders - base folder and folders requested by the
     * caller alike - via MKCOL level by level. An already
     * existing directory counts as success ({@see webdav_client::mkcol()}).
     *
     * @param resolved_webdav_instance $resolved
     * @param string[] $folders
     * @throws webdav_error
     */
    private function ensure_directory(resolved_webdav_instance $resolved, array $folders): void {
        if ($this->location !== null) {
            // A pointer names an existing context root. Never recreate it after
            // it was moved or deleted, otherwise writes would silently fork it.
            $this->require_pointer_root($resolved);
        }
        $segments = $this->location === null ? $this->relative_segments($folders) : $folders;
        if (empty($segments)) {
            return;
        }
        $base = $this->location === null ? '' : implode('/', $this->base_segments());
        $resolved->client()->mkcol_chain($resolved->directory_url($base), $segments);
    }

    /**
     * Requires pointer root.
     *
     * @param resolved_webdav_instance $resolved The resolved.
     * @throws webdav_error
     * @throws \moodle_exception contextrootmissing
     */
    private function require_pointer_root(resolved_webdav_instance $resolved): void {
        try {
            $resolved->client()->propfind($resolved->directory_url(implode('/', $this->base_segments())), 0);
        } catch (webdav_error $e) {
            if ($e->errorclass !== webdav_error::NOT_FOUND) {
                throw $e;
            }
            throw new \moodle_exception('contextrootmissing', 'local_coursepilot');
        }
    }

    /**
     * The current properties of the target file, or null if it is missing.
     *
     * @param webdav_client $client
     * @param string $fileurl
     * @return array{etag: ?string, timemodified: int, size: int}|null
     * @throws webdav_error every error except "not found".
     */
    private function current_entry(webdav_client $client, string $fileurl): ?array {
        try {
            $meta = $client->propfind($fileurl, 0);
        } catch (webdav_error $e) {
            return webdav_error::empty_when_missing(
                $e,
                null,
                static fn (webdav_error $err): storage_failure => new storage_failure($err->errorclass, $err)
            );
        }
        $entry = $meta[0] ?? null;
        if ($entry === null) {
            return null;
        }
        return ['etag' => $entry['etag'], 'timemodified' => $entry['timemodified'], 'size' => $entry['size']];
    }

    /**
     * Creates a file (`existing === null`) or overwrites it
     * conditionally - a transport-level conflict (412) becomes the location-neutral
     * {@see storage_conflict_exception}, so that the caller never sees a
     * webdav_error as a conflict.
     *
     * @param webdav_client $client
     * @param string $fileurl
     * @param string $content
     * @param ?array $existing Type: array{etag:?string,timemodified:int,size:int}|null.
     * @param string $clientpath For the error message.
     * @throws storage_conflict_exception
     * @throws webdav_error every other error.
     */
    private function put(webdav_client $client, string $fileurl, string $content, ?array $existing, string $clientpath): void {
        try {
            if ($existing === null) {
                $client->put_new($fileurl, $content);
            } else {
                $client->put_overwrite($fileurl, $content, $existing['etag'], $existing['timemodified']);
            }
        } catch (webdav_error $e) {
            if ($e->errorclass === webdav_error::CONFLICT) {
                throw new storage_conflict_exception($clientpath);
            }
            throw $e;
        }
    }

    /**
     * Rejects a conditional write whose check value no longer matches the
     * current state - even if the file is now missing entirely.
     * No comparison if no check value was passed (`null`).
     *
     * @param ?array $existing Type: array{etag:?string,timemodified:int,size:int}|null.
     * @param string|null $expectedchecksum
     * @param string $clientpath For the error message.
     * @throws storage_conflict_exception
     */
    private function require_checksum_match(?array $existing, ?string $expectedchecksum, string $clientpath): void {
        if ($expectedchecksum === null) {
            return;
        }
        if ($expectedchecksum === storage_port::MISSING_CHECKSUM) {
            if ($existing !== null) {
                throw new storage_conflict_exception($clientpath);
            }
            return;
        }
        $actual = $existing !== null
            ? pointer_reader::external_checkvalue($existing['etag'], $existing['timemodified'])
            : null;
        if ($actual !== $expectedchecksum) {
            throw new storage_conflict_exception($clientpath);
        }
    }
}
