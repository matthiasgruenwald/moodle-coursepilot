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
 * Field catalog for mod_page (Spec 0015 §4.1, yellow: named special handling).
 *
 * Existing pitfalls (ticket #380):
 * - The "page" form field (editor array text/format/itemid) is a pseudofield
 *   read without a guard by page_update_instance() (mod/page/lib.php:
 *   `$data->content = $data->page['text'];`). If absent on update, content
 *   and contentformat become null: PHP reads the missing array offset as null.
 * - printintro/printlastmodified are serialized to displayoptions, not columns.
 * - Contrary to Spec 0015 §2.2, display is a real column in
 *   mod/page/db/install.xml; omitting it would fail the catalog drift test.
 *   Spec 0015 mentions popupwidth/popupheight only for url (display=6), but
 *   page also reads them in mod/page/lib.php when
 *   `$data->display == RESOURCELIB_DISPLAY_POPUP`; both are pseudofields here.
 * - printheading no longer exists in Moodle 5.0 and is deliberately absent.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class page implements module_catalog {
    /**
     * Provides modname.
     *
     * @return string
     */
    public static function modname(): string {
        return 'page';
    }

    /**
     * Provides fields.
     *
     * @return array
     */
    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Display name of the page.',
                true,
                null,
                null,
                null,
                'mod/page/mod_form.php:39-45 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description (intro), optionally shown above the page content (pseudofield '
                    . '"printintro" controls whether it is shown).',
                false,
                null,
                null,
                null,
                'mod/page/db/install.xml (page.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/page/db/install.xml (page.introformat)'
            ),
            new field(
                'content',
                'PARAM_RAW',
                'The actual page content (HTML). Set through the "page" pseudofield, not directly - '
                    . 'therefore NOT listed here as required even though the column needs a value: the '
                    . '"page" pseudofield fulfills that requirement (#404). Requiring both created a '
                    . 'dead end - specifying "content" required "page", specifying "page" required "content".',
                false,
                null,
                null,
                null,
                'mod/page/db/install.xml (page.content); set from the "page" pseudofield in '
                    . 'mod/page/lib.php (page_update_instance())'
            ),
            new field(
                'contentformat',
                'PARAM_INT',
                'Text format of the page content. Set through the "page" pseudofield, like "content".',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/page/db/install.xml (page.contentformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Page display (e.g. new window, popup). Available options are '
                    . 'a subset configured by Moodle administration, not a fixed list.',
                false,
                0,
                null,
                'resourcelib_get_displayoptions()',
                'lib/resourcelib.php:30-42 (RESOURCELIB_DISPLAY_*-constants), :111 (resourcelib_get_displayoptions()); '
                    . 'Subset from admin_setting_configmultiselect(\'page/displayoptions\', ...) in '
                    . 'mod/page/settings.php:31-35; column mod/page/db/install.xml (page.display)'
            ),
        ];
    }

    /**
     * Provides state.
     *
     * @param int $instanceid The instanceid.
     * @param int $cmid The cmid.
     * @param bool $fullcontent The fullcontent.
     * @return array
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        global $DB;

        $details = module_state::empty($fullcontent);
        $page = $DB->get_record('page', ['id' => $instanceid], 'name, intro, content', IGNORE_MISSING);
        if ($page) {
            $details['name'] = (string) $page->name;
            $details['content'] = module_state::content_field((string) $page->content, $fullcontent);
            $details['settings'] = module_state::settings(['intro' => module_state::preview((string) $page->intro, $fullcontent)]);
        }
        return $details;
    }

    /**
     * Writes options.
     *
     * @return array
     */
    public static function write_options(): array {
        return ['editor_content' => ['page' => ['content', 'contentformat']]];
    }

    /**
     * Provides common field names.
     *
     * @return array
     */
    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    /**
     * Provides pseudofields.
     *
     * @return array
     */
    public static function pseudofields(): array {
        return [
            new field(
                'page',
                'array{text: string, format: int, itemid: int}',
                'Editor array for page content. WITHOUT this field, Moodle silently sets "content" (and '
                    . '"contentformat") to null on update - page_update_instance() reads '
                    . '$data->page[\'text\'] without a guard.',
                true,
                null,
                null,
                null,
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Source citation keeps its code span.
                'mod/page/lib.php (page_update_instance(): `$data->content = $data->page[\'text\'];`)'
            ),
            new field(
                'printintro',
                'PARAM_BOOL',
                'Show intro alongside page content. Not a DB field - stored in the serialized '
                    . '"displayoptions" column.',
                false,
                0,
                [0, 1],
                null,
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Source citation keeps its code span.
                'mod/page/lib.php (page_update_instance(): `$displayoptions[\'printintro\']`); Default '
                    . 'mod/page/settings.php:41'
            ),
            new field(
                'printlastmodified',
                'PARAM_BOOL',
                'Show modification date below page content. Not a DB field - stored in "displayoptions".',
                false,
                1,
                [0, 1],
                null,
                // phpcs:ignore moodle.Strings.ForbiddenStrings.Found -- Source citation keeps its code span.
                'mod/page/lib.php (page_update_instance(): `$displayoptions[\'printlastmodified\']`); Default '
                    . 'mod/page/settings.php:43'
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
                'mod/page/lib.php (page_update_instance(): only set when display==RESOURCELIB_DISPLAY_POPUP); '
                    . 'Default mod/page/settings.php:45'
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
                'mod/page/lib.php (page_update_instance(): only set when display==RESOURCELIB_DISPLAY_POPUP)'
            ),
        ];
    }

    /**
     * Provides blocklist.
     *
     * @return array
     */
    public static function blocklist(): array {
        return [
            'revision',
            'displayoptions',
            'legacyfiles',
            'legacyfileslast',
        ];
    }

    /**
     * Provides combination rules.
     *
     * @return array
     */
    public static function combination_rules(): array {
        return [];
    }

    /**
     * Provides side effects.
     *
     * @return array
     */
    public static function side_effects(): array {
        return [];
    }

    /**
     * Provides bundles.
     *
     * @return array
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
     * @return array
     */
    public static function checked_constants(): array {
        return ['RESOURCELIB_DISPLAY_POPUP'];
    }

    /**
     * Provides learner locks.
     *
     * @return array
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
