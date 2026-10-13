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
 * Context-area policy in the calling teacher's Moodle Private Files.
 * The fixed root, user context and file area isolate the teacher's content.
 *
 * Owns the Markdown filename rule, write-size limit, legacy migration and
 * personal-data/host guards. Area-independent paths and file operations use
 * the common anchor and port; replacing this module with them would lose
 * the context-specific authorisation decisions.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_files {
    /** @var string Moodle file component: Private Files (Spec 0016 §1.2). */
    public const COMPONENT = storage_anchor::COMPONENT;

    /** @var string The only file area accessible to the model. */
    public const FILEAREA = storage_anchor::FILEAREA;

    /** @var int Fixed item ID; this area has no other items. */
    public const ITEMID = storage_anchor::ITEMID;

    /** @var int Hard size limit per write operation (Spec 0016 §5.2). */
    public const MAX_WRITE_BYTES = 1024 * 1024;

    /** @var string Temporary-file prefix used by {@see replace()}. */
    public const TEMP_PREFIX = private_files_storage_port::TEMP_PREFIX;

    /** @var string Legacy component before migration (#407). */
    public const LEGACY_COMPONENT = 'local_coursepilot';

    /** @var string Legacy file area before migration (#407). */
    public const LEGACY_FILEAREA = 'coursepilot_context';

    /**
     * Context-area definition (#444): root setting, default root, error keys
     * and the area's .md write-name policy (Spec 0016 §5.1).
     *
     * Since #445 the root setting/default match {@see storage_anchor::ANCHOR_ROOTSETTING}
     * and {@see storage_anchor::ANCHOR_DEFAULT_ROOT}: the context directory
     * is the fixed pointer anchor, not merely coincidentally named alike.
     *
     * @return storage_area
     */
    public static function area(): storage_area {
        return new storage_area(
            rootsetting: storage_anchor::ANCHOR_ROOTSETTING,
            defaultroot: storage_anchor::ANCHOR_DEFAULT_ROOT,
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_-]+\.md$/', $filename)) {
                    throw new \moodle_exception('contextfilenotmarkdown', 'local_coursepilot', '', $filename);
                }
            },
            pointerkey: 'context_area',
        );
    }

    /**
     * The logged-in person's own user context, never derived from client inputs.
     *
     * @return \context_user
     */
    public static function own_context(): \context_user {
        return storage_anchor::own_context();
    }

    /**
     * Resolves an optional client subfolder to a full Moodle file path
     * inside the context area.
     *
     * @param string $path Relative subfolder, e.g. "" or "subjects/math".
     * @return string Always with leading and trailing "/".
     */
    public static function resolve_directory(string $path): string {
        return storage_anchor::resolve_directory(self::area(), $path);
    }

    /**
     * Resolves a client file path (directory and filename).
     *
     * @param string $path For example "templates.md" or "subjects/math/note.md".
     * @return array{0: string, 1: string} [Directory path, filename]
     */
    public static function resolve_file(string $path): array {
        return storage_anchor::resolve_file(self::area(), $path);
    }

    /**
     * Like {@see resolve_file()} with the stricter write rules of Spec 0016 §5.1:
     * folder segments use [A-Za-z0-9_-], filenames add the .md suffix.
     * Reads remain permissive so legacy/manual files stay readable even
     * when Coursepilot would not create those names.
     *
     * @param string $path For example "plan.md" or "subjects/math/profile.md".
     * @return array{0: string, 1: string} [Directory path, filename]
     * @throws \moodle_exception invalidcontextpath / contextfilenotmarkdown
     */
    public static function resolve_writable_file(string $path): array {
        return storage_anchor::resolve_writable_file(self::area(), $path);
    }

    /**
     * Resolved context pointer (#491) for write endpoints selecting Moodle
     * or external policy (capability and quota). null means unresolved/open,
     * as in {@see \local_coursepilot\storage_anchor::resolve_pointer_location()}.
     *
     * @return pointer_location|null
     * @throws \moodle_exception pointerunreadable/pointerincomplete/pointerunreachable
     */
    public static function resolve_pointer_location(): ?pointer_location {
        return storage_anchor::resolve_pointer_location(self::area());
    }

    /**
     * Hard limit per write operation (Spec 0016 §5.2): full content for write,
     * only appended bytes for append, not the target's final size. Previously
     * duplicated in write_context_file/append_context_file until #506.
     *
     * @param string $content
     * @throws \moodle_exception contextfiletoolarge
     */
    public static function require_size_within_limit(string $content): void {
        $size = strlen($content);
        if ($size > self::MAX_WRITE_BYTES) {
            throw new \moodle_exception('contextfiletoolarge', 'local_coursepilot', '', (object) [
                'size' => $size,
                'max' => self::MAX_WRITE_BYTES,
            ]);
        }
    }

    /**
     * Standard capability for managing one's own files (Spec 0016 §1.1),
     * used by phase-2 writes. After migration to user/private, Coursepilot
     * uses the same area and permissions as Moodle Private Files.
     *
     * @throws \required_capability_exception
     */
    public static function require_manage_own_files(): void {
        private_files_storage_port::require_manage_own_files();
    }

    /**
     * Remaining user-quota bytes (Spec 0016 §1.3). file_storage does not
     * enforce $CFG->userquota itself, only core UI does; Coursepilot must
     * respect the same school limit.
     *
     * @return int|null Remaining bytes, or null if no limit applies
     *         (quota disabled, unlimited, or moodle/user:ignoreuserquota).
     */
    public static function remaining_quota(): ?int {
        return private_files_storage_port::remaining_quota();
    }

    /**
     * Moodle file record for a context-area file.
     *
     * @param int $contextid
     * @param string $directory
     * @param string $filename
     * @return mixed[]
     */
    public static function filerecord(int $contextid, string $directory, string $filename): array {
        return private_files_storage_port::filerecord($contextid, $directory, $filename);
    }

    /**
     * Replaces a context file's content, shared by all write endpoints.
     * See {@see private_files_storage_port::replace()} for temporary-file sequencing rationale.
     *
     * @param \stored_file|null $existing Existing file, if present.
     * @param mixed[] $filerecord Target from {@see filerecord()}.
     * @param string $content Complete new content.
     */
    public static function replace(?\stored_file $existing, array $filerecord, string $content): void {
        private_files_storage_port::replace($existing, $filerecord, $content);
    }

    /**
     * Copies legacy files into Private Files during upgrade (Spec 0016 §3.1).
     * Relative paths remain unchanged because both areas use the same root.
     * Skip collisions and log them; retain the legacy files for rollback.
     *
     * Files outside the root remain untouched, rather than landing loose in
     * Private Files where Coursepilot cannot see them. The upgrade log names
     * them so teachers can retrieve them manually.
     *
     * Deliberately ignores quota for this one-time system copy: migration
     * must not fail on an individual balance and copies only existing data.
     *
     * Location-specific migration knowledge remains above the common anchor;
     * {@see storage_anchor} has no legacy-area policy.
     *
     * @return int Number of copied files.
     */
    public static function migrate_legacy_files(): int {
        global $DB;

        $fs = get_file_storage();
        $root = self::resolve_directory('');
        $copied = 0;
        $legacy = $DB->get_recordset('files', [
            'component' => self::LEGACY_COMPONENT,
            'filearea' => self::LEGACY_FILEAREA,
        ]);
        foreach ($legacy as $record) {
            if ($record->filename === '.') {
                // Folder placeholder: create_file_from_storedfile() creates the
                // required directories for copied files automatically.
                continue;
            }
            if (!str_starts_with($record->filepath, $root)) {
                mtrace('local_coursepilot: Context file skipped (outside "' . $root . '"): '
                    . $record->filepath . $record->filename . ' (context ' . $record->contextid . ')');
                continue;
            }
            $target = [
                'contextid' => $record->contextid,
                'component' => self::COMPONENT,
                'filearea' => self::FILEAREA,
                'itemid' => self::ITEMID,
                'filepath' => $record->filepath,
                'filename' => $record->filename,
            ];
            if ($fs->file_exists(...array_values($target))) {
                mtrace('local_coursepilot: Context file skipped (already exists in Private Files): '
                    . $record->filepath . $record->filename . ' (context ' . $record->contextid . ')');
                continue;
            }
            $fs->create_file_from_storedfile($target, (int) $record->id);
            $copied++;
        }
        $legacy->close();

        return $copied;
    }

    /**
     * Rejects new personal data when disabled or at a disallowed host.
     *
     * @param pointer_location $location Selected location.
     * @param string $path Relative context path.
     * @param string $content Complete replacement content.
     */
    public static function guard_new_content(pointer_location $location, string $path, string $content): void {
        if (!personal_data::is_marked($content)) {
            return;
        }
        if (!personal_data::allowed()) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
        personal_data_hosts::require_allowed_location($location, $path);
    }

    /**
     * Rejects an existing personal-data-marked target when personal data is disabled.
     * Shared by context writes and appends at both locations.
     *
     * @param string|null $existingcontent Preflight content.
     * @param string $path
     * @throws \moodle_exception contextfilelocked
     */
    public static function guard_existing_locked(?string $existingcontent, string $path): void {
        if ($existingcontent !== null && !personal_data::allowed() && personal_data::is_marked($existingcontent)) {
            throw new \moodle_exception('contextfilelocked', 'local_coursepilot', '', $path);
        }
    }
}
