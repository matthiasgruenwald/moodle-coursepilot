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
 * Field catalog for mod_url (Spec 0015 §4.1/§4.4, yellow: named special handling).
 *
 * Existing pitfalls (ticket #380):
 * - externalurl deliberately does NOT use PARAM_URL: clean_param() silently
 *   returns an empty string for syntax deviations (lib/classes/param.php:1039-1052).
 *   That is stricter than Moodle's form, which accepts server-relative links
 *   and mailto:. The existing implementation (#357, ae58d76) uses
 *   PARAM_RAW_TRIMMED with explicit url_appears_valid_url() validation;
 *   this catalog keeps that exact value range.
 * - url_add_instance()/url_update_instance() recompute displayoptions and
 *   parameters from other fields, so both are blocked.
 * - popupwidth/popupheight are pseudofields effective only for display=6 (popup).
 * - parameter_N/variable_N (N=0..99) are pseudofields: without them on update,
 *   url_update_instance() deletes all existing URL parameters because parameters()
 *   is rebuilt from $data->parameter_N/$data->variable_N every time (Spec 0015 §3.4).
 * - Unlike page/resource/folder, url has no revision column.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class url implements module_catalog {
    /**
     * Provides modname.
     *
     * @return string
     */
    public static function modname(): string {
        return 'url';
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
                'Display name of the link.',
                true,
                null,
                null,
                null,
                'mod/url/mod_form.php:42-45 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description (intro), optionally shown above the link (pseudofield "printintro" '
                    . 'controls whether it is shown; only effective for certain display values).',
                false,
                null,
                null,
                null,
                'mod/url/db/install.xml (url.intro, NOTNULL=false)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/url/db/install.xml (url.introformat)'
            ),
            new field(
                'externalurl',
                'PARAM_RAW_TRIMMED',
                'Target URL. NOT PARAM_URL (see class comment) - validated against '
                    . 'url_appears_valid_url().',
                true,
                null,
                null,
                'url_appears_valid_url()',
                'mod/url/locallib.php:39-46 (url_appears_valid_url()); type mod/url/mod_form.php:50 '
                    . '(PARAM_RAW_TRIMMED); column mod/url/db/install.xml (url.externalurl, NOTNULL)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Link display (e.g. embedded, new window, popup). Available '
                    . 'options are a subset configured by Moodle administration, not a fixed list.',
                false,
                0,
                null,
                'resourcelib_get_displayoptions()',
                'lib/resourcelib.php:30-42 (RESOURCELIB_DISPLAY_*-constants), :111 (resourcelib_get_displayoptions()); '
                    . 'Subset from admin_setting_configmultiselect(\'url/displayoptions\', ...) in '
                    . 'mod/url/settings.php:31-39; column mod/url/db/install.xml (url.display)'
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
        $url = $DB->get_record('url', ['id' => $instanceid], 'name, intro, externalurl', IGNORE_MISSING);
        if ($url) {
            $details['name'] = (string) $url->name;
            $details['content'] = module_state::content_field((string) $url->intro, $fullcontent);
            $details['settings'] = module_state::settings(['externalurl' => (string) $url->externalurl]);
        }
        return $details;
    }

    /**
     * Writes options.
     *
     * @return array
     */
    public static function write_options(): array {
        return [];
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
                'printintro',
                'PARAM_BOOL',
                'Show intro as well - only effective for display=0 (auto), 1 (embed) or 2 (frame). Not a '
                    . 'DB field - stored in the serialized "displayoptions" column.',
                false,
                0,
                [0, 1],
                null,
                'mod/url/lib.php (url_update_instance(): only set when display in [AUTO, EMBED, FRAME])'
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
                'mod/url/lib.php (url_update_instance(): only set when display==RESOURCELIB_DISPLAY_POPUP); '
                    . 'Default mod/url/settings.php'
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
                'mod/url/lib.php (url_update_instance(): only set when display==RESOURCELIB_DISPLAY_POPUP)'
            ),
            new field(
                'parameter_N',
                'PARAM_RAW',
                'Name of the Nth URL parameter (N=0..99, paired with variable_N). WITHOUT this field on update, '
                    . 'Moodle deletes all existing parameters - url_update_instance() rebuilds them completely on each '
                    . 'call from parameter_N/variable_N.',
                false,
                null,
                null,
                null,
                'mod/url/lib.php (url_add_instance()/url_update_instance(): loop over parameter_0..parameter_99)'
            ),
            new field(
                'variable_N',
                'PARAM_RAW',
                'Value of the Nth URL parameter (N=0..99, paired with parameter_N). See parameter_N.',
                false,
                null,
                null,
                null,
                'mod/url/lib.php (url_add_instance()/url_update_instance(): loop over variable_0..variable_99)'
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
            'displayoptions',
            'parameters',
        ];
    }

    /**
     * Provides combination rules.
     *
     * @return array
     */
    public static function combination_rules(): array {
        return [
            'parameter_N and variable_N must both be set, otherwise the pair is ignored '
                . '(mod/url/lib.php: url_add_instance()/url_update_instance()).',
        ];
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
        return [];
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
