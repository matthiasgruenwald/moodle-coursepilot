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

use local_coursepilot\webdav\webdav_setup_steps;

/**
 * Storage contract adapter (Spec 0021): Moodle Private
 * Files. Implements {@see storage_port}; owns file persistence, quota and
 * safe replacement. {@see storage_anchor} resolves locations and paths.
 * Provides shared file operations behind the location-neutral interface;
 * the context and material facades delegate their persistence here.
 *
 * Does not resolve context pointers: supplied areas have no
 * {@see storage_area::$pointerkey}, so {@see storage_anchor::root()} never
 * branches into the previous pointer resolution path. Adapter selection is
 * handled elsewhere, not by this adapter. Its checksum is Moodle contenthash.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class private_files_storage_port implements storage_port {
    /** @var string Temporary file prefix for safe content replacement. */
    public const TEMP_PREFIX = '.coursepilot-new-';

    /**
     * Creates the private files storage port.
     *
     * @param pointer_location|null $location Already resolved Moodle location.
     *        Null is allowed for contract tests without a pointer.
     */
    public function __construct(
        /** @var ?pointer_location Already resolved Moodle location. */
        private readonly ?pointer_location $location = null,
    ) {
    }

    /**
     * Reads the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function read(storage_area $area, string $path): ?array {
        [$directory, $filename] = $this->resolve_file($area, $path);
        $found = self::read_content($directory, $filename);
        if ($found === null) {
            return null;
        }
        return [
            'content' => $found['content'],
            'checksum' => $found['contenthash'],
            'size' => $found['size'],
            'mimetype' => $found['mimetype'],
            'timemodified' => $found['timemodified'],
        ];
    }

    /**
     * Lists the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function list(storage_area $area, string $path): array {
        $directory = $this->resolve_directory($area, $path);
        $entries = [];
        foreach (self::list_entries($directory) as $entry) {
            $entries[] = [
                'name' => $entry['name'],
                'type' => $entry['type'],
                'size' => $entry['size'],
                'mimetype' => $entry['mimetype'],
                'checksum' => $entry['contenthash'],
                'timemodified' => $entry['timemodified'],
            ];
        }
        return $entries;
    }

    /**
     * Writes the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @param string $content The content.
     * @param ?string $expectedchecksum The expectedchecksum.
     * @param string $source Optional serialised provenance; supported by Private Files only.
     */
    public function write(
        storage_area $area,
        string $path,
        string $content,
        ?string $expectedchecksum = null,
        string $source = ''
    ): array {
        [$directory, $filename] = $this->resolve_writable_file($area, $path);
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        $existing = self::read_content($directory, $filename);
        $this->require_checksum_match($existing, $expectedchecksum, $clientpath);

        $oldsize = $existing['size'] ?? 0;
        self::require_quota($area, strlen($content) - $oldsize);
        if ($expectedchecksum === storage_port::MISSING_CHECKSUM) {
            // A missing preflight must stay create-only through persistence.
            // self::write_content() rereads and may replace a concurrent file.
            $record = self::filerecord(storage_anchor::own_context()->id, $directory, $filename) + ['source' => $source];
            try {
                get_file_storage()->create_file_from_string($record, $content);
            } catch (\stored_file_creation_exception $e) {
                if (self::read_content($directory, $filename) !== null) {
                    throw new storage_conflict_exception($clientpath);
                }
                throw $e;
            }
        } else {
            self::write_content($directory, $filename, $content, ['source' => $source]);
        }

        $written = self::read_content($directory, $filename);
        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $written['size'],
            'checksum' => $written['contenthash'],
        ];
    }

    /**
     * Appends the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @param string $content The content.
     * @param ?string $expectedchecksum The expectedchecksum.
     */
    public function append(storage_area $area, string $path, string $content, ?string $expectedchecksum = null): array {
        [$directory, $filename] = $this->resolve_writable_file($area, $path);
        $clientpath = storage_anchor::normalise_client_path($area, $path);
        $existing = self::read_content($directory, $filename);

        $this->require_checksum_match($existing, $expectedchecksum, $clientpath);
        self::require_quota($area, strlen($content));
        $size = self::append_content($directory, $filename, $content);

        $written = self::read_content($directory, $filename);
        return [
            'path' => $clientpath,
            'created' => $existing === null,
            'size' => $size,
            'checksum' => $written['contenthash'],
        ];
    }

    /**
     * Deletes the private files storage port.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     */
    public function delete(storage_area $area, string $path): bool {
        [$directory, $filename] = $this->resolve_file($area, $path);
        return self::delete_file($directory, $filename);
    }

    /**
     * Resolves file.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @return array{0: string, 1: string}
     */
    private function resolve_file(storage_area $area, string $path): array {
        $normalised = storage_anchor::normalise_client_path($area, $path);
        if ($normalised === '') {
            throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
        }
        $segments = explode('/', $normalised);
        $filename = array_pop($segments);
        return [$this->resolve_directory($area, implode('/', $segments)), $filename];
    }

    /**
     * Resolves writable file.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @return array{0: string, 1: string}
     */
    private function resolve_writable_file(storage_area $area, string $path): array {
        [$folders, $filename] = storage_anchor::writable_segments($area, $path);
        return [$this->resolve_directory($area, implode('/', $folders)), $filename];
    }

    /**
     * Resolves directory.
     *
     * @param storage_area $area The area.
     * @param string $path The path.
     * @return string
     */
    private function resolve_directory(storage_area $area, string $path): string {
        $relative = storage_anchor::normalise_client_path($area, $path);
        $root = $this->location === null
            ? storage_anchor::resolve_directory($area, '')
            : rtrim((string) $this->location->path, '/') . '/';
        return $relative === '' ? $root : $root . $relative . '/';
    }

    /**
     * Rejects a conditional write whose checksum no longer matches current
     * content, including a now missing file. A null checksum skips comparison
     * and keeps unconditional overwrite/create behaviour.
     *
     * @param ?array $existing Stored file data (content, mimetype, size, contenthash,
     *        timemodified) or null.
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
        $currentchecksum = $existing['contenthash'] ?? null;
        if ($currentchecksum !== $expectedchecksum) {
            throw new storage_conflict_exception($clientpath);
        }
    }

    /**
     * Standard own-file capability for every write endpoint regardless of
     * area: both use Moodle Private Files and its permissions.
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_own_files(): void {
        require_capability('moodle/user:manageownfiles', storage_anchor::own_context());
    }

    /**
     * Remaining user quota in bytes. file_storage does not itself enforce
     * $CFG->userquota, unlike core UI. Independent of the area root: quota
     * counts all user storage, not one subfolder.
     *
     * @return int|null Remaining bytes, or null if no limit applies
     *         (quota disabled, unlimited or moodle/user:ignoreuserquota).
     */
    public static function remaining_quota(): ?int {
        global $CFG;

        $quota = (int) ($CFG->userquota ?? 0);
        if ($quota <= 0 || has_capability('moodle/user:ignoreuserquota', storage_anchor::own_context())) {
            return null;
        }
        return max(0, $quota - (int) file_get_user_used_space());
    }

    /**
     * Rejects writes that would exceed the user quota.
     *
     * @param storage_area $area
     * @param int $additionalbytes Increase over the current size.
     * @throws \moodle_exception area quotaerrorkey
     */
    public static function require_quota(storage_area $area, int $additionalbytes): void {
        $remaining = self::remaining_quota();
        if ($remaining === null || $additionalbytes <= $remaining) {
            return;
        }
        // Deliberate shortcut - ponytail: include page for every area even though materialquotaexceeded
        // does not currently use it. A per-area branch would cost more code than
        // the unused key (get_string ignores it). Split only when another area
        // must explicitly omit the page.
        throw new \moodle_exception($area->quotaerrorkey, 'local_coursepilot', '', (object) [
            'remaining' => format_float($remaining / 1048576, 1),
            'needed' => format_float($additionalbytes / 1048576, 1),
            'page' => webdav_setup_steps::LOCATION_SELECTION_PAGE,
        ]);
    }

    /**
     * Moodle file record for a file in an area.
     *
     * @param int $contextid
     * @param string $directory
     * @param string $filename
     * @return mixed[]
     */
    public static function filerecord(int $contextid, string $directory, string $filename): array {
        return [
            'contextid' => $contextid,
            'component' => storage_anchor::COMPONENT,
            'filearea' => storage_anchor::FILEAREA,
            'itemid' => storage_anchor::ITEMID,
            'filepath' => $directory,
            'filename' => $filename,
        ];
    }

    /**
     * Moodle file behind directory/filename, or null. Shared lookup for
     * {@see read_content()}, {@see write_content()} and {@see append_content()}.
     *
     * @param string $directory
     * @param string $filename
     * @return \stored_file|null Never a directory placeholder.
     */
    private static function find_file(string $directory, string $filename): ?\stored_file {
        $file = get_file_storage()->get_file(
            storage_anchor::own_context()->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            $directory,
            $filename
        );
        return ($file && !$file->is_directory()) ? $file : null;
    }

    /**
     * Recursively lists files without folders for
     * {@see \local_coursepilot\external\report_loose_material_files}, which
     * needs every file regardless of nesting. Uses timecreated rather than
     * timemodified (age since creation), plus full directories so callers
     * can derive client paths through {@see storage_anchor::relative_file()}. The pointer
     * is not filtered here (unchanged legacy behavior): it can only live
     * in the anchor root, outside material cleanup's searched area.
     *
     * @param string $directory Result of {@see storage_anchor::resolve_directory()}.
     * @return array<int, array{directory: string, name: string, size: int,
     *         contenthash: string, timecreated: int}>
     */
    public static function list_entries_recursive(string $directory): array {
        $entries = [];
        foreach (self::directory_files($directory, true, false) as $file) {
            $entries[] = [
                'directory' => $file->get_filepath(),
                'name' => $file->get_filename(),
                'size' => (int) $file->get_filesize(),
                'contenthash' => $file->get_contenthash(),
                'timecreated' => (int) $file->get_timecreated(),
            ];
        }
        return $entries;
    }

    /**
     * Shared get_directory_files() for {@see list_entries()} and
     * {@see list_entries_recursive()}. Only recursive,
     * includedirs and the result shape differ.
     *
     * @param string $directory
     * @param bool $recursive
     * @param bool $includedirs
     * @return \stored_file[]
     */
    private static function directory_files(string $directory, bool $recursive, bool $includedirs): array {
        return get_file_storage()->get_directory_files(
            storage_anchor::own_context()->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            $directory,
            $recursive,
            $includedirs,
            'filepath, filename'
        );
    }

    /**
     * Lists entries.
     *
     * @param string $directory The directory.
     * @return mixed[]
     */
    public static function list_entries(string $directory): array {
        $entries = [];
        foreach (self::directory_files($directory, false, true) as $file) {
            if (
                !$file->is_directory()
                    && in_array($file->get_filename(), [storage_anchor::POINTER_FILENAME, storage_anchor::PENDING_FILENAME], true)
            ) {
                continue;
            }
            if ($file->is_directory()) {
                // Note: get_directory_files() excludes the requested directory's own
                // placeholder (:dirid); only immediate subfolders appear here.
                $entries[] = [
                    'name' => trim(substr($file->get_filepath(), strlen($directory)), '/'),
                    'type' => 'folder',
                    'size' => 0,
                    'mimetype' => '',
                    'contenthash' => '',
                    'timemodified' => 0,
                ];
                continue;
            }
            $entries[] = [
                'name' => $file->get_filename(),
                'type' => 'file',
                'size' => (int) $file->get_filesize(),
                'mimetype' => (string) $file->get_mimetype(),
                'contenthash' => $file->get_contenthash(),
                'timemodified' => (int) $file->get_timemodified(),
            ];
        }
        return $entries;
    }

    /**
     * Reads location-independent file values, never returns a Moodle file object.
     *
     * @param string $directory Result of {@see storage_anchor::resolve_directory()}.
     * @param string $filename
     * @return array{content: string, mimetype: string, size: int, contenthash: string,
     *         timemodified: int}|null null for missing files or directory placeholders.
     */
    public static function read_content(string $directory, string $filename): ?array {
        $file = self::find_file($directory, $filename);
        if (!$file) {
            return null;
        }
        return [
            'content' => $file->get_content(),
            'mimetype' => (string) $file->get_mimetype(),
            'size' => (int) $file->get_filesize(),
            'contenthash' => $file->get_contenthash(),
            'timemodified' => (int) $file->get_timemodified(),
        ];
    }

    /**
     * Creates/replaces full file content. Callers validate path, extension,
     * size, personal-data policy, concurrency and quota using
     * {@see read_content()} before writing. This method only persists the write.
     *
     * @param string $directory Result of {@see storage_anchor::resolve_directory()}.
     * @param string $filename
     * @param string $content Complete new content.
     * @param mixed[] $recordoverrides Additional/overriding file-record fields
     *        e.g. the source field for an image crop
     *        ({@see \local_coursepilot\external\crop_material_file}).
     *        Empty leaves the ordinary {@see filerecord()} record
     *        unchanged.
     */
    public static function write_content(string $directory, string $filename, string $content, array $recordoverrides = []): void {
        $contextid = storage_anchor::own_context()->id;
        $existing = self::find_file($directory, $filename);
        $filerecord = array_merge(self::filerecord($contextid, $directory, $filename), $recordoverrides);
        self::replace($existing, $filerecord, $content);
    }

    /**
     * Deletes a file if present. Callers validate capability and existence
     * of every requested path through {@see read_content()} before deleting.
     *
     * @param string $directory Result of {@see storage_anchor::resolve_directory()}.
     * @param string $filename
     * @return bool true if deleted; false if no file existed.
     */
    public static function delete_file(string $directory, string $filename): bool {
        $file = self::find_file($directory, $filename);
        if (!$file) {
            return false;
        }
        $file->delete();
        return true;
    }

    /**
     * Appends content, creating the file if absent. Like {@see write_content()},
     * validation belongs to callers. Rereads stored content to concatenate it:
     * the caller received values, not a reusable stored_file. Returns the
     * actual persisted total size, not an estimate from the earlier read;
     * concurrent changes can differ (Spec 0016 §5.3 excludes locks).
     *
     * @param string $directory Result of {@see storage_anchor::resolve_directory()}.
     * @param string $filename
     * @param string $content Content to append.
     * @return int Total file size after appending, in bytes.
     */
    public static function append_content(string $directory, string $filename, string $content): int {
        $contextid = storage_anchor::own_context()->id;
        $existing = self::find_file($directory, $filename);
        $newcontent = $existing ? $existing->get_content() . $content : $content;
        self::replace($existing, self::filerecord($contextid, $directory, $filename), $newcontent);
        return strlen($newcontent);
    }

    /**
     * Shared content replacement for all write endpoints.
     *
     * Put new bytes into the file pool under a temporary name before deleting
     * the old reference. Delete-then-create cannot be safely rolled back:
     * stored_file::delete() physically removes the last referenced blob,
     * while a DB transaction restores only its row, leaving a missing blob
     * and a lost teacher file without a successful overwrite.
     *
     * If interrupted between deletion and rename, the complete new content
     * remains visible under the temporary name in Private Files. Untidy,
     * but preserves the content.
     *
     * @param \stored_file|null $existing Existing file, if present.
     * @param mixed[] $filerecord Target from {@see filerecord()}.
     * @param string $content Complete new content.
     */
    public static function replace(?\stored_file $existing, array $filerecord, string $content): void {
        $fs = get_file_storage();
        if (!$existing) {
            $fs->create_file_from_string($filerecord, $content);
            return;
        }

        $temprecord = $filerecord;
        $temprecord['filename'] = self::TEMP_PREFIX . uniqid() . '-' . $filerecord['filename'];
        $new = $fs->create_file_from_string($temprecord, $content);
        $existing->delete();
        $new->rename($filerecord['filepath'], $filerecord['filename']);
    }
}
