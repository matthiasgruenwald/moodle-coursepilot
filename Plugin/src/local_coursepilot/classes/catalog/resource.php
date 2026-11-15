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

namespace local_coursepilot\catalog;

/**
 * Field catalog for mod_resource (Spec 0015 §4.1/§4.3, Spec 0018 §4/§7):
 * the main file is required, supplied as a material store reference in the
 * same create_module call.
 *
 * Existing pitfalls (ticket #380, issue #434):
 * - files (file-manager draft item ID) is a fully cataloged REQUIRED pseudofield.
 *   Its value is a list of material store paths (Spec 0018 §4.2, e.g.
 *   ["worksheet.pdf"]). {@see \local_coursepilot\external\create_module} and
 *   {@see \local_coursepilot\external\update_module_settings} resolve it to a
 *   file-manager draft before writing through
 *   {@see \local_coursepilot\material_files::resolve_into_draft()}.
 * - Unlike folder, resource without a main file creates a broken activity page
 *   (mod/resource/view.php:69-71 calls resource_print_filenotfound()). Hence
 *   required=true with no default: create_module fails before creating the
 *   activity, leaving no empty intermediate state.
 * - resource_set_display_options() recomputes displayoptions from
 *   display/popupwidth/popupheight/printintro/showsize/showtype/showdate; blocked.
 * - resource_update_instance() increments revision (`$data->revision++;`); blocked.
 * - tobemigrated/legacyfiles/legacyfileslast are Moodle 1.9 restore bookkeeping,
 *   not teacher fields; blocked.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class resource implements module_catalog {
    /**
     * Provides modname.
     *
     * @return string
     */
    public static function modname(): string {
        return 'resource';
    }

    /**
     * Provides fields.
     *
     * @return mixed[]
     */
    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Display name of the file activity.',
                true,
                null,
                null,
                null,
                'mod/resource/mod_form.php:51-54 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description (intro), optionally shown above the file (pseudofield "printintro" '
                    . 'controls whether it is shown; only effective for certain display values).',
                false,
                null,
                null,
                null,
                'mod/resource/db/install.xml (resource.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/resource/db/install.xml (resource.introformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'File display (e.g. automatic, embedded, new window, popup, direct '
                    . 'open/download). Available options are a subset '
                    . 'configured by Moodle administration, not a fixed list.',
                false,
                0,
                null,
                'resourcelib_get_displayoptions()',
                'lib/resourcelib.php:30-42 (RESOURCELIB_DISPLAY_*-constants), :111 (resourcelib_get_displayoptions()); '
                    . 'Subset from admin_setting_configmultiselect(\'resource/displayoptions\', ...) in '
                    . 'mod/resource/settings.php:28-42; column mod/resource/db/install.xml (resource.display)'
            ),
            new field(
                'filterfiles',
                'PARAM_INT',
                'Apply text filters to file content: no filter (0), all files (1), or HTML files only '
                    . '(2).',
                false,
                0,
                [0, 1, 2],
                null,
                'mod/resource/mod_form.php:132-134 (option list none/allfiles/htmlfilesonly); column '
                    . 'mod/resource/db/install.xml (resource.filterfiles)'
            ),
        ];
    }

    /**
     * Provides state.
     *
     * @param int $instanceid The instanceid.
     * @param int $cmid The cmid.
     * @param bool $fullcontent The fullcontent.
     * @return mixed[]
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::unknown(self::modname(), $instanceid, $fullcontent);
    }

    /**
     * Writes options.
     *
     * @return mixed[]
     */
    public static function write_options(): array {
        return ['material_reference_fields' => ['files' => \local_coursepilot\material_files::CONTENT_FILEAREAS['resource']]];
    }

    /**
     * Provides common field names.
     *
     * @return mixed[]
     */
    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    /**
     * Provides pseudofields.
     *
     * @return mixed[]
     */
    public static function pseudofields(): array {
        return [
            new field(
                'files',
                'List of material store paths (JSON array)',
                'Files to attach, including the main file - each entry is a path in the material store '
                    . '(Spec 0018 §4.2, e.g. ["worksheet.pdf"]), uploaded there first '
                    . 'using upload_material_file. REQUIRED FIELD: WITHOUT a main file, resource creates a broken '
                    . 'activity page - unlike folder, an empty state is invalid here.',
                true,
                null,
                null,
                null,
                'mod/resource/locallib.php: resource_set_mainfile(); mod/resource/view.php:69-71 '
                    . '(resource_print_filenotfound() without a file); local_coursepilot\material_files::resolve_into_draft()'
            ),
            new field(
                'printintro',
                'PARAM_BOOL',
                'Show intro as well - only effective for display=0 (auto), 1 (embed) or 2 (frame). Not a '
                    . 'DB field - stored in the serialized "displayoptions" column.',
                false,
                0,
                [0, 1],
                null,
                'mod/resource/lib.php (resource_set_display_options(): only set when display in '
                    . '[AUTO, EMBED, FRAME])'
            ),
            new field(
                'popupwidth',
                'PARAM_INT',
                'Window width in pixels, only effective for display=6 (popup). Not a DB field - stored in '
                    . '"displayoptions".',
                false,
                620,
                null,
                null,
                'mod/resource/lib.php (resource_set_display_options(): only set when '
                    . 'display==RESOURCELIB_DISPLAY_POPUP)'
            ),
            new field(
                'popupheight',
                'PARAM_INT',
                'Window height in pixels, only effective for display=6 (popup). Not a DB field - stored in '
                    . '"displayoptions".',
                false,
                450,
                null,
                null,
                'mod/resource/lib.php (resource_set_display_options(): only set when '
                    . 'display==RESOURCELIB_DISPLAY_POPUP)'
            ),
            new field(
                'showsize',
                'PARAM_BOOL',
                'Dateigroesse anzeigen. Kein DB-Feld - fliesst in "displayoptions".',
                false,
                0,
                [0, 1],
                null,
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Source citation keeps its code span.
                'mod/resource/lib.php (resource_set_display_options(): `$displayoptions[\'showsize\']`)'
            ),
            new field(
                'showtype',
                'PARAM_BOOL',
                'Dateityp anzeigen. Kein DB-Feld - fliesst in "displayoptions".',
                false,
                0,
                [0, 1],
                null,
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Source citation keeps its code span.
                'mod/resource/lib.php (resource_set_display_options(): `$displayoptions[\'showtype\']`)'
            ),
            new field(
                'showdate',
                'PARAM_BOOL',
                'Show file creation/modification date. Not a DB field - stored in "displayoptions".',
                false,
                0,
                [0, 1],
                null,
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Source citation keeps its code span.
                'mod/resource/lib.php (resource_set_display_options(): `$displayoptions[\'showdate\']`)'
            ),
        ];
    }

    /**
     * Provides blocklist.
     *
     * @return mixed[]
     */
    public static function blocklist(): array {
        return [
            'revision',
            'displayoptions',
            'tobemigrated',
            'legacyfiles',
            'legacyfileslast',
        ];
    }

    /**
     * Provides combination rules.
     *
     * @return mixed[]
     */
    public static function combination_rules(): array {
        return [];
    }

    /**
     * Provides side effects.
     *
     * @return mixed[]
     */
    public static function side_effects(): array {
        return [
            'resource requires "files" on creation (list of material store paths, Spec 0018 '
                . '§4.2): without a main file, the activity page is broken (Spec 0015 §4.3), so create_module '
                . 'fails without "files" before anything is created.',
        ];
    }

    /**
     * Provides bundles.
     *
     * @return mixed[]
     */
    public static function bundles(): array {
        return [];
    }

    /**
     * Writes route.
     *
     * @return ?string
     */
    public static function write_route(): ?string {
        return null;
    }

    /**
     * Provides checked constants.
     *
     * @return mixed[]
     */
    public static function checked_constants(): array {
        return [];
    }

    /**
     * Provides learner locks.
     *
     * @return mixed[]
     */
    public static function learner_locks(): array {
        return [];
    }

    /**
     * Provides grade origin.
     *
     * @param int $instanceid The instanceid.
     * @return string
     */
    public static function grade_origin(int $instanceid = 0): string {
        return learner_locks::GRADE_NONE;
    }

    /**
     * Provides reviewed up to major.
     *
     * @return int
     */
    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
