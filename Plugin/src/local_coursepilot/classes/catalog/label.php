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
 * Feldkatalog fuer mod_label (Spec 0015 §4.1: gruen, keine Sonderbehandlung
 * ausser der gesperrten "name"-Spalte). Erste katalogisierte Aktivitaetsart -
 * beweist die Bauform (#379).
 *
 * mod_label/db/install.xml kennt nur id, course, name, intro, introformat,
 * timemodified. course/timemodified stehen bereits in
 * {@see shared_block::BLOCKLIST}; "name" kommt hier dazu, weil Moodle es
 * selbst aus dem Intro ableitet (mod/label/lib.php: get_label_name()) - ein
 * Patch wuerde sofort ueberschrieben.
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
                'Der Textinhalt der Textkarte (HTML). Aus ihm leitet Moodle den Anzeigenamen ab.',
                true,
                null,
                null,
                null,
                'mod/label/db/install.xml (label.intro, NOTNULL)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Textformat des Intros (HTML/Moodle-Auto-Format/Reintext/Markdown).',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); Spalte mod/label/db/install.xml (label.introformat)'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::for_modname(self::modname(), $instanceid, $cmid, $fullcontent);
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

    public static function schreibweg(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        // Die Gruppenmodus-Konstanten (NOGROUPS/SEPARATEGROUPS/VISIBLEGROUPS)
        // gehoeren zum gemeinsamen Block, nicht zu label selbst - siehe
        // shared_block::checked_constants().
        return [];
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
