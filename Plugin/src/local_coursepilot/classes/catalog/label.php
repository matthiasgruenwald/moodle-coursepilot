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

    public static function modname(): string {
        return 'label';
    }

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

    public static function write_options(): array {
        return [];
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [];
    }

    public static function blocklist(): array {
        return ['name'];
    }

    public static function combination_rules(): array {
        return [];
    }

    public static function side_effects(): array {
        return [];
    }

    public static function bundles(): array {
        return [];
    }

    public static function write_route(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        // The group mode constants (NOGROUPS/SEPARATEGROUPS/VISIBLEGROUPS)
        // belong to the shared block, not to label itself - see
        // shared_block::checked_constants().
        return [];
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
