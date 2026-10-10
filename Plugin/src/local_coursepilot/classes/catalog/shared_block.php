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
 * Cross-module block (Spec 0015 §2.3): visibility, stealth, group mode,
 * grouping, idnumber and section assignment live in {course_modules}, not
 * in the instance table, but use the same update_moduleinfo() form path.
 * Defined ONCE and appended by describe_module_fields for every activity
 * kind; no module class duplicates it (acceptance criterion #379).
 *
 * coursepagevisibility is not a DB field. It is the read-tool vocabulary
 * (get_course_catalog) for state derived from visible/visibleoncoursepage.
 * Listed as a pseudofield so catalogs and read tools share that vocabulary
 * (Spec 0015 §3.5 "ein Vokabular").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class shared_block {
    /**
     * Always blocked fields (Spec 0015 §2.2, category 3): every module
     * recomputes these; a patch must not set them.
     *
     * The seven completion* columns (Spec 0015 §8, tickets #382/#392) belong
     * to course_modules like visible/groupmode, but are blocked. Without
     * completionunlocked, Moodle silently discards them; with it, Moodle deletes
     * learner completion data. completionunlocked is itself blocked: only the
     * dedicated set_completion endpoint (ticket #392) may set it in the named
     * two-step operation, never a patch through update_module_settings/create_module.
     *
     * @var string[]
     */
    /**
     * Read vocabulary: names returned by read tools that are not writable,
     * with the field the teacher should set instead (#404).
     *
     * Listed in {@see self::pseudofields()} and in describe_module_fields alongside
     * real pseudofields such as page. A model reading coursepagevisibility=stealth
     * naturally tries to write it; "Unknown field" was misleading because the
     * field is known but read-only. create_module, update_module_settings and
     * quiz_write_bridge return a routing hint instead.
     *
     * @var array<string, string> Field name => write-path hint.
     */
    public const READ_ONLY_VOCABULARY = [
        'coursepagevisibility' => '"visibleoncoursepage": 1 (listed on the course page) or 0 (stealth)',
        'availability_status' => '"visible": 0/1 (hidden/available) and "visibleoncoursepage": 0/1 (stealth)',
    ];

    /**
     * Throws when $fieldname is read vocabulary, with a routing hint
     * instead of "Unknown field".
     *
     * @param string $fieldname
     * @param string $modname
     * @return void
     * @throws \moodle_exception readonlyvocabularyfield
     */
    public static function assert_not_read_only_vocabulary(string $fieldname, string $modname): void {
        if (!array_key_exists($fieldname, self::READ_ONLY_VOCABULARY)) {
            return;
        }
        throw new \moodle_exception('readonlyvocabularyfield', 'local_coursepilot', '', [
            'field' => $fieldname,
            'modname' => $modname,
            'hint' => self::READ_ONLY_VOCABULARY[$fieldname],
        ]);
    }

    /**
     * Completion fields writable exclusively through
     * {@see \local_coursepilot\external\set_completion}: the seven generic
     * course_modules columns, completionunlocked and the module-specific fields
     * from set_completion::MODULE_SPECIFIC_FIELDS. Already blocked; the dedicated
     * message names the supported path (ticket #461: during acceptance a model
     * failed five times because "blocked" gave no direction).
     *
     * @var string[]
     */
    public const COMPLETION_FIELDS_VIA_SET_COMPLETION = [
        'completion',
        'completionview',
        'completionexpected',
        'completiongradeitemnumber',
        'completionusegrade',
        'completionpassgrade',
        'completionunlocked',
        'completionsubmit',
    ];

    /**
     * Throws for a completion field, pointing to set_completion
     * instead of returning only a blocked-field message.
     *
     * @param string $fieldname
     * @return void
     * @throws \moodle_exception completionfieldviasetcompletion
     */
    public static function assert_not_completion_field(string $fieldname): void {
        if (!in_array($fieldname, self::COMPLETION_FIELDS_VIA_SET_COMPLETION, true)) {
            return;
        }
        throw new \moodle_exception('completionfieldviasetcompletion', 'local_coursepilot', '', [
            'field' => $fieldname,
        ]);
    }

    /**
     * Blocklist.
     */
    public const BLOCKLIST = [
        'timemodified',
        'timecreated',
        'course',
        'completion',
        'completionview',
        'completionexpected',
        'completiongradeitemnumber',
        'completionusegrade',
        'completionpassgrade',
        'completionunlocked',
    ];

    /**
     * Category 1 of the shared block: real course_modules columns.
     *
     * @return field[]
     */
    public static function fields(): array {
        return [
            new field(
                'visible',
                'PARAM_BOOL',
                'Visible in the course (1) or hidden from learners (0).',
                false,
                1,
                [0, 1],
                null,
                'lib/db/install.xml:333 (course_modules.visible)'
            ),
            new field(
                'visibleoncoursepage',
                'PARAM_BOOL',
                'Stealth: at 0 the activity is accessible (when linked or used as a prerequisite '
                    . '), but is absent from the course page list.',
                false,
                1,
                [0, 1],
                null,
                'lib/db/install.xml:334 (course_modules.visibleoncoursepage)'
            ),
            new field(
                'groupmode',
                'PARAM_INT',
                'Group mode: no groups, separate groups or visible groups.',
                false,
                0,
                [0, 1, 2],
                null,
                'lib/grouplib.php:29,34,39 (NOGROUPS/SEPARATEGROUPS/VISIBLEGROUPS); column lib/db/install.xml:336'
            ),
            new field(
                'groupingid',
                'PARAM_INT',
                'Grouping assigned to the activity (0 = none). IDs only, not names.',
                false,
                0,
                null,
                null,
                'lib/db/install.xml:337 (course_modules.groupingid)'
            ),
            new field(
                'idnumber',
                'PARAM_RAW',
                'Freely assigned activity identifier, e.g. for grade calculations.',
                false,
                '',
                null,
                null,
                'lib/db/install.xml:329 (course_modules.idnumber)'
            ),
            new field(
                'sectionnum',
                'PARAM_INT',
                'Section number (0-based) assigned to the activity.',
                false,
                null,
                null,
                null,
                'course/modlib.php:799 (Form field "section", relative section number, not the course_sections ID)'
            ),
        ];
    }

    /**
     * Category 2 of the shared block.
     *
     * @return field[]
     */
    public static function pseudofields(): array {
        return [
            new field(
                'coursepagevisibility',
                'string',
                'READ ONLY. State derived from visible/visibleoncoursepage and used by read tools. '
                    . 'Values: "shown" (normally listed on the course page) or "stealth" (available but not '
                    . 'listed). To write, use "visibleoncoursepage" 1 or 0 instead.',
                false,
                'shown',
                ['shown', 'stealth'],
                null,
                'Plugin/src/local_coursepilot/classes/catalog/shared_block.php::derive_visibility() (Coursepilot vocabulary, '
                    . 'no separate Moodle column; affects visibleoncoursepage)'
            ),
            new field(
                'availability_status',
                'string',
                'READ ONLY. State derived from visible/visibleoncoursepage and used by read tools. '
                    . 'Includes a third value: "hidden" (visible=0), otherwise "stealth" as in coursepagevisibility '
                    . 'or "shown". To write, use "visible" and "visibleoncoursepage" instead.',
                false,
                'shown',
                ['shown', 'stealth', 'hidden'],
                null,
                'Plugin/src/local_coursepilot/classes/catalog/shared_block.php::derive_visibility() (Coursepilot vocabulary, '
                    . 'no separate Moodle column; combines visible and visibleoncoursepage)'
            ),
        ];
    }

    /**
     * One vocabulary (Spec 0015 §3.5): the single derivation of
     * coursepagevisibility and availability_status from visible/visibleoncoursepage.
     * Used by get_modules, get_course_catalog AND get_module_settings to keep
     * all three consistent.
     *
     * @param int $visible course_modules.visible
     * @param int $visibleoncoursepage course_modules.visibleoncoursepage
     * @return array{coursepagevisibility: string, availability_status: string}
     */
    public static function derive_visibility(int $visible, int $visibleoncoursepage): array {
        return [
            'coursepagevisibility' => $visibleoncoursepage === 0 ? 'stealth' : 'shown',
            'availability_status' => $visible === 0 ? 'hidden' : ($visibleoncoursepage === 0 ? 'stealth' : 'shown'),
        ];
    }

    /**
     * Category 5 of the shared block.
     *
     * @return string[]
     */
    public static function side_effects(): array {
        return [
            'Stealth requires allowstealth on the instance; when disabled, the '
                . 'write fails with a clear message instead of silently doing nothing (Spec 0015 §7).',
            'A hidden section hides its activities regardless of their '
                . 'own visible value (Spec 0015 §6).',
        ];
    }

    /**
     * Group mode constants (ticket #399, ADR 0017) apply equally to every
     * activity kind because groupmode belongs to the shared block, not one catalog.
     *
     * @return string[]
     */
    public static function checked_constants(): array {
        return ['NOGROUPS', 'SEPARATEGROUPS', 'VISIBLEGROUPS'];
    }
}
