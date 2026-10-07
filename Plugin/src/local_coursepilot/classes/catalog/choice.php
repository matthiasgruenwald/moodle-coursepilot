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
 * Field catalog for mod_choice (Spec 0015 §2.4/§4.5, ticket #381).
 *
 * Pitfalls from the existing code base:
 * - The options are ONE field ("option[]"), not a series of fields - a
 *   repeated form group (mod/choice/mod_form.php: repeat_elements()), not a
 *   fixed set of single fields. The 2-6 limit of the local path
 *   (local_coursepilot\external\create_choice) is a Coursepilot-specific
 *   invention of that older path, not a Moodle limit - so it does NOT
 *   go into this catalog.
 * - "limit[]" is indexed in parallel to "option[]" by the same key
 *   (choice_add_instance()/choice_update_instance(): `$choice->limit[$key]`)
 *   - hence a combination rule instead of a field property: the length
 *   of limit[] must match the length of option[], otherwise Moodle does not
 *   limit some options at all.
 * - "optionid[]" carries existing choice_options IDs for the update path.
 *   choice_update_instance() does NOT check whether an ID belongs to its own
 *   instance before it is overwritten via $DB->update_record() - an
 *   ID from a foreign choice instance would overwrite and break that
 *   instance's option. Documented in the field itself, see pseudofields().
 * - Field bundle "allocation" (Spec 0015 §2.4, new): device/partner allocation
 *   needs six fields that nobody remembers to set individually -
 *   limitanswers=1, limit[] per option (1 for devices, 2 for pair work -
 *   the bundle presets the more common case 1, the AI overrides it
 *   when needed), publish=CHOICE_PUBLISH_NAMES (1), showresults=
 *   CHOICE_SHOWRESULTS_ALWAYS (3), display=CHOICE_DISPLAY_VERTICAL (1),
 *   allowupdate=1. Literal values instead of constants, because mod/choice/lib.php
 *   is not necessarily included when this class is loaded - the constants
 *   are given as comments.
 * - **"completionsubmit"** is a completion field AND a real column
 *   at once, just as with assign: functionally it belongs to
 *   completion tracking, not in a casual field patch. That is why it is on
 *   the blocklist as there - it is written via `set_completion` in the
 *   second step (ticket #461), where it is enabled for "assign" and "choice" as a
 *   module-specific completion field.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class choice implements module_catalog {

    public static function modname(): string {
        return 'choice';
    }

    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Display name of the choice.',
                true,
                null,
                null,
                null,
                'mod/choice/mod_form.php:18-22 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description text (intro) of the choice.',
                true,
                null,
                null,
                null,
                'mod/choice/db/install.xml (choice.intro, NOTNULL without DB default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/choice/db/install.xml (choice.introformat)'
            ),
            new field(
                'display',
                'PARAM_INT',
                'Display of the options: horizontal (0) or vertical (1).',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/lib.php:42-43 (CHOICE_DISPLAY_HORIZONTAL/CHOICE_DISPLAY_VERTICAL); '
                    . 'mod/choice/mod_form.php:29-34'
            ),
            new field(
                'allowupdate',
                'PARAM_BOOL',
                'Learners may change their selection afterwards.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:37 (selectyesno); column mod/choice/db/install.xml (choice.allowupdate)'
            ),
            new field(
                'allowmultiple',
                'PARAM_BOOL',
                'Multiple selection allowed. Frozen after the first answer once '
                    . 'answers exist.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:39-43 (selectyesno, freeze() with existing choice_answers); column '
                    . 'mod/choice/db/install.xml (choice.allowmultiple)'
            ),
            new field(
                'limitanswers',
                'PARAM_BOOL',
                'Limit the number of participants per option. Only then does "limit[]" take effect (see combination rules).',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:47 (selectyesno); column mod/choice/db/install.xml (choice.limitanswers)'
            ),
            new field(
                'showavailable',
                'PARAM_BOOL',
                'Show free places per option. Only visible/effective when limitanswers=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:50-52 (selectyesno, hideIf limitanswers eq 0); column '
                    . 'mod/choice/db/install.xml (choice.showavailable)'
            ),
            new field(
                'showunanswered',
                'PARAM_BOOL',
                'Count an additional option "Not answered" in the results.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:114 (selectyesno); column mod/choice/db/install.xml (choice.showunanswered)'
            ),
            new field(
                'includeinactive',
                'PARAM_BOOL',
                'Count answers of inactive (e.g. unenrolled) users in the results. '
                    . 'The form default (0) differs from the DB column default (1).',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:116-117 (selectyesno, setDefault(0) overrides DB default); column '
                    . 'mod/choice/db/install.xml (choice.includeinactive, DEFAULT=1)'
            ),
            new field(
                'timeopen',
                'PARAM_INT',
                'Unix timestamp: the choice opens. 0 = no start time. Creates a calendar entry '
                    . '(see side effects).',
                false,
                0,
                null,
                null,
                'mod/choice/mod_form.php:87-89 (date_time_selector, optional); column '
                    . 'mod/choice/db/install.xml (choice.timeopen)'
            ),
            new field(
                'timeclose',
                'PARAM_INT',
                'Unix timestamp: the choice closes. 0 = no end time. Creates a calendar entry '
                    . '(see side effects).',
                false,
                0,
                null,
                null,
                'mod/choice/mod_form.php:90-92 (date_time_selector, optional); column '
                    . 'mod/choice/db/install.xml (choice.timeclose)'
            ),
            new field(
                'showpreview',
                'PARAM_BOOL',
                'Show the options before timeopen already (without being able to vote).',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/mod_form.php:93 (advcheckbox); column mod/choice/db/install.xml (choice.showpreview)'
            ),
            new field(
                'showresults',
                'PARAM_INT',
                'When results are visible: never (0), after own answer (1), after closing (2), always '
                    . '(3).',
                false,
                0,
                [0, 1, 2, 3],
                null,
                'mod/choice/lib.php:37-40 (CHOICE_SHOWRESULTS_NOT/AFTER_ANSWER/AFTER_CLOSE/ALWAYS); '
                    . 'mod/choice/mod_form.php:100-105'
            ),
            new field(
                'publish',
                'PARAM_INT',
                'Result display anonymous (0) or with names (1). Side effect: switching from anonymous to '
                    . 'named makes answers already given retroactively visible by name - see '
                    . 'side effects.',
                false,
                0,
                [0, 1],
                null,
                'mod/choice/lib.php:34-35 (CHOICE_PUBLISH_ANONYMOUS/CHOICE_PUBLISH_NAMES); '
                    . 'mod/choice/mod_form.php:107-112'
            ),
        ];
    }

    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        return module_state::unknown(self::modname(), $instanceid, $fullcontent);
    }

    public static function write_options(): array {
        return [
            'scalar_to_repeated' => ['limit' => 'option'],
            'parallel_array_lengths' => [['reference' => 'option', 'field' => 'limit']],
            'date_order_rules' => [['reference' => 'timeopen', 'field' => 'timeclose', 'mode' => 'not_before']],
            // Return path to carry_forward_choice_options()
            // (pseudofield_carry_forward.php): "option"/"limit"/"optionid" live in
            // choice_options, not in the choice instance row - the read path
            // (module_state::read_repeated_groups(), called from
            // get_module_settings) reads the same table back through this declaration
            // instead of needing a "choice" special case in the tool
            // (issue #564).
            'repeated_group' => [
                'option' => [
                    'table' => 'choice_options',
                    'foreignkey' => 'choiceid',
                    'orderby' => 'id',
                    'fields' => ['option' => 'text', 'limit' => 'maxanswers', 'optionid' => 'id'],
                ],
            ],
        ];
    }

    public static function common_field_names(): array {
        return array_map(static fn (field $f): string => $f->name, self::fields());
    }

    public static function pseudofields(): array {
        return [
            new field(
                'option',
                'string[]',
                'The choice options - ONE field, not a series of fields: a list of texts, linked via the same '
                    . 'key to "limit" and "optionid". At least one non-empty entry '
                    . '(option[0]) is required.',
                true,
                null,
                null,
                null,
                'mod/choice/mod_form.php:53-78 (repeat_elements() of the option/limit/optionid group); '
                    . 'mod/choice/lib.php:110/151 (choice_add_instance()/choice_update_instance(): '
                    . '`foreach ($choice->option as $key => $value)`)'
            ),
            new field(
                'limit',
                'int[]',
                'Participant limit per option, linked via the same key as "option". Only effective when '
                    . 'limitanswers=1. Must have as many entries as "option" (see combination rules).',
                false,
                null,
                null,
                null,
                'mod/choice/mod_form.php:53,61-64 (repeat_elements(), default 0); mod/choice/lib.php:116-117/156-157 '
                    . '(`$choice->limit[$key]`)'
            ),
            new field(
                'optionid',
                'int[]',
                'Existing choice_options IDs for the update path, linked via the same key as "option"; '
                    . '0 or missing creates a new option. CAUTION: choice_update_instance() '
                    . 'does not check whether an ID belongs to its own instance - an ID from a foreign '
                    . 'choice instance must therefore not end up here, otherwise its option is overwritten.',
                false,
                0,
                null,
                null,
                'mod/choice/mod_form.php:55,120-133 (data_preprocessing(), hidden field); '
                    . 'mod/choice/lib.php:160-168 (choice_update_instance(): no instance check before '
                    . '$DB->update_record())'
            ),
        ];
    }

    public static function blocklist(): array {
        return [
            'completionsubmit',
        ];
    }

    public static function combination_rules(): array {
        return [
            '"limit[]" must have as many entries as "option[]" (same key in '
                . 'choice_add_instance()/choice_update_instance()) - if an entry is missing, the corresponding '
                . 'option stays unlimited, even if limitanswers=1 is set.',
            '"timeclose" must not be before "timeopen" (mod/choice/mod_form.php: validation()).',
        ];
    }

    public static function side_effects(): array {
        return [
            'Switching "publish" from anonymous (0) to named (1) makes answers already given '
                . 'retroactively visible with names, not only future ones.',
            '"timeopen"/"timeclose" create or update calendar entries (mod/choice/locallib.php: '
                . 'choice_set_events()).',
        ];
    }

    public static function bundles(): array {
        return [
            'allocation' => [
                'limitanswers' => 1,
                'limit' => 1,
                'publish' => 1, // CHOICE_PUBLISH_NAMES.
                'showresults' => 3, // CHOICE_SHOWRESULTS_ALWAYS.
                'display' => 1, // CHOICE_DISPLAY_VERTICAL.
                'allowupdate' => 1,
            ],
        ];
    }

    public static function write_route(): ?string {
        return null;
    }

    public static function checked_constants(): array {
        return [];
    }

    public static function learner_locks(): array {
        // allowupdate: the form default 0 is itself a lock. It counts
        // on creation too (#583) - whoever wants to create it open names
        // "allowupdate": 1 (as does the "allocation" bundle).
        return [
            'allowupdate' => ['op' => 'equals', 'value' => 0,
                'reason' => 'Learners cannot change their answer; a correction needs the teacher to delete the response.'],
            'timeclose' => ['op' => 'nonzero',
                'reason' => 'After the close date learners can no longer answer unless the teacher moves the date.'],
        ];
    }

    public static function grade_origin(int $instanceid = 0): string {
        return learner_locks::GRADE_NONE;
    }

    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
