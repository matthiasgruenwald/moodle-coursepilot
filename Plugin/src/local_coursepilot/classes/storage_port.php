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
 * Storage contract (issue #536, Spec 0021): read, write, append, list and
 * delete per {@see storage_area} (a plain value set, ADR 0020) and relative
 * path, with a checksum for conditional writes.
 *
 * One interface for {@see private_files_storage_port} and {@see webdav_storage_port}.
 * {@see storage_anchor::port()} alone selects the adapter from the context
 * pointer. Context, material store, workbench and previous-location read-only
 * access use this contract (issue #645).
 *
 * A checksum identifies file content independently of location: Moodle
 * contenthash in Private Files, a weaker ETag/modification-time substitute
 * without atomic guarantees in WebDAV. Callers compare plain strings;
 * the value does not identify a storage location (Spec 0021 Implementation Decisions).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
interface storage_port {

    /** Internal condition for a preflight that observed no target file. */
    public const MISSING_CHECKSUM = "\0coursepilot-missing";

    /**
     * Reads file content, or null when missing or a directory.
     *
     * @param storage_area $area
     * @param string $path Client path, e.g. "plan.md" or "subjects/maths/profile.md".
     * @return array{content: string, checksum: string, size: int, mimetype: string,
     *         timemodified: int}|null
     * @throws \moodle_exception Area invalidpathkey for an invalid path.
     */
    public function read(storage_area $area, string $path): ?array;

    /**
     * Lists one area level with the same fields for both locations
     * (Spec 0021 Implementation Decisions: checksums are provided for both or neither).
     *
     * @param storage_area $area
     * @param string $path Client subfolder, e.g. "" or "subjects/maths".
     * @return array<int, array{name: string, type: string, size: int, mimetype: string,
     *         checksum: string, timemodified: int}>
     * @throws \moodle_exception Area invalidpathkey for an invalid path.
     */
    public function list(storage_area $area, string $path): array;

    /**
     * Creates a file or fully replaces its content. Path validation, quota
     * and temporary-file write sequencing belong to the adapter, not the caller
     * (Spec 0021 acceptance criterion).
     *
     * @param storage_area $area
     * @param string $path
     * @param string $content Complete new content.
     * @param string|null $expectedchecksum Checksum from an earlier read for a
     *        conditional write. Null (default) keeps unconditional overwrite/create.
     *        A supplied checksum differing from current content, including a missing
     *        file, throws {@see storage_conflict_exception}.
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception Area invalidpathkey/name error,
     *         area quotaerrorkey
     * @throws storage_conflict_exception On checksum mismatch.
     */
    public function write(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array;

    /**
     * Appends content to a file, creating it when absent.
     *
     * @param storage_area $area
     * @param string $path
     * @param string $content Content to append.
     * @param string|null $expectedchecksum Preflight condition, as for write().
     * @return array{path: string, created: bool, size: int, checksum: string}
     * @throws \moodle_exception Area invalidpathkey/name error,
     *         area quotaerrorkey
     * @throws storage_conflict_exception For read-modify-write adapters (e.g.
     *         WebDAV) when an external change occurs between reading and conditional
     *         writing back. A real, if rare, conflict rather than an area call error.
     */
    public function append(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array;

    /**
     * Deletes a file if present.
     *
     * @param storage_area $area
     * @param string $path
     * @return bool true if a file was deleted; false if none existed.
     * @throws \moodle_exception Area invalidpathkey for an invalid path.
     */
    public function delete(storage_area $area, string $path): bool;
}
