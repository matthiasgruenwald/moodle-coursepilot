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

/**
 * Four context operations (read, write, append, list), independent of
 * storage location for callers (Spec 0021). The external tools no
 * longer branch on location, location-error keys or separate etag fields.
 *
 * Separate from {@see context_files} to stay below the 800-line guideline,
 * following the split of {@see pointer_reader}/{@see pointer_writer} from
 * {@see storage_anchor}. Unlike those helpers, this class intentionally
 * fixes context_files::area(): no second area needs it (YAGNI, ADR 0020).
 *
 * All operations use {@see storage_anchor::port()} with the
 * {@see private_files_storage_port} and {@see webdav_storage_port} adapters
 * Failure handling (ADR 0023, pending note without fallback)
 * applies to both locations. Adapters report outages without recording notes;
 * this module records them once through {@see pending_write_translation}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_area {
    /**
     * Reads a context file through the current location's adapter. The
     * checksum is returned as "contenthash" for both locations.
     *
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read(string $path): ?array {
        return self::read_from(storage_anchor::port(context_files::area()), $path);
    }

    /**
     * Like {@see read()}, but for the read-only previous location (Issue #498).
     *
     * @param string $path
     * @param pointer_location $location
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     */
    public static function read_previous_location(string $path, pointer_location $location): ?array {
        return self::read_from(storage_anchor::port_at($location), $path);
    }

    /**
     * Reads from.
     *
     * @param storage_port $port
     * @param string $path
     * @return mixed[]|null
     */
    private static function read_from(storage_port $port, string $path): ?array {
        $area = context_files::area();
        try {
            $file = $port->read($area, $path);
        } catch (storage_failure $e) {
            throw pointer_reader::storage_exception($e);
        }
        return $file === null ? null : [
            'path' => storage_anchor::normalise_client_path($area, $path),
            'content' => $file['content'],
            'mimetype' => $file['mimetype'],
            'size' => $file['size'],
            'contenthash' => $file['checksum'],
            'timemodified' => $file['timemodified'],
        ];
    }

    /**
     * Lists one level of the context area with the same field set for both
     * locations, "locked" already evaluated.
     *
     * @param string $path
     * @param bool $previouslocation Lists the read-only previous location instead.
     * @return array{directory: string, entries: mixed[]}
     */
    public static function list(string $path, bool $previouslocation = false): array {
        $area = context_files::area();
        $port = $previouslocation
            ? storage_anchor::port_at(previous_location::require_open_location())
            : storage_anchor::port($area);
        $directory = storage_anchor::normalise_client_path($area, $path);
        try {
            $entries = $port->list($area, $path);
        } catch (storage_failure $e) {
            throw pointer_reader::storage_exception($e);
        }

        return [
            'directory' => $directory,
            'entries' => array_map(
                static fn (array $entry): array => self::annotate_entry($port, $entry, $directory),
                $entries
            ),
        ];
    }

    /**
     * Renames the adapter checksum to "contenthash" and adds "locked".
     *
     * @param storage_port $port The adapter that listed the entry.
     * @param mixed[] $entry An entry from {@see storage_port::list()}.
     * @param string $directory Result directory, see {@see list()}.
     * @return mixed[]
     */
    private static function annotate_entry(storage_port $port, array $entry, string $directory): array {
        $checksum = $entry['checksum'];
        unset($entry['checksum']);

        if ($entry['type'] === 'folder') {
            return $entry + ['contenthash' => '', 'locked' => false];
        }
        $entry['contenthash'] = $checksum;

        // Only .md files carry a personal-data frontmatter marker (#506/#493).
        $ismarkdown = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) === 'md';
        if (!$ismarkdown || personal_data::allowed()) {
            return $entry + ['locked' => false];
        }

        return $entry + ['locked' => self::is_entry_marked($port, $entry, $directory)];
    }

    /**
     * Whether a .md file is marked as personal data, via the mark memory
     * (Issue #493) keyed by the adapter checksum. The file is read from the
     * same adapter that listed it.
     *
     * @param storage_port $port
     * @param mixed[] $entry
     * @param string $directory
     * @return bool
     */
    private static function is_entry_marked(storage_port $port, array $entry, string $directory): bool {
        $relativepath = $directory === '' ? $entry['name'] : $directory . '/' . $entry['name'];

        $marked = mark_memory::lookup($relativepath, $entry['size'], $entry['timemodified'], $entry['contenthash']);
        if ($marked === null) {
            $content = self::read_from($port, $relativepath);
            $marked = $content !== null && personal_data::is_marked($content['content']);
            mark_memory::remember($relativepath, $entry['size'], $entry['timemodified'], $entry['contenthash'], $marked);
        }

        return $marked;
    }

    /**
     * Adds missing context files without touching any teacher-owned content.
     * Pre-read failures and conditional-create conflicts are best-effort failures,
     * never a reason to interrupt location selection (#603).
     *
     * @param string[] $files Relative path => complete content.
     * @return string[] Paths actually created.
     */
    public static function supplement_missing(array $files): array {
        $created = [];
        foreach ($files as $path => $content) {
            try {
                if (self::read($path) !== null) {
                    continue;
                }
                self::write($path, $content, createonly: true);
                $created[] = $path;
            } catch (\Throwable $e) {
                // Includes pre-read outages, locked files and concurrent creation.
                // The normal write path retains its pending-write failure policy.
                continue;
            }
        }
        return $created;
    }

    /**
     * Writes a context file through the adapter of the current location
     * ({@see storage_anchor::port()}); no tool sees the location decision.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash See {@see context_files::write_pointer_aware()}.
     * @param string $pendingentry See {@see context_files::write_pointer_aware()}.
     * @param bool $createonly See {@see context_files::write_pointer_aware()}.
     * @param int $courseid See {@see context_files::write_pointer_aware()}.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     */
    public static function write(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $pendingentry = '',
        bool $createonly = false,
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        try {
            $location = storage_anchor::effective_location($area);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            if (personal_data::is_marked($content) && !personal_data::allowed()) {
                throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
            }
            throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_CREATE, $courseid);
        }
        return self::write_to(
            storage_anchor::port_at($location),
            $location,
            $path,
            $content,
            $expectedcontenthash,
            $pendingentry,
            $createonly,
            $courseid
        );
    }

    /**
     * Writes through an already selected storage port with the same preflight guards.
     *
     * @param storage_port $port Selected storage adapter.
     * @param pointer_location $location Selected location for personal-data policy and failure descriptions.
     * @param string $path Relative context path.
     * @param string $content Complete new content.
     * @param string $expectedcontenthash Expected preflight checksum.
     * @param string $pendingentry Pending retry identifier.
     * @param bool $createonly Reject an existing target.
     * @param int $courseid Course ID for pending writes.
     * @return array{path: string, created: bool, size: int, oldsize: int}
     */
    public static function write_to(
        storage_port $port,
        pointer_location $location,
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $pendingentry = '',
        bool $createonly = false,
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        self::require_write_access($location);
        $existing = self::preread($port, $location, $path, pointer_writer::OP_UNKNOWN, $courseid);
        if ($existing !== null && $createonly) {
            throw new \moodle_exception('contextfilealreadyexists', 'local_coursepilot', '', $path);
        }
        self::guard_existing_locked($existing, $path);
        self::guard_new_content($location, $path, $content);
        self::guard_pending_retry($path, $pendingentry, $existing !== null, $expectedcontenthash);
        // Reuse the preflight read as the write condition. This closes the
        // read-write window without exposing location-specific concurrency.
        $checksum = $expectedcontenthash !== '' ? $expectedcontenthash : ($existing['checksum'] ?? storage_port::MISSING_CHECKSUM);
        $operation = $existing === null ? pending_write_translation::OP_CREATE : pending_write_translation::OP_OVERWRITE;
        $written = self::persist($port, $location, $path, $content, $operation, $courseid, $checksum);
        return [
            'path' => $written['path'],
            'created' => $written['created'],
            'size' => $written['size'],
            'oldsize' => $existing['size'] ?? 0,
        ];
    }

    /**
     * Reads the target before any mutation; an outage aborts and records the pending operation.
     *
     * @param storage_port $port Selected adapter.
     * @param pointer_location $location Selected location.
     * @param string $path Relative context path.
     * @param string $operation Pending operation if the target cannot be read.
     * @param int $courseid Course ID for the pending note.
     * @return array{content: string, checksum: string, size: int, mimetype: string, timemodified: int}|null
     */
    private static function preread(
        storage_port $port,
        pointer_location $location,
        string $path,
        string $operation,
        int $courseid
    ): ?array {
        try {
            return $port->read(context_files::area(), $path);
        } catch (storage_failure $e) {
            throw pointer_writer::record_preread_failure($e, $location, $path, $operation, $courseid);
        } catch (\moodle_exception $e) {
            $locationoperation = $operation === pointer_writer::OP_UNKNOWN ? pointer_writer::OP_CREATE : $operation;
            throw pointer_writer::record_location_failure($e, $path, $location, $locationoperation, $courseid);
        }
    }

    /**
     * Rejects new personal data when disabled or at a disallowed host.
     *
     * @param pointer_location $location Selected location.
     * @param string $path Relative context path.
     * @param string $content Complete replacement content.
     */
    private static function guard_new_content(pointer_location $location, string $path, string $content): void {
        if (!personal_data::is_marked($content)) {
            return;
        }
        if (!personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
        personal_data_hosts::require_allowed_location($location, $path);
    }

    /**
     * A pending retry may replace an existing file only with an explicit checksum.
     *
     * @param string $path Relative context path.
     * @param string $pendingentry Pending retry identifier.
     * @param bool $exists Whether the preflight found a target.
     * @param string $expectedcontenthash Explicit client checksum.
     */
    private static function guard_pending_retry(
        string $path,
        string $pendingentry,
        bool $exists,
        string $expectedcontenthash
    ): void {
        if ($pendingentry !== '' && $exists && $expectedcontenthash === '') {
            throw new storage_conflict_exception($path);
        }
    }

    /**
     * Enforces the capability applicable to the selected location.
     *
     * @param pointer_location $location Selected location.
     */
    private static function require_write_access(pointer_location $location): void {
        if ($location->kind === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }
    }

    /**
     * Persists either operation and records storage outages once on the domain side.
     * Caller errors and conflicts keep their original codes and create no pending note.
     *
     * @param storage_port $port Selected adapter.
     * @param pointer_location $location Selected location.
     * @param string $path Relative context path.
     * @param string $content Content to write or append.
     * @param string $operation Pending operation category.
     * @param int $courseid Course ID for pending writes.
     * @param string $expectedchecksum Authorised preflight state.
     * @return array{path: string, created: bool, size: int, checksum: string}
     */
    private static function persist(
        storage_port $port,
        pointer_location $location,
        string $path,
        string $content,
        string $operation,
        int $courseid,
        string $expectedchecksum
    ): array {
        try {
            return $operation === pending_write_translation::OP_APPEND
                ? $port->append(context_files::area(), $path, $content, $expectedchecksum)
                : $port->write(context_files::area(), $path, $content, $expectedchecksum);
        } catch (storage_conflict_exception $e) {
            throw $e;
        } catch (\moodle_exception $e) {
            $area = context_files::area();
            if (in_array($e->errorcode, [$area->invalidpathkey, $area->quotaerrorkey, 'contextfilenotmarkdown'], true)) {
                throw $e;
            }
            throw pointer_writer::record_storage_failure($e, $location, $path, $operation, $courseid);
        } catch (\Throwable $e) {
            throw pointer_writer::record_storage_failure($e, $location, $path, $operation, $courseid);
        }
    }

    /**
     * Rejects an existing personal-data-marked target when the #344 switch
     * is off. Shared by {@see write()} and {@see append()} at both locations.
     *
     * @param ?array $existing Result of {@see private_files_storage_port::read()}. Type: array{content:string}|null.
     * @param string $path
     * @throws \moodle_exception contextfilelocked
     */
    private static function guard_existing_locked(?array $existing, string $path): void {
        if ($existing !== null && !personal_data::allowed() && personal_data::is_marked($existing['content'])) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
    }

    /**
     * Appends to a context file independently of its location; see {@see write()}.
     *
     * @param string $path
     * @param string $content
     * @param string $expectedcontenthash See {@see context_files::append_pointer_aware()}.
     * @param string $pendingentry See {@see context_files::append_pointer_aware()}.
     * @param int $courseid See {@see context_files::append_pointer_aware()}.
     * @return array{path: string, created: bool, size: int}
     */
    public static function append(
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $pendingentry = '',
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        try {
            $location = storage_anchor::effective_location($area);
        } catch (\moodle_exception $e) {
            if ($e->errorcode !== 'webdaviservfilesonly') {
                throw $e;
            }
            throw pointer_writer::record_location_failure($e, $path, null, pointer_writer::OP_APPEND, $courseid);
        }
        return self::append_to(
            storage_anchor::port_at($location),
            $location,
            $path,
            $content,
            $expectedcontenthash,
            $pendingentry,
            $courseid
        );
    }

    /**
     * Appends through an already selected port, retaining the authorised preflight state.
     *
     * @param storage_port $port Selected storage adapter.
     * @param pointer_location $location Selected location for policy and failure descriptions.
     * @param string $path Relative context path.
     * @param string $content Content to append.
     * @param string $expectedcontenthash Expected preflight checksum.
     * @param string $pendingentry Pending retry identifier.
     * @param int $courseid Course ID for pending writes.
     * @return array{path: string, created: bool, size: int}
     */
    public static function append_to(
        storage_port $port,
        pointer_location $location,
        string $path,
        string $content,
        string $expectedcontenthash = '',
        string $pendingentry = '',
        int $courseid = 0
    ): array {
        $area = context_files::area();
        storage_anchor::writable_segments($area, $path);
        self::require_write_access($location);
        $existing = self::preread($port, $location, $path, pointer_writer::OP_APPEND, $courseid);
        self::guard_existing_locked($existing, $path);
        $finalcontent = ($existing['content'] ?? '') . $content;
        self::guard_new_content($location, $path, $finalcontent);
        if ($expectedcontenthash !== '' && ($existing === null || $existing['checksum'] !== $expectedcontenthash)) {
            throw new storage_conflict_exception($path);
        }
        self::guard_pending_retry($path, $pendingentry, $existing !== null, $expectedcontenthash);
        // Keep the authorised preflight state through the adapter's conditional write,
        // even when the caller supplied no checksum (personal-data guard).
        $condition = $existing['checksum'] ?? storage_port::MISSING_CHECKSUM;
        $written = self::persist(
            $port,
            $location,
            $path,
            $content,
            pending_write_translation::OP_APPEND,
            $courseid,
            $condition
        );
        return ['path' => $written['path'], 'created' => $written['created'], 'size' => $written['size']];
    }
}
