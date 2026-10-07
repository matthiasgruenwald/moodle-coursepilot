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
 * Trash for replaced activity files (Spec 0018 §9.1, Issue #432).
 *
 * Moodle deletes old files rows deep in file_save_draft_area_files(), which
 * the plugin cannot intercept. Copy the row before writing, with the same
 * contenthash in a dedicated file area. The content-addressed pool uses
 * no additional storage and keeps the content alive after original deletion.
 *
 * Uses ordinary files rows grouped by source cmid, without a custom schema.
 * Unlike material trash (§9.2), no quota pressure requires an expiry period.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_file_trash {

    /** @var string Dedicated component for internal storage, without a download route. */
    public const COMPONENT = 'local_coursepilot';

    /** @var string Dedicated trash file area. */
    public const FILEAREA = 'activityfiletrash';

    /**
     * Preserve an activity file in trash before update_moduleinfo() replaces
     * it. Call while the original still exists. Do nothing if this copy, keyed
     * by pathnamehash and contenthash, already exists.
     *
     * @param \stored_file $file Existing original activity file.
     * @param int $cmid Course module ID of the source activity.
     * @return void
     */
    public static function trash(\stored_file $file, int $cmid): void {
        $fs = get_file_storage();
        $filepath = '/' . $cmid . '/' . $file->get_pathnamehash() . '/';

        if ($fs->file_exists(
            $file->get_contextid(),
            self::COMPONENT,
            self::FILEAREA,
            $cmid,
            $filepath,
            $file->get_filename()
        )) {
            // Already preserved, e.g. a second write attempt after a failure.
            return;
        }

        $fs->create_file_from_storedfile([
            'contextid' => $file->get_contextid(),
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $cmid,
            'filepath' => $filepath,
            'filename' => $file->get_filename(),
        ], $file);
    }

    /**
     * Find the latest trash copy for history rollback (ADR 0018), matching
     * both filename and contenthash: the same content, not just the same name.
     *
     * @param int $contextid Activity module context.
     * @param int $cmid
     * @param string $filename
     * @param string $contenthash
     * @return \stored_file|null
     */
    public static function find_for_restore(int $contextid, int $cmid, string $filename, string $contenthash): ?\stored_file {
        global $DB;

        $records = $DB->get_records_select(
            'files',
            'contextid = ? AND component = ? AND filearea = ? AND itemid = ? AND filename = ? AND contenthash = ?',
            [$contextid, self::COMPONENT, self::FILEAREA, $cmid, $filename, $contenthash],
            'timecreated DESC',
            '*',
            0,
            1
        );
        if (!$records) {
            return null;
        }

        return get_file_storage()->get_file_instance(reset($records));
    }

    /**
     * Build a file-manager draft from stored_file objects for rollback from
     * trash, rather than material paths (material_files::resolve_into_draft()).
     * Preload current activity files through file_prepare_draft_area so
     * rollback replaces only affected files and preserves the others.
     *
     * @param int $targetcontextid Activity module context.
     * @param string $component
     * @param string $filearea
     * @param \stored_file[] $files Files to set, one per filename.
     * @return int Draft item ID usable directly as a *_update_instance() field value.
     */
    public static function resolve_restore_into_draft(
        int $targetcontextid,
        string $component,
        string $filearea,
        array $files
    ): int {
        global $USER;

        $fs = get_file_storage();
        $draftitemid = 0;
        file_prepare_draft_area($draftitemid, $targetcontextid, $component, $filearea, 0);

        $usercontext = \context_user::instance($USER->id);
        foreach ($files as $source) {
            $filename = $source->get_filename();
            $existing = $fs->get_file($usercontext->id, 'user', 'draft', $draftitemid, '/', $filename);
            if ($existing) {
                $existing->delete();
            }
            $fs->create_file_from_storedfile([
                'contextid' => $usercontext->id,
                'component' => 'user',
                'filearea' => 'draft',
                'itemid' => $draftitemid,
                'filepath' => '/',
                'filename' => $filename,
            ], $source);
        }

        return $draftitemid;
    }
}
