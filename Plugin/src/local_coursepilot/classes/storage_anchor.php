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
 * Shared storage anchor (#444, storage specification #442 §1/§4/§5):
 * centralizes component/filearea/itemid, own user context, root/segment
 * validation, path conversion, capabilities, quota and file records, plus
 * temporary-file write sequencing formerly duplicated in
 * {@see context_files} and {@see material_files}.
 *
 * A {@see storage_area} is a value record, not a type. The original
 * Private-Files-only design required no separate adapter interface
 * (ADR #444). Area facades retain their existing public interfaces and
 * delegate shared operations here.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class storage_anchor {
    /** @var string Moodle file component: Private Files. */
    public const COMPONENT = 'user';

    /** @var string The only file area accessible to the model. */
    public const FILEAREA = 'private';

    /** @var int Fixed item ID; no area has other items. */
    public const ITEMID = 0;

    /** @var string Temporary-file prefix in {@see replace()}. */
    public const TEMP_PREFIX = '.coursepilot-new-';

    /**
     * @var string Anchor setting name (#445): the only location that cannot be
     *      overridden by a context pointer, to avoid circular
     *      resolution. Matches the context-area root setting:
     *      {@see context_files} is literally the anchor,
     *      rather than merely sharing its name.
     */
    public const ANCHOR_ROOTSETTING = 'contextroot';

    /** @var string Default anchor root when the setting is empty. */
    public const ANCHOR_DEFAULT_ROOT = 'coursepilot';

    /**
     * @var string Pointer filename in the anchor folder (#445,
     *      storage spec #442 §2). Leading dot and
     *      .json extension exclude it from the context-area .md rule
     *      and material extension allowlist;
     *      neither tool can overwrite it. The original design
     *      created it manually through Private Files only.
     */
    public const POINTER_FILENAME = '.coursepilot-location.json';

    /**
     * @var string Pending-note filename in the anchor folder (#492,
     *      ADR 0023, Spec #486 §8/§10), beside the pointer.
     *      For the same reason, leading dot and .json extension
     *      exclude it from the context-area .md rule and
     *      listing ({@see list_entries()}).
     */
    public const PENDING_FILENAME = '.coursepilot-pending.json';

    /**
     * The logged-in person's own context, never derived from client inputs.
     *
     * @return \context_user
     */
    public static function own_context(): \context_user {
        global $USER;
        return \context_user::instance($USER->id);
    }

    /**
     * Resolves an area root in two steps (#445, storage spec #442 §2): first
     * the configured default, then the actual target from a pointer in the
     * fixed anchor when the area has {@see storage_area::$pointerkey}.
     * Without a pointer, the configured root applies as before.
     *
     * @param storage_area $area
     * @return string Always with leading and trailing "/".
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable -
     *         never silently fall back to the default when a
     *         pointer exists but is invalid (see {@see resolve_pointer_location()}).
     */
    private static function root(storage_area $area): string {
        $configured = self::configured_root($area->rootsetting, $area->defaultroot);
        $location = self::resolve_pointer_location($area);
        if ($location === null) {
            return $configured;
        }
        if ($location->kind === pointer_location::EXTERNAL) {
            if ($area->externalfallback) {
                // The workbench (#520, Spec #486 §1) stays at the default anchor root
                // when material storage is external. No error: the pointer does not
                // describe the workbench as its target.
                return $configured;
            }
            // This caller does not support external storage (#490 originally
            // provided reads only). Falling back silently would create a second,
            // partial area, so return a named error instead.
            throw new \moodle_exception(
                'pointerexternalnotsupported',
                'local_coursepilot',
                '',
                webdav_setup_steps::LOCATION_SELECTION_PAGE
            );
        }
        return $location->path;
    }

    /**
     * Resolves an area pointer without network access (#490, Spec #486 §2),
     * reading raw data from the anchor and interpreting it through
     * {@see context_pointer}. null means open/no pointer: use the default root.
     *
     * @param storage_area $area
     * @return pointer_location|null
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable
     */
    public static function resolve_pointer_location(storage_area $area): ?pointer_location {
        if ($area->pointerkey === null) {
            return null;
        }
        $decoded = self::raw_pointer();
        if ($decoded === null) {
            return null;
        }
        return context_pointer::resolve_target($decoded, $area->pointerkey);
    }

    /**
     * Reads the fixed anchor's pointer file and decodes its JSON object.
     * File I/O only; {@see context_pointer} interprets generations and fields.
     *
     * @return array|null null without a pointer file (open).
     * @throws \moodle_exception pointerunreadable
     */
    private static function raw_pointer(): ?array {
        global $USER;

        // Without a logged-in person there are no owned Private Files containing
        // a pointer. Pure resolution (tests without setUser()) stays DB-free
        // as before #445: default root, without own_context() access.
        if (empty($USER->id)) {
            return null;
        }

        $anchor = self::configured_root(self::ANCHOR_ROOTSETTING, self::ANCHOR_DEFAULT_ROOT);
        $file = get_file_storage()->get_file(
            self::own_context()->id,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
            $anchor,
            self::POINTER_FILENAME
        );
        if (!$file) {
            return null;
        }

        $decoded = json_decode($file->get_content(), true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || array_is_list($decoded)) {
            throw new \moodle_exception('pointerunreadable', 'local_coursepilot', '', self::POINTER_FILENAME);
        }
        return context_pointer::normalise($decoded);
    }

    /**
     * Resolved area location, never null (#495). Like
     * {@see resolve_pointer_location()}, but an open/no-pointer state becomes
     * {@see pointer_location::moodle()} at the configured root. Callers building
     * {@see pointer_location::comparison_key()} always need a concrete place.
     *
     * @param storage_area $area
     * @return pointer_location
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable/
     *         materialstoreincontext
     */
    public static function effective_location(storage_area $area): pointer_location {
        $location = self::resolve_pointer_location($area);
        if ($location === null) {
            return pointer_location::moodle(self::configured_root($area->rootsetting, $area->defaultroot));
        }
        if ($location->kind === pointer_location::EXTERNAL && $area->externalfallback) {
            // Workbench (#520) stays at its default Moodle anchor when material
            // is external, matching {@see root()} so the reported location cannot
            // differ from the actual directory.
            return pointer_location::moodle(self::configured_root($area->rootsetting, $area->defaultroot));
        }
        return $location;
    }

    /**
     * Single location dispatcher for tool paths. Only this layer reads the
     * pointer; tools and area facades use the returned port.
     *
     * @param storage_area $area The area.
     * @param int $courseid The courseid.
     */
    public static function port(storage_area $area, int $courseid = 0): storage_port {
        return self::port_at(self::effective_location($area), $courseid);
    }

    /**
     * The adapter for an already resolved location - only for the read-only
     * previous location ({@see previous_location}), whose location comes from
     * the pointer history instead of the current pointer.
     *
     * @param pointer_location $location The location.
     * @param int $courseid The courseid.
     */
    public static function port_at(pointer_location $location, int $courseid = 0): storage_port {
        if ($location->kind === pointer_location::MOODLE) {
            return new private_files_storage_port($location);
        }
        return new webdav_storage_port((int) $location->instanceid, (string) $location->relativepath, null, $location, $courseid);
    }

    /**
     * Configured default root without pointer resolution. Used both for
     * area defaults and the fixed anchor itself (#445).
     *
     * @param string $settingname
     * @param string $defaultvalue
     * @return string Always with leading and trailing "/".
     */
    private static function configured_root(string $settingname, string $defaultvalue): string {
        $configured = trim((string) (get_config('local_coursepilot', $settingname) ?: $defaultvalue), '/');
        return $configured === '' ? '/' : '/' . $configured . '/';
    }

    /**
     * Configured area root without leading/trailing slashes, for location
     * selection (#494): the path written to the pointer when choosing Moodle.
     *
     * @param storage_area $area
     * @return string
     */
    public static function default_root(storage_area $area): string {
        return trim(self::configured_root($area->rootsetting, $area->defaultroot), '/');
    }

    /**
     * Public raw-pointer read (#494): location selection needs the complete
     * document, including history, rather than only one resolved target.
     *
     * @return array|null null without a pointer file.
     * @throws \moodle_exception pointerunreadable
     */
    public static function read_raw_pointer(): ?array {
        return self::raw_pointer();
    }

    /**
     * Writes a complete pointer (#494), exclusively through deliberate
     * location selection on its Moodle profile page
     * ({@see \local_coursepilot\location_selection}, Spec #442 §3). Never from
     * consent or chat (CONTEXT.md, #476), and no Coursepilot tool calls it.
     * Moves no files, only replaces the small pointer with the usual
     * temporary-file choreography in {@see replace()}.
     *
     * @param array $document Complete pointer document (context_area,
     *        material_store, location_history).
     */
    public static function write_pointer_document(array $document): void {
        $content = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::write_pointer_file($content);
    }

    /**
     * Saves confirmed location selection as a complete pointer document.
     * Page logic supplies values, never bypasses the anchor for file writes.
     *
     * @param array[] $locations
     * @param array[] $history
     * @param array|null $previouslocation
     */
    public static function save_location_selection(array $locations, array $history, ?array $previouslocation): void {
        $document = [
            'context_area' => $locations['context_area'],
            'material_store' => $locations['material_store'],
            'location_history' => $history,
        ];
        if ($previouslocation !== null) {
            $document['previous_location'] = $previouslocation;
        }
        self::write_pointer_document($document);
    }

    /**
     * The fixed anchor directory itself, containing the pointer and pending
     * note directly rather than an area beneath it.
     *
     * @return string Always with leading and trailing "/".
     */
    public static function anchor_root(): string {
        return self::configured_root(self::ANCHOR_ROOTSETTING, self::ANCHOR_DEFAULT_ROOT);
    }

    /**
     * Single pointer-file write used by {@see write_pointer_document()}.
     *
     * @param string $content Already encoded JSON content.
     */
    private static function write_pointer_file(string $content): void {
        $anchor = self::configured_root(self::ANCHOR_ROOTSETTING, self::ANCHOR_DEFAULT_ROOT);
        $contextid = self::own_context()->id;
        $existing = get_file_storage()->get_file(
            $contextid,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
            $anchor,
            self::POINTER_FILENAME
        );
        self::replace($existing ?: null, self::filerecord($contextid, $anchor, self::POINTER_FILENAME), $content);
    }

    /**
     * Splits client paths into clean segments and rejects . and .., enforcing
     * the no-escape boundary directly in plugin code.
     *
     * @param storage_area $area
     * @param string $path
     * @return string[]
     */
    private static function segments(storage_area $area, string $path): array {
        $normalised = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $normalised) as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($segment === '.' || $segment === '..') {
                throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
            }
            $segments[] = $segment;
        }
        return $segments;
    }

    /**
     * Resolves an optional client subfolder to a full Moodle path inside the area.
     *
     * @param storage_area $area
     * @param string $path Relative subfolder, e.g. "" or "subjects/math".
     * @return string Always with leading and trailing "/".
     */
    public static function resolve_directory(storage_area $area, string $path): string {
        $segments = self::segments($area, $path);
        return rtrim(self::root($area) . implode('/', $segments), '/') . '/';
    }

    /**
     * Client path of a resolved directory, relative to the root in the same
     * notation tools accept. The root itself is the empty path.
     *
     * @param storage_area $area
     * @param string $directory Result of {@see resolve_directory()}
     * @return string
     */
    public static function relative_directory(storage_area $area, string $directory): string {
        return trim(substr($directory, strlen(self::root($area))), '/');
    }

    /**
     * Client file path: like {@see relative_directory()} plus its filename.
     * A root-level file is simply its filename.
     *
     * @param storage_area $area
     * @param string $directory Result of {@see resolve_directory()}
     * @param string $filename
     * @return string
     */
    public static function relative_file(storage_area $area, string $directory, string $filename): string {
        $relative = self::relative_directory($area, $directory);
        return $relative === '' ? $filename : $relative . '/' . $filename;
    }

    /**
     * Resolves a client file path (directory and filename).
     *
     * @param storage_area $area
     * @param string $path For example "templates.md" or "subjects/math/note.md".
     * @return array{0: string, 1: string} [Directory path, filename]
     */
    public static function resolve_file(storage_area $area, string $path): array {
        $segments = self::segments($area, $path);
        if (empty($segments)) {
            throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
        }
        $filename = array_pop($segments);
        return [self::resolve_directory($area, implode('/', $segments)), $filename];
    }

    /**
     * Like {@see resolve_file()} with stricter write rules: folder segments
     * use [A-Za-z0-9_-], filenames use {@see storage_area::$checkwritablename},
     * the area-specific policy. Reads intentionally remain permissive for
     * legacy/manual files that Coursepilot would not create itself.
     *
     * @param storage_area $area
     * @param string $path For example "plan.md" or "subjects/math/profile.md".
     * @return array{0: string, 1: string} [Directory path, filename]
     * @throws \moodle_exception area invalidpathkey or area-specific filename error
     */
    public static function resolve_writable_file(storage_area $area, string $path): array {
        [$folders, $filename] = self::writable_segments($area, $path);
        return [self::resolve_directory($area, implode('/', $folders)), $filename];
    }

    /**
     * Write validation from {@see resolve_writable_file()} without root
     * resolution, for external writes (#491) needing no Moodle directory.
     * Avoids {@see root()}, which rejects external targets. Folder segments
     * use [A-Za-z0-9_-]; filenames use {@see storage_area::$checkwritablename}.
     *
     * @param storage_area $area
     * @param string $path For example "plan.md" or "subjects/math/profile.md".
     * @return array{0: string[], 1: string} [Folder segments, filename]
     * @throws \moodle_exception area invalidpathkey or area-specific filename error
     */
    public static function writable_segments(storage_area $area, string $path): array {
        $segments = self::segments($area, $path);
        if (empty($segments)) {
            throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
        }
        $filename = array_pop($segments);
        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                throw new \moodle_exception($area->invalidpathkey, 'local_coursepilot');
            }
        }
        ($area->checkwritablename)($filename);
        return [$segments, $filename];
    }

    /**
     * Full relative path in an owned WebDAV instance: selected pointer folder
     * ({@see pointer_location::$relativepath}) plus requested client subpath,
     * both segment-validated. {@see pointer_reader}/{@see pointer_writer}
     * share it so reads and writes address the same resource.
     *
     * @param storage_area $area
     * @param pointer_location $location
     * @param string $path
     * @return string
     */
    public static function external_relative_path(storage_area $area, pointer_location $location, string $path): string {
        $base = trim((string) $location->relativepath, '/');
        $extra = self::normalise_client_path($area, $path);
        if ($base === '') {
            return $extra;
        }
        return $extra === '' ? $base : $base . '/' . $extra;
    }

    /**
     * Standard own-file capability for every write endpoint regardless of
     * area: both use Moodle Private Files and its permissions.
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_own_files(): void {
        require_capability('moodle/user:manageownfiles', self::own_context());
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
        if ($quota <= 0 || has_capability('moodle/user:ignoreuserquota', self::own_context())) {
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
     * @return array
     */
    public static function filerecord(int $contextid, string $directory, string $filename): array {
        return [
            'contextid' => $contextid,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => self::ITEMID,
            'filepath' => $directory,
            'filename' => $filename,
        ];
    }

    /**
     * Lists one level as location-independent values (#487): name, type, size,
     * MIME type, contenthash and modification time. No stored_file leaves
     * this layer. The pointer is not a working file and stays excluded.
     *
     * No personal-data locked flag: that is context-area policy (ADR 0011),
     * not anchor policy. Callers can use {@see read_content()} to inspect it.
     *
     * @param string $directory Result of {@see resolve_directory()}.
     * @return array<int, array{name: string, type: string, size: int, mimetype: string,
     *         contenthash: string, timemodified: int}>
     */
    /**
     * Moodle file behind directory/filename, or null. Shared lookup for
     * {@see read_content()}, {@see write()} and {@see append()}.
     *
     * @param string $directory
     * @param string $filename
     * @return \stored_file|null Never a directory placeholder.
     */
    private static function find_file(string $directory, string $filename): ?\stored_file {
        $file = get_file_storage()->get_file(
            self::own_context()->id,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
            $directory,
            $filename
        );
        return ($file && !$file->is_directory()) ? $file : null;
    }

    /**
     * Recursively lists files without folders (#488) for
     * {@see \local_coursepilot\external\report_loose_material_files}, which
     * needs every file regardless of nesting. Uses timecreated rather than
     * timemodified (age since creation), plus full directories so callers
     * can derive client paths through {@see relative_file()}. The pointer
     * is not filtered here (unchanged legacy behavior): it can only live
     * in the anchor root, outside material cleanup's searched area.
     *
     * @param string $directory Result of {@see resolve_directory()}.
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
     * {@see list_entries_recursive()} (#488 standards review). Only recursive,
     * includedirs and the result shape differ.
     *
     * @param string $directory
     * @param bool $recursive
     * @param bool $includedirs
     * @return \stored_file[]
     */
    private static function directory_files(string $directory, bool $recursive, bool $includedirs): array {
        return get_file_storage()->get_directory_files(
            self::own_context()->id,
            self::COMPONENT,
            self::FILEAREA,
            self::ITEMID,
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
     * @return array
     */
    public static function list_entries(string $directory): array {
        $entries = [];
        foreach (self::directory_files($directory, false, true) as $file) {
            if (
                !$file->is_directory()
                    && ($file->get_filename() === self::POINTER_FILENAME || $file->get_filename() === self::PENDING_FILENAME)
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
                'mimetype' => (string) ($file->get_mimetype() ?? ''),
                'contenthash' => $file->get_contenthash(),
                'timemodified' => (int) $file->get_timemodified(),
            ];
        }
        return $entries;
    }

    /**
     * Reads location-independent file values (#487), never returns a Moodle file object.
     *
     * @param string $directory Result of {@see resolve_directory()}.
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
            'mimetype' => (string) ($file->get_mimetype() ?? ''),
            'size' => (int) $file->get_filesize(),
            'contenthash' => $file->get_contenthash(),
            'timemodified' => (int) $file->get_timemodified(),
        ];
    }

    /**
     * Creates/replaces full file content (#487). Callers validate path, extension,
     * size, personal-data policy, concurrency and quota using
     * {@see read_content()} before writing. This method only persists the write.
     *
     * @param string $directory Result of {@see resolve_directory()}.
     * @param string $filename
     * @param string $content Complete new content.
     * @param array $recordoverrides Additional/overriding file-record fields
     *        (#488), e.g. the source field for an image crop
     *        ({@see \local_coursepilot\external\crop_material_file}).
     *        Empty leaves the ordinary {@see filerecord()} record
     *        unchanged.
     */
    public static function write(string $directory, string $filename, string $content, array $recordoverrides = []): void {
        $contextid = self::own_context()->id;
        $existing = self::find_file($directory, $filename);
        $filerecord = array_merge(self::filerecord($contextid, $directory, $filename), $recordoverrides);
        self::replace($existing, $filerecord, $content);
    }

    /**
     * Deletes a file if present (#488). Callers validate capability and existence
     * of every requested path through {@see read_content()} before deleting.
     *
     * @param string $directory Result of {@see resolve_directory()}.
     * @param string $filename
     * @return bool true if deleted; false if no file existed.
     */
    public static function delete(string $directory, string $filename): bool {
        $file = self::find_file($directory, $filename);
        if (!$file) {
            return false;
        }
        $file->delete();
        return true;
    }

    /**
     * Appends content, creating the file if absent (#487). Like {@see write()},
     * validation belongs to callers. Rereads stored content to concatenate it:
     * the caller received values, not a reusable stored_file. Returns the
     * actual persisted total size, not an estimate from the earlier read;
     * concurrent changes can differ (Spec 0016 §5.3 excludes locks).
     *
     * @param string $directory Result of {@see resolve_directory()}.
     * @param string $filename
     * @param string $content Content to append.
     * @return int Total file size after appending, in bytes.
     */
    public static function append(string $directory, string $filename, string $content): int {
        $contextid = self::own_context()->id;
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
     * @param array $filerecord Target from {@see filerecord()}.
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

    /**
     * Validated client path (no . or ..) without binding to a root.
     * Moodle and WebDAV share this coordinate system (Spec #486 §2), so it
     * belongs here rather than in their separate pointer-aware branches.
     *
     * @param storage_area $area
     * @param string $path
     * @return string
     */
    public static function normalise_client_path(storage_area $area, string $path): string {
        return implode('/', self::segments($area, $path));
    }
}
