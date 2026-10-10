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
 * Field catalog for mod_label (Spec 0015 §4.1: green, no special handling
 * except the blocked "name" column). First catalogued activity kind -
 * proves the design (#379).
 *
 * mod_label/db/install.xml only knows id, course, name, intro, introformat,
 * timemodified. course/timemodified are already in
 * {@see shared_block::BLOCKLIST}; "name" is added here because Moodle derives it
 * itself from the intro (mod/label/lib.php: get_label_name()) - a
 * patch would be overwritten immediately.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class label implements module_catalog {
    /**
     * Provides modname.
     *
     * @return string
     */
    public static function modname(): string {
        return 'label';
    }

    /**
     * Provides fields.
     *
     * @return array
     */
    public static function fields(): array {
        return [
            new field(
                'intro',
                'PARAM_RAW',
                'The text content of the text card (HTML). Moodle derives the display name from it.',
                true,
                null,
                null,
                null,
                'mod/label/db/install.xml (label.intro, NOTNULL)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro (HTML/Moodle auto-format/plain text/Markdown).',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/label/db/install.xml (label.introformat)'
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
        $label = $DB->get_record('label', ['id' => $instanceid], 'name, intro', IGNORE_MISSING);
        if ($label) {
            $details['name'] = (string) $label->name;
            $details['content'] = module_state::content_field((string) $label->intro, $fullcontent);
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
        return [];
    }

    /**
     * Provides blocklist.
     *
     * @return array
     */
    public static function blocklist(): array {
        return ['name'];
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
        // The group mode constants (NOGROUPS/SEPARATEGROUPS/VISIBLEGROUPS)
        // belong to the shared block, not to label itself - see
        // shared_block::checked_constants().
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
