<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\catalog;

/**
 * Field catalog for mod_folder (Spec 0015 §4.1, Spec 0018 §4/§7: pseudofield
 * "files" on create as a list of material folder paths, optionally with
 * a target subfolder).
 *
 * Pitfalls from the existing code (ticket #380, issue #434):
 * - "files" (file manager draft itemid) is a pseudofield, fully
 *   catalogued: the value is a list of material folder paths (Spec
 *   0018 §4.2), each entry either a plain path string (lands in the
 *   root directory of the folder) or an object
 *   `{"path": "...", "target_folder": "..."}` for a target subfolder
 *   ({@see \local_coursepilot\material_files::resolve_into_draft()}).
 * - Unlike resource, an EMPTY folder is valid
 *   (mod/folder/lib.php: `$draftitemid = $data->files;` is only processed
 *   when truthy) - "files" therefore stays optional.
 * - display=1 (inline) is incompatible with automatic completion
 *   tracking on view - Moodle rejects this in the form.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class folder implements module_catalog {
    public static function modname(): string {
        return 'folder';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Display name of the folder.',
                true,
                null,
                null,
                null,
                'mod/folder/mod_form.php:38-41 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description text (intro).',
                false,
                null,
                null,
                null,
                'mod/folder/db/install.xml (folder.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/folder/db/install.xml (folder.introformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Display: own page (0) or embedded on the course page (1, "inline").',
                false,
                0,
                [0, 1],
                null,
                'mod/folder/lib.php:29,31 (FOLDER_DISPLAY_PAGE/FOLDER_DISPLAY_INLINE); Spalte '
                    . 'mod/folder/db/install.xml (folder.display)'
            ),
            new field(
                'showexpanded',
                'PARAM_BOOL',
                'Show subfolders expanded (1) or collapsed (0) when opening.',
                false,
                1,
                [0, 1],
                null,
                'mod/folder/db/install.xml (folder.showexpanded); Default get_config(\'folder\', \'showexpanded\')'
            ),
            new field(
                'showdownloadfolder',
                'PARAM_BOOL',
                'Show the "Download everything as ZIP" button.',
                false,
                1,
                [0, 1],
                null,
                'mod/folder/db/install.xml (folder.showdownloadfolder)'
            ),
            new field(
                'forcedownload',
                'PARAM_BOOL',
                'Download individual files on click instead of opening them in the browser.',
                false,
                1,
                [0, 1],
                null,
                'mod/folder/db/install.xml (folder.forcedownload)'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::unknown(self::modname(), $instanceid, $fullcontent);
    }

    public static function write_options(): array {
        return [
            'material_reference_fields' => ['files' => \local_coursepilot\material_files::CONTENT_FILEAREAS['folder']],
            'patch_blocked_fields' => ['files'],
            'missing_form_values' => ['files' => 0],
        ];
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [
            new field(
                'files',
                'List of material folder paths (JSON array)',
                'The files to place in the folder - each entry a path in the material folder (Spec 0018 §4.2, '
                    . 'e.g. ["worksheet.pdf"]) or an object {"path": "...", "target_folder": "subfolder"} for '
                    . 'a target directory inside the folder. Multiple entries per call possible. An '
                    . 'EMPTY folder is valid - unlike resource, a missing file does not block '
                    . 'creation. Usable ONLY on create (create_module) - a later patch via '
                    . 'update_module_settings deliberately fails (folderfilespatchunsupported) instead of silently '
                    . 'having no effect: to add more files, create another folder.',
                false,
                null,
                null,
                null,
                'mod/folder/lib.php (folder_add_instance(): $data->files as draft itemid; folder_update_instance() '
                    . 'instead reads file_get_submitted_draft_itemid() from $_REQUEST); '
                    . 'local_coursepilot\material_files::resolve_into_draft()'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'revision',
        ];
    }

    public static function combination_rules(): array {
        return [
            'display=1 (inline) is incompatible with automatic completion tracking on view '
                . '(completion=automatic + completionview) - Moodle rejects this in the form '
                . '(mod/folder/mod_form.php: validation()).',
        ];
    }

    public static function side_effects(): array {
        return [
            'folder can also be created without "files" - an empty folder is valid, unlike resource without '
                . 'a main file (Spec 0015 §4.3).',
        ];
    }

    public static function bundles(): array {
        return [];
    }

    public static function write_route(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        return ['FOLDER_DISPLAY_PAGE', 'FOLDER_DISPLAY_INLINE'];
    }

    public static function learner_locks(): array {
        return [];
    }

    public static function grade_origin(int $instanceid = 0): string {
        return learner_locks::GRADE_NONE;
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
