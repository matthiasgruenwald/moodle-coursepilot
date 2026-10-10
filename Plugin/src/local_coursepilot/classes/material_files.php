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

use core_external\external_value;
use local_coursepilot\webdav\webdav_error;

/**
 * Material folder anchor (Spec 0018 §2, #428). A sibling of context_files
 * in the teacher's Private files: component=user, filearea=private, itemid=0,
 * restricted to area()'s subfolder (default coursepilot-material).
 *
 * These constants hide location knowledge (Spec 0018 §2.3): endpoints obtain
 * component/filearea/itemid/root only here. A move to e.g. an attached
 * repository changes the shared storage_anchor. storage_anchor_test.php
 * proves this with a second test-defined area without endpoint test changes
 * (#444, second-location proof).
 *
 * Unlike the context area (.md only, Spec 0016 §5.1), material supports
 * allowlisted binary files (Spec 0018 §6), requiring its own filename policy.
 *
 * Since #444 this class is a thin area definition for location knowledge
 * above storage_anchor. Material-specific policies stay here: allowlists,
 * path resolution into file-manager drafts, used-content checksums and
 * quota warnings.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class material_files {
    /** @var string Read-only, pointer-aware material store location (default, #495). */
    public const LOCATION_STORE = 'store';

    /** @var string Workbench location at the anchor: ignores external pointers and is the only write target. */
    public const LOCATION_WORKBENCH = 'workbench';

    /**
     * Shared location parameter description (#508, Spec #486 §7). One source
     * for material and embedding tools so range/description changes reach all of them.
     *
     * @var string
     */
    public const LOCATION_DESCRIPTION = '"store" (default, the teacher\'s grown material store, read-only) '
        . 'or "workbench" (Coursepilot\'s own staging area)';

    /** @var string Moodle file component: Private files (Spec 0018 §2.1). */
    public const COMPONENT = storage_anchor::COMPONENT;

    /** @var string Only file area accessible to the AI. */
    public const FILEAREA = storage_anchor::FILEAREA;

    /** @var int Fixed item ID; the area has no other items. */
    public const ITEMID = storage_anchor::ITEMID;

    /** @var string Default root directory, overridable by plugin configuration. */
    private const DEFAULT_ROOT = 'coursepilot-material';

    /**
     * Content file-area component/area by activity type (#434). One source
     * rather than diverging copies in resource/folder write_options()
     * material_reference_fields.
     *
     * @var array<string, array{component: string, filearea: string}>
     */
    public const CONTENT_FILEAREAS = [
        'resource' => ['component' => 'mod_resource', 'filearea' => 'content'],
        'folder' => ['component' => 'mod_folder', 'filearea' => 'content'],
    ];

    /**
     * General upload allowlist (Spec 0018 §6), retained from the local 1.x
     * UPLOAD_MIME_TYPES policy.
     *
     * @var string[]
     */
    private const ALLOWED_UPLOAD_EXTENSIONS = [
        'pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'html', 'htm',
        'png', 'jpg', 'jpeg', 'gif', 'svg', 'txt', 'csv', 'zip',
    ];

    /**
     * Narrower image embedding allowlist (Spec 0018 §6), retained from the
     * local 1.x EMBED_IMAGE_MIME_TYPES policy. SVG stays explicitly allowed.
     *
     * @var string[]
     */
    private const ALLOWED_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'];

    /**
     * User quota fraction below which to warn (Spec 0018 §8.1).
     */
    private const QUOTA_WARNING_RATIO = 0.1;

    /**
     * Shared location web service parameter (#508): range, default and AI
     * description in one place rather than independent tool copies.
     *
     * @return external_value
     */
    public static function location_parameter(): external_value {
        return new external_value(PARAM_ALPHA, self::LOCATION_DESCRIPTION, VALUE_DEFAULT, self::LOCATION_STORE);
    }

    /**
     * The same definition as raw tool_registry schema data for the AI tool
     * list (#508).
     *
     * @return array{type: string, enum: string[], description: string}
     */
    public static function location_schema(): array {
        return [
            'type' => 'string',
            'enum' => [self::LOCATION_STORE, self::LOCATION_WORKBENCH],
            'description' => self::LOCATION_DESCRIPTION,
        ];
    }

    /**
     * Material area definition (#444): root setting/default, error keys and
     * its specific write policy, the extension allowlist (Spec 0018 §2.4/§6).
     *
     * @return storage_area
     */
    public static function area(): storage_area {
        return new storage_area(
            rootsetting: 'materialroot',
            defaultroot: self::DEFAULT_ROOT,
            invalidpathkey: 'invalidmaterialpath',
            quotaerrorkey: 'materialquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $filename) || !self::is_allowed_extension($filename)) {
                    throw new \moodle_exception('materialfiledisallowedtype', 'local_coursepilot', '', (object) [
                        'filename' => $filename,
                        'allowed' => implode(', ', self::allowed_extensions()),
                    ]);
                }
            },
            pointerkey: 'material_store',
        );
    }

    /**
     * Workbench area definition (#495, adjusted in #520, Spec #486 §1).
     * Uses area()'s values. If the material store is in Moodle, even with a
     * custom pointer path, the workbench is the same directory so chat uploads
     * remain reachable for cleanup/deletion. Only an external material store
     * makes the workbench fall back to the configured anchor root (externalfallback);
     * external material storage has no write tool (#490 provides reads).
     *
     * All writes resolve through this area: resolve_directory()/resolve_file()/
     * resolve_writable_file() and their relative_* counterparts. The material
     * store (area()) is reachable only through its pointer-aware read methods,
     * list_entries_for_location()/read_content_for_location(), never as a write target.
     *
     * @return storage_area
     */
    public static function workbench_area(): storage_area {
        $area = self::area();
        return new storage_area(
            rootsetting: $area->rootsetting,
            defaultroot: $area->defaultroot,
            invalidpathkey: $area->invalidpathkey,
            quotaerrorkey: $area->quotaerrorkey,
            checkwritablename: $area->checkwritablename,
            pointerkey: $area->pointerkey,
            externalfallback: true,
        );
    }

    /**
     * The signed-in user's own context, never derived from client input.
     *
     * @return \context_user
     */
    public static function own_context(): \context_user {
        return storage_anchor::own_context();
    }

    /**
     * Resolve an optional client subdirectory into a complete Moodle workbench
     * path. The workbench follows a material pointer while its target is in
     * Moodle (#520); see workbench_area().
     *
     * @param string $path Relative subdirectory, e.g. "" or "subjects/math".
     * @return string Always with leading and trailing slashes.
     */
    public static function resolve_directory(string $path): string {
        return storage_anchor::resolve_directory(self::workbench_area(), $path);
    }

    /**
     * Client path relative to the root for a resolved directory. The root
     * itself is the empty path.
     *
     * @param string $directory Result of {@see resolve_directory()}
     * @return string
     */
    public static function relative_directory(string $directory): string {
        return storage_anchor::relative_directory(self::workbench_area(), $directory);
    }

    /**
     * Client file path: relative_directory() plus a filename.
     *
     * @param string $directory Result of {@see resolve_directory()}
     * @param string $filename
     * @return string
     */
    public static function relative_file(string $directory, string $filename): string {
        return storage_anchor::relative_file(self::workbench_area(), $directory, $filename);
    }

    /**
     * Resolve a client file path (directory plus filename) for reading, without
     * extension checks. Existing or manually placed files stay readable.
     *
     * @param string $path e.g. "screenshot.png" or "subjects/math/sheet.pdf".
     * @return array{0: string, 1: string} [directory path, filename]
     */
    public static function resolve_file(string $path): array {
        return storage_anchor::resolve_file(self::workbench_area(), $path);
    }

    /**
     * Recursively list material files, without directories, regardless of
     * location (#488), for external\report_loose_material_files.
     *
     * @param string $directory Result of {@see resolve_directory()}.
     * @return array<int, array{directory: string, name: string, size: int,
     *         contenthash: string, timecreated: int}>
     */
    public static function list_entries_recursive(string $directory): array {
        return storage_anchor::list_entries_recursive($directory);
    }

    /**
     * Read material content regardless of location (#488).
     *
     * @param string $directory Result of {@see resolve_directory()}.
     * @param string $filename
     * @return array{content: string, mimetype: string, size: int, contenthash: string,
     *         timemodified: int}|null null if the file is missing or is a directory.
     */
    public static function read_content(string $directory, string $filename): ?array {
        return storage_anchor::read_content($directory, $filename);
    }

    /**
     * Union of both extension allowlists (Spec 0018 §6).
     *
     * @return string[]
     */
    public static function allowed_extensions(): array {
        return array_values(array_unique(array_merge(self::ALLOWED_UPLOAD_EXTENSIONS, self::ALLOWED_IMAGE_EXTENSIONS)));
    }

    /**
     * Whether a filename extension belongs to either allowlist.
     *
     * @param string $filename
     * @return bool
     */
    public static function is_allowed_extension(string $filename): bool {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::allowed_extensions(), true);
    }

    /**
     * Image embedding allowlist (Spec 0018 §6, #433), exposing
     * ALLOWED_IMAGE_EXTENSIONS for activity-description validation. A PDF can
     * be stored and attached as an additional file (#429), but cannot be
     * embedded as an img element in the intro.
     *
     * @return string[]
     */
    public static function allowed_embed_image_extensions(): array {
        return self::ALLOWED_IMAGE_EXTENSIONS;
    }

    /**
     * Whether a filename extension is allowed for image embedding (Spec 0018 §6).
     *
     * @param string $filename
     * @return bool
     */
    public static function is_allowed_embed_image_extension(string $filename): bool {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return $extension !== '' && in_array($extension, self::ALLOWED_IMAGE_EXTENSIONS, true);
    }

    /**
     * Like resolve_file(), applying Spec 0018 §2.4 write rules (from Spec 0016
     * §5.1): directory segments use [A-Za-z0-9_-], and filenames use those
     * characters plus an allowed extension (Spec 0018 §6), rather than .md only.
     *
     * @param string $path e.g. "screenshot.png" or "subjects/math/sheet.pdf".
     * @return array{0: string, 1: string} [directory path, filename]
     * @throws \moodle_exception invalidmaterialpath / materialfiledisallowedtype
     */
    public static function resolve_writable_file(string $path): array {
        return storage_anchor::resolve_writable_file(self::workbench_area(), $path);
    }

    /**
     * List one level at the requested location (#495, Spec #486 §2/§7) through
     * storage_anchor::port(). workbench stays in Private files; store (default)
     * follows its pointer to Moodle or external storage. Both reject paths at
     * or below the context area, irrespective of storage, and mark a direct
     * child that is the context area as context_area rather than folder.
     *
     * @param string $locationkey {@see LOCATION_STORE}/{@see LOCATION_WORKBENCH}.
     * @param string $path
     * @return array{directory: string, entries: array}
     * @throws \moodle_exception invalidmateriallocation, materialpathiscontext,
     *         materialexternalerror, plus location/pointer errors from the anchor.
     */
    public static function list_entries_for_location(string $locationkey, string $path): array {
        [$area, $location, $contextlocation, $normalisedpath] = self::guarded_location($locationkey, $path);
        try {
            $entries = storage_anchor::port($area)->list($area, $path);
        } catch (webdav_error $e) {
            // Use material-specific failure text instead of the AI-directed
            // context-gap message (#526, Spec #486 §8).
            throw pointer_reader::webdav_exception($e, 'materialexternalerror');
        }

        return [
            'directory' => $normalisedpath,
            'entries' => array_map(
                static fn (array $entry): array => self::mark_context_area_entry(
                    self::with_contenthash($entry, $location),
                    $location,
                    $contextlocation,
                    $normalisedpath
                ),
                $entries
            ),
        ];
    }

    /**
     * Read a file at the requested location (#495). See
     * list_entries_for_location() for location/context-area isolation.
     *
     * @param string $locationkey {@see LOCATION_STORE}/{@see LOCATION_WORKBENCH}.
     * @param string $path
     * @return array{path: string, content: string, mimetype: string, size: int,
     *         contenthash: string, timemodified: int}|null
     * @throws \moodle_exception as in {@see list_entries_for_location()}, plus invalidmaterialpath.
     */
    public static function read_content_for_location(string $locationkey, string $path): ?array {
        [$area, $location, , $normalisedpath] = self::guarded_location($locationkey, $path);
        try {
            $file = storage_anchor::port($area)->read($area, $path);
        } catch (webdav_error $e) {
            throw pointer_reader::webdav_exception($e, 'materialexternalerror');
        }
        return $file === null ? null : self::with_contenthash($file, $location) + ['path' => $normalisedpath];
    }

    /**
     * Area and resolved locations for a material request after enforcing
     * context-area protection.
     *
     * @param string $locationkey
     * @param string $path
     * @return array{0: storage_area, 1: pointer_location, 2: pointer_location, 3: string}
     * @throws \moodle_exception invalidmateriallocation, materialpathiscontext
     */
    private static function guarded_location(string $locationkey, string $path): array {
        $location = self::location_for_value($locationkey);
        $contextlocation = storage_anchor::effective_location(context_files::area());
        $normalisedpath = self::normalise_path($path);
        self::guard_not_context_area($location, $contextlocation, $normalisedpath);
        $area = $locationkey === self::LOCATION_WORKBENCH ? self::workbench_area() : self::area();
        return [$area, $location, $contextlocation, $normalisedpath];
    }

    /**
     * Expose the adapter checksum as contenthash with the same shape for both
     * locations. Directories and external material have none (Spec #486 §7):
     * an ETag/modification-time substitute is not presented as a content checksum.
     *
     * @param array $entry Entry or file from {@see storage_port}.
     * @param pointer_location $location Location containing the entry.
     * @return array
     */
    private static function with_contenthash(array $entry, pointer_location $location): array {
        $hascontenthash = $location->kind === pointer_location::MOODLE && ($entry['type'] ?? 'file') !== 'folder';
        $entry['contenthash'] = $hascontenthash ? $entry['checksum'] : '';
        unset($entry['checksum']);
        return $entry;
    }

    /**
     * Validate client path segments without binding to a root. Used for error
     * messages/comparison keys before knowing whether the path can resolve.
     *
     * @param string $path
     * @return string
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function normalise_path(string $path): string {
        return storage_anchor::normalise_client_path(self::area(), $path);
    }

    /**
     * Provides location for value.
     *
     * @param string $locationkey
     * @return pointer_location
     * @throws \moodle_exception invalidmateriallocation
     */
    private static function location_for_value(string $locationkey): pointer_location {
        if (!in_array($locationkey, [self::LOCATION_STORE, self::LOCATION_WORKBENCH], true)) {
            throw new \moodle_exception('invalidmateriallocation', 'local_coursepilot', '', $locationkey);
        }
        return $locationkey === self::LOCATION_WORKBENCH
            ? storage_anchor::effective_location(self::workbench_area())
            : storage_anchor::effective_location(self::area());
    }

    /**
     * Material paths at or below the context area are rejected regardless of
     * location (#495, Spec #486 §2/§7), using pointer_location::comparison_key().
     *
     * @param pointer_location $location Location resolving subpath.
     * @param pointer_location $contextlocation Resolved context area.
     * @param string $subpath Already segment-validated; see normalise_path().
     * @throws \moodle_exception materialpathiscontext
     */
    private static function guard_not_context_area(
        pointer_location $location,
        pointer_location $contextlocation,
        string $subpath
    ): void {
        if (str_starts_with($location->comparison_key($subpath), $contextlocation->comparison_key())) {
            throw new \moodle_exception('materialpathiscontext', 'local_coursepilot');
        }
    }

    /**
     * Mark a direct child that is the context area as context_area, not folder
     * (#495, Spec #486 §2/§7). It is listed visibly but guard_not_context_area()
     * blocks subsequent access through material tools.
     *
     * @param array $entry
     * @param pointer_location $location Location containing parentpath.
     * @param pointer_location $contextlocation Resolved context area.
     * @param string $parentpath Resolved, segment-validated parent path.
     * @return array
     */
    private static function mark_context_area_entry(
        array $entry,
        pointer_location $location,
        pointer_location $contextlocation,
        string $parentpath
    ): array {
        if ($entry['type'] !== 'folder') {
            return $entry;
        }
        $childpath = $parentpath === '' ? $entry['name'] : $parentpath . '/' . $entry['name'];
        if ($location->comparison_key($childpath) === $contextlocation->comparison_key()) {
            $entry['type'] = 'context_area';
        }
        return $entry;
    }

    /**
     * Require the standard permission to manage one's own files, as in
     * context_files. The material folder shares the Private files area.
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_own_files(): void {
        storage_anchor::require_manage_own_files();
    }

    /**
     * Remaining user quota in bytes, independent of root, as in
     * context_files::remaining_quota(). Reuse storage_anchor because the quota
     * covers the whole user rather than a subdirectory.
     *
     * @return int|null Remaining bytes, or null when unlimited.
     */
    public static function remaining_quota(): ?int {
        return storage_anchor::remaining_quota();
    }

    /**
     * Reject writes exceeding the user quota, including any positive growth
     * when zero space remains (Spec 0018 §8.1).
     *
     * @param int $additionalbytes Growth compared with the previous content.
     * @throws \moodle_exception materialquotaexceeded
     */
    public static function require_quota(int $additionalbytes): void {
        storage_anchor::require_quota(self::area(), $additionalbytes);
    }

    /**
     * Warn when a write leaves less than 10% of user quota
     * (Spec 0018 §8.1, following Spec 0016 §5.4).
     *
     * @param int $additionalbytes Growth compared with the previous content.
     * @return string|null Warning text, or null when no warning is needed.
     */
    public static function quota_warning(int $additionalbytes): ?string {
        global $CFG;

        $remaining = self::remaining_quota();
        $quota = (int) ($CFG->userquota ?? 0);
        if ($remaining === null || $quota <= 0) {
            return null;
        }
        $remainingafter = max(0, $remaining - $additionalbytes);
        if ($remainingafter / $quota >= self::QUOTA_WARNING_RATIO) {
            return null;
        }
        return get_string('materialquotawarning', 'local_coursepilot', format_float($remainingafter / 1048576, 1));
    }

    /**
     * Per-file size limit for resolve_into_draft() (Spec #486 §7, #496).
     * Drafts do not consume user quota, so existing-store files do not call
     * require_quota(). Instead apply CFG->maxbytes, the same limit Moodle
     * uses for activity uploads in file managers/pickers. A nonpositive value
     * means no additional limit (Moodle convention; get_max_upload_file_size()).
     *
     * @param int $bytes
     * @return void
     * @throws \moodle_exception materialembedtoolarge
     */
    private static function guard_embed_size(int $bytes): void {
        global $CFG;

        $maxbytes = (int) ($CFG->maxbytes ?? 0);
        if ($maxbytes <= 0 || $bytes <= $maxbytes) {
            return;
        }
        throw new \moodle_exception('materialembedtoolarge', 'local_coursepilot', '', (object) [
            'size' => $bytes,
            'max' => $maxbytes,
        ]);
    }

    /**
     * Moodle file record for a material file.
     *
     * @param int $contextid
     * @param string $directory
     * @param string $filename
     * @return array
     */
    public static function filerecord(int $contextid, string $directory, string $filename): array {
        return storage_anchor::filerecord($contextid, $directory, $filename);
    }

    /**
     * Resolve material paths into a file-manager draft (Spec 0018 §4.2/§7).
     * Prepare existing targetcontextid/component/filearea/itemid attachments
     * with file_prepare_draft_area() first. Preserve them: calls append, not replace.
     *
     * Read-only for sources: copy each file without moving or deleting it. If a
     * later activity write fails, material files remain untouched (Spec 0018 §4.2).
     *
     * Each entry is either a path string (placed at draft root /) or an object
     * {path, target_folder} (#434: choose a folder inside mod_folder's content).
     * mod_folder supports real subdirectories; mod_assign/mod_resource file areas
     * are flat and use strings. target_folder uses the same segment validation
     * as material paths, without a separate draft-path policy.
     *
     * Since #496 (Spec #486 §7), read_content_for_location() resolves the source.
     * store (default) copies directly from the teacher's existing material without
     * staging in the workbench. Drafts do not consume user quota; each store
     * file is subject to guard_embed_size() (CFG->maxbytes). Workbench files
     * already passed upload_material_file's server limit, so copying adds no
     * new limit for them.
     *
     * @param int $targetcontextid Target activity module context.
     * @param string $component e.g. "mod_assign".
     * @param string $filearea e.g. "introattachment".
     * @param int $itemid
     * @param array $paths Material paths, e.g. ["worksheet.pdf"], or
     *        `['path' => ..., 'target_folder' => ...]` objects.
     * @param string $locationkey {@see LOCATION_STORE}/{@see LOCATION_WORKBENCH} - Source of paths (#496).
     * @return int Draft item ID, usable as a *_update_instance() field value.
     * @throws \moodle_exception invalidmaterialpath / invalidmateriallocation / materialpathiscontext /
     *         materialfilenotfound / materialembedtoolarge
     */
    public static function resolve_into_draft(
        int $targetcontextid,
        string $component,
        string $filearea,
        int $itemid,
        array $paths,
        string $locationkey = self::LOCATION_STORE
    ): int {
        $fs = get_file_storage();
        $draftitemid = 0;
        file_prepare_draft_area($draftitemid, $targetcontextid, $component, $filearea, $itemid);

        $usercontext = self::own_context();
        foreach ($paths as $entry) {
            self::embed_draft_entry($fs, $usercontext->id, $draftitemid, $entry, $locationkey);
        }

        return $draftitemid;
    }

    /**
     * Resolve one list entry and copy it into the draft (#523: extracted from
     * resolve_into_draft() to keep the function below 50 lines).
     *
     * @param \file_storage $fs
     * @param int $usercontextid
     * @param int $draftitemid
     * @param string|array $entry
     * @param string $locationkey
     */
    private static function embed_draft_entry(
        \file_storage $fs,
        int $usercontextid,
        int $draftitemid,
        $entry,
        string $locationkey
    ): void {
        [$path, $targetdirectory] = self::split_draft_entry($entry);
        $source = self::read_content_for_location($locationkey, $path);
        if ($source === null) {
            throw new \moodle_exception('materialfilenotfound', 'local_coursepilot', '', self::normalise_path($path));
        }
        if ($locationkey === self::LOCATION_STORE) {
            // Only store sources need this limit (Spec #486 §7). Workbench files
            // already passed upload_material_file's get_max_upload_file_size() limit.
            // Applying CFG->maxbytes here would retroactively prevent embedding a
            // larger stored workbench file, changing behavior without spec support
            // (#496 review finding).
            self::guard_embed_size(strlen($source['content']));
        }
        $filename = basename($source['path']);

        $existing = $fs->get_file($usercontextid, 'user', 'draft', $draftitemid, $targetdirectory, $filename);
        if ($existing) {
            // The same filename was referenced again: newest content wins.
            $existing->delete();
        }
        // Deliberate shortcut - ponytail: read_content() intentionally returns no stored_file (#487/#488;
        // see storage_anchor::read_content()). Copy only MIME type explicitly,
        // leaving license/author to draft defaults. Material uploads through these
        // tools already use default metadata. Upgrade to stored_file copies with
        // full metadata when a real case demonstrates the difference.
        $fs->create_file_from_string([
            'contextid' => $usercontextid,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => $targetdirectory,
            'filename' => $filename,
            'mimetype' => $source['mimetype'] !== '' ? $source['mimetype'] : null,
        ], $source['content']);
    }

    /**
     * Split a resolve_into_draft() entry into material path and draft target
     * directory (#434).
     *
     * @param mixed $entry String or `['path' => ..., 'target_folder' => ...]`.
     * @return array{0: string, 1: string} [material path, draft target directory with surrounding slashes].
     * @throws \moodle_exception invalidmaterialpath
     */
    private static function split_draft_entry($entry): array {
        $path = self::entry_path($entry);
        if (is_string($entry)) {
            return [$path, '/'];
        }
        $targetfolder = $entry['target_folder'] ?? '';
        if (!is_string($targetfolder)) {
            throw new \moodle_exception('invalidmaterialpath', 'local_coursepilot');
        }
        // Use ordinary material-path validation rather than a separate draft
        // policy: resolve_directory() rejects . and .. segments, and
        // relative_directory() removes the root, leaving validated segments.
        $relative = self::relative_directory(self::resolve_directory($targetfolder));
        $targetdirectory = $relative === '' ? '/' : '/' . $relative . '/';
        return [$path, $targetdirectory];
    }

    /**
     * Extract a material path from a resolve_into_draft() entry, without its
     * target folder. Public so callers such as catalog\write_target::update_activity()
     * need not repeat the string/object distinction (#434).
     *
     * @param mixed $entry String or `['path' => ..., 'target_folder' => ...]`.
     * @return string
     * @throws \moodle_exception invalidmaterialpath
     */
    public static function entry_path($entry): string {
        if (is_string($entry)) {
            return $entry;
        }
        if (!is_array($entry) || !isset($entry['path']) || !is_string($entry['path'])) {
            throw new \moodle_exception('invalidmaterialpath', 'local_coursepilot');
        }
        return $entry['path'];
    }

    /**
     * Collect contenthash values from activity file areas in courses where the
     * caller can use Coursepilot (Spec 0018 §8.2, #438). Query each time rather
     * than maintaining a usage table that could drift.
     *
     * Include the entire course context subtree, all activities and question
     * banks, rather than only mod_resource/mod_folder. Files embedded in
     * assignment descriptions or question text count as used too.
     *
     * @return string[]
     */
    public static function used_contenthashes(): array {
        global $DB;

        $hashes = [];
        foreach (enrol_get_my_courses('id') as $course) {
            $coursecontext = \context_course::instance($course->id);
            if (!has_capability('local/coursepilot:use', $coursecontext)) {
                continue;
            }
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT f.contenthash
                   FROM {files} f
                   JOIN {context} c ON c.id = f.contextid
                  WHERE f.filename <> '.'
                    AND (c.id = :courseid OR " . $DB->sql_like('c.path', ':pathlike') . ")",
                ['courseid' => $coursecontext->id, 'pathlike' => $coursecontext->path . '/%']
            );
            foreach ($rows as $row) {
                $hashes[$row->contenthash] = true;
            }
        }
        return array_keys($hashes);
    }

    /**
     * Replace material content through storage_anchor::replace(), avoiding
     * duplicated file-system logic. It stages a temporary file before deleting
     * the old file (Spec 0016 §5.3), regardless of context/material roots.
     *
     * @param \stored_file|null $existing Previous file, if any.
     * @param array $filerecord Target record from filerecord().
     * @param string $content Complete new content.
     */
    public static function replace(?\stored_file $existing, array $filerecord, string $content): void {
        storage_anchor::replace($existing, $filerecord, $content);
    }

    /**
     * Quota-checked material write shared by upload_material_file (chat
     * attachments with preceding extension/concurrency checks) and
     * export_questions_xml (complete XML export without the upload allowlist,
     * which deliberately excludes .xml; see resolve_writable_file()).
     * Previously these endpoints duplicated size/quota/write orchestration
     * (#437 standards review).
     *
     * @param string $directory Result of resolve_file()/resolve_writable_file().
     * @param string $filename
     * @param string $content Complete new content.
     * @param int $oldsize Previous file size in bytes, zero if absent
     *        (usually already known by the caller, e.g. from its own concurrency or
     *        created check through read_content()).
     * @param array $recordoverrides Additional/overriding file record fields; see
     *        storage_anchor::write(), e.g. a crop's source field.
     * @return string|null Quota warning, or null when no warning is needed.
     * @throws \moodle_exception materialquotaexceeded
     */
    public static function write(
        string $directory,
        string $filename,
        string $content,
        int $oldsize,
        array $recordoverrides = []
    ): ?string {
        $additionalbytes = strlen($content) - $oldsize;

        self::require_quota($additionalbytes);
        $warning = self::quota_warning($additionalbytes);

        storage_anchor::write($directory, $filename, $content, $recordoverrides);

        return $warning;
    }
}
