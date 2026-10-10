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

namespace local_coursepilot\external;

use completion_info;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\field;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\registry;
use moodle_exception;

/**
 * The only write path for completion fields (Spec 0015 §8,
 * ticket #392): the five "completion*" fields are on the blocklist of
 * update_module_settings/create_module (shared_block::BLOCKLIST) - an
 * incidental patch must not be able to delete learner data.
 *
 * The reason lies in course/modlib.php::update_moduleinfo(): without
 * "completionunlocked" Moodle silently discards the fields (line ~625: only
 * inside `if (!empty($moduleinfo->completionunlocked))` are completion/
 * completionview/completionusegrade/completionpassgrade/
 * completiongradeitemnumber actually written); with "completionunlocked"
 * the same function then ALWAYS calls
 * `completion_info::reset_all_state()` (line ~745, regardless of whether
 * a field actually changed) - with manual completion
 * (COMPLETION_TRACKING_MANUAL) that permanently deletes every
 * course_modules_completion row of this activity; with automatic
 * completion they are deleted and recalculated from the current state
 * (lib/completionlib.php::reset_all_state()/delete_all_state()).
 *
 * "completionexpected" is the only exception: Moodle writes it
 * regardless of the lock state (modlib.php line ~637, "does not affect users
 * who have completed the activity") - so it is never part of the data-loss
 * two-step flow.
 *
 * Named two-step flow (Spec 0015 §8): if the data-loss check fails
 * (completion data already exists for this cmid AND one of the
 * four lock-field values actually changes) and `confirmed` is not
 * explicitly true, NOTHING is written - the message names the number of
 * affected learners. Only the second call with `confirmed: true`
 * executes. Without data-loss risk (no existing data, or only
 * "completionexpected" changed) the call runs through without the two-step flow.
 *
 * Module-specific completion fields (ticket #461) go through
 * the same path: "completionsubmit" ("submission required" for assign,
 * "vote cast" for choice) is a column of the instance table, but
 * functionally a completion field - and a lock field, because
 * mod_assign::update_instance() only writes it with "completionunlocked". For
 * any other activity type the patch fails with a signpost instead of
 * "Unknown field" (see {@see self::MODULE_SPECIFIC_FIELDS}).
 *
 * "completionunlocked" is set exclusively here and only immediately before the
 * confirmed write - never automatically, never in
 * update_module_settings/create_module (it is on the blocklist there).
 *
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class set_completion extends external_api {
    /**
     * Completion fields the teacher/AI can set via fields_json, with their
     * allowed value range (null = no fixed range, e.g. a timestamp).
     * "completiongradeitemnumber" is deliberately not included (like
     * "cmidnumber" in update_module_settings): it is derived from the final
     * state of "completionusegrade" (0 if completionusegrade=1, otherwise
     * null - exactly the rule from course/modlib.php line ~628-631).
     *
     * @var array<string, ?int[]>
     */
    private const ALLOWED_FIELDS = [
        'completion' => [0, 1, 2],
        'completionview' => [0, 1],
        'completionusegrade' => [0, 1],
        'completionpassgrade' => [0, 1],
        'completionexpected' => null,
    ];

    /**
     * The four fields whose actual change needs "completionunlocked"
     * (lock fields) - and which are therefore subject to the data-loss two-step flow.
     * "completionexpected" is excluded (see class doc).
     *
     * @var string[]
     */
    private const LOCKED_FIELDS = ['completion', 'completionview', 'completionusegrade', 'completionpassgrade'];

    /**
     * Module-specific completion fields (ticket #461): they are not in
     * {course_modules} but a real column in the instance table -
     * "submission required" for an assignment, "vote cast"
     * for a choice. Functionally they are still completion fields and
     * therefore go through the same write path: mod_assign writes
     * "completionsubmit" only inside
     * `if (!empty($formdata->completionunlocked))` (mod/assign/locallib.php:
     * update_instance(), line ~1569) - via update_module_settings it would be
     * silently discarded, and with "completionunlocked" the same
     * data-loss two-step flow applies as for the four generic lock fields.
     * That is why they are on the blocklist of
     * assign::blocklist()/choice::blocklist() and are enabled here per
     * activity type: a patch for any other activity type fails with a
     * signpost instead of "Unknown field".
     *
     * @var array<string, array{modnames: string[], values: ?int[]}>
     */
    private const MODULE_SPECIFIC_FIELDS = [
        'completionsubmit' => ['modnames' => ['assign', 'choice'], 'values' => [0, 1]],
    ];

    /**
     * The fields settable for $modname with their value range: the five
     * generic ones plus the module-specific ones of this activity type.
     *
     * @param string $modname
     * @return array<string, ?int[]>
     */
    private static function allowed_fields(string $modname): array {
        $fields = self::ALLOWED_FIELDS;
        foreach (self::MODULE_SPECIFIC_FIELDS as $fieldname => $spec) {
            if (in_array($modname, $spec['modnames'], true)) {
                $fields[$fieldname] = $spec['values'];
            }
        }
        return $fields;
    }

    /**
     * The lock fields of this activity type - the four generic ones plus the
     * module-specific ones (they also need "completionunlocked").
     *
     * @param string $modname
     * @return string[]
     */
    private static function locked_fields(string $modname): array {
        $modulefields = array_keys(array_diff_key(self::allowed_fields($modname), self::ALLOWED_FIELDS));
        return array_merge(self::LOCKED_FIELDS, $modulefields);
    }

    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object with "completion" (0=off,1=manual,2=automatic), "completionview", '
                    . '"completionusegrade", "completionpassgrade", "completionexpected" and - for "assign" and '
                    . '"choice" - "completionsubmit" (1=submission/response required) - only the fields to '
                    . 'change (patch)'
            ),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms deleting existing completion data of learners, if writing back the '
                    . 'completion fields would trigger that (two-step confirmation of set_completion). Omit or '
                    . 'false on the first call.',
                VALUE_DEFAULT,
                false
            ),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * Runs the set completion tool.
     *
     * @param int $cmid
     * @param string $fieldsjson
     * @param bool $confirmed
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(
        int $cmid,
        string $fieldsjson,
        bool $confirmed = false,
        array $confirmlearnerlocks = []
    ): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'fields_json' => $fieldsjson,
            'confirmed' => $confirmed,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native permission check in the course context (Spec 0015 §3.3/§9.2),
        // identical to update_module_settings/create_module - no separate
        // Coursepilot write capability.
        require_capability('moodle/course:manageactivities', $context);

        $modname = (string) $cm->modname;
        $catalogclass = registry::require_catalogued($modname);

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }
        self::validate_patch($patch, $modname);

        $course = get_course((int) $cm->course);
        $completion = new completion_info($course);
        if (!$completion->is_enabled()) {
            // Without completion tracking enabled course-wide/site-wide, Moodle would
            // discard each of these fields anyway (completionlib.php:
            // is_enabled()) - clear message instead of a silent no-op.
            throw new moodle_exception('completionnotenabled', 'local_coursepilot');
        }

        $before = self::read_settings($cmid);
        $changedlocked = self::changed_fields($before, $patch, self::locked_fields($modname));
        $changedexpected = self::changed_fields($before, $patch, ['completionexpected']);

        if (!$changedlocked && !$changedexpected) {
            return [
                'cmid' => (int) $cmid,
                'modname' => (string) $cm->modname,
                'message' => 'No change: the patch already matched the current state.',
                'changes' => [],
            ];
        }

        learner_locks::assert_confirmed(
            $modname,
            self::grade_completion_locks($catalogclass, (int) $cm->instance, $before, $patch, $changedlocked),
            $params[learner_locks::PARAMETER]
        );

        if ($changedlocked) {
            $affectedlearners = (int) $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid]);
            if ($affectedlearners > 0 && !$params['confirmed']) {
                // First step: report, do not execute (Spec 0015 §8).
                throw new moodle_exception(
                    'completiondatalossconfirmationrequired',
                    'local_coursepilot',
                    '',
                    ['affected_learners' => $affectedlearners]
                );
            }
        }

        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        pseudofield_carry_forward::apply($modname, $catalogclass, $moduleinfo, $before, $cm, $patch);
        self::apply_patch($moduleinfo, $before, $patch, (bool) $changedlocked, $modname);

        \update_moduleinfo($cm, $moduleinfo, $course);

        $after = self::read_settings($cmid);
        $changes = self::diff(array_merge($changedlocked, $changedexpected), $before, $after);

        return [
            'cmid' => (int) $cmid,
            'modname' => (string) $cm->modname,
            'message' => self::build_message($changes),
            'changes' => $changes,
        ];
    }

    /**
     * Guard (#583): automatic completion via the grade waits for
     * the teacher if the grade comes from them
     * ({@see learner_locks::GRADE_TEACHER}). Counts as soon as the call itself
     * first enables the grade field or automatic completion - an
     * unchanged existing state needs no renewed confirmation.
     *
     * @param string $catalogclass Type: class-string<\local_coursepilot\catalog\module_catalog>.
     * @param int $instanceid
     * @param array $before
     * @param array $patch
     * @param string[] $changedlocked
     * @return array<int, array{id: string, detail: string}>
     */
    private static function grade_completion_locks(
        string $catalogclass,
        int $instanceid,
        array $before,
        array $patch,
        array $changedlocked
    ): array {
        $final = static fn(string $field): int => (int) ($patch[$field] ?? $before[$field] ?? 0);
        if ($final('completion') !== COMPLETION_TRACKING_AUTOMATIC) {
            return [];
        }
        $completionchanged = in_array('completion', $changedlocked, true);
        $fields = array_filter(
            ['completionusegrade', 'completionpassgrade'],
            static fn(string $field): bool => $final($field) === 1
                && ($completionchanged || in_array($field, $changedlocked, true))
        );
        if (!$fields || $catalogclass::grade_origin($instanceid) !== learner_locks::GRADE_TEACHER) {
            return [];
        }
        return array_map(static fn(string $field): array => [
            'id' => $field,
            'detail' => '"' . $field . '" = 1: the activity only counts as complete once the teacher has graded it.',
        ], array_values($fields));
    }

    /**
     * An unknown field or a value outside the allowed range fails
     * BEFORE any write access (all-or-nothing, like update_module_settings).
     *
     * @param array $patch
     * @param string $modname The modname.
     * @return void
     * @throws moodle_exception invalidfieldname|completionunknownfield|completioninvalidfieldvalue
     */
    private static function validate_patch(array $patch, string $modname): void {
        $allowedfields = self::allowed_fields($modname);
        foreach ($patch as $fieldname => $value) {
            field::assert_name($fieldname);
            if (!array_key_exists($fieldname, $allowedfields)) {
                self::assert_not_foreign_module_field($fieldname, $modname);
                throw new moodle_exception(
                    'completionunknownfield',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'allowed_fields' => implode(', ', array_keys($allowedfields))]
                );
            }
            if (!is_int($value)) {
                throw new moodle_exception(
                    'completioninvalidfieldvalue',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'value' => json_encode($value)]
                );
            }
            $allowedvalues = $allowedfields[$fieldname];
            if ($allowedvalues !== null && !in_array($value, $allowedvalues, true)) {
                throw new moodle_exception(
                    'completioninvalidfieldvalue',
                    'local_coursepilot',
                    '',
                    ['field' => $fieldname, 'value' => json_encode($value)]
                );
            }
        }
    }

    /**
     * A module-specific completion field that exists - just not
     * for this activity type: own message listing the activity types that
     * carry it, instead of "Unknown field" (same stance as
     * shared_block::assert_not_read_only_vocabulary()).
     * shared_block::assert_not_read_only_vocabulary()).
     *
     * @param string $fieldname
     * @param string $modname
     * @return void
     * @throws moodle_exception completionfieldnotformodname
     */
    private static function assert_not_foreign_module_field(string $fieldname, string $modname): void {
        if (!array_key_exists($fieldname, self::MODULE_SPECIFIC_FIELDS)) {
            return;
        }
        throw new moodle_exception('completionfieldnotformodname', 'local_coursepilot', '', [
            'field' => $fieldname,
            'modname' => $modname,
            'modnames' => implode(', ', self::MODULE_SPECIFIC_FIELDS[$fieldname]['modnames']),
        ]);
    }

    /**
     * Reads settings.
     *
     * @param int $cmid
     * @return array Current state, same shape as get_module_settings (already contains
     *         all five completion* fields).
     */
    private static function read_settings(int $cmid): array {
        $result = get_module_settings::execute($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Which of the named fields does $patch patch AND thereby actually change
     * the value compared to $before? A patch that merely repeats the existing
     * value triggers neither the two-step flow nor "completionunlocked".
     *
     * @param array $before
     * @param array $patch
     * @param string[] $fields
     * @return string[]
     */
    private static function changed_fields(array $before, array $patch, array $fields): array {
        $changed = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $patch)) {
                continue;
            }
            if ((int) ($before[$field] ?? 0) !== (int) $patch[$field]) {
                $changed[] = $field;
            }
        }
        return $changed;
    }

    /**
     * Overlays $moduleinfo with the final state (before + patch) for all
     * five fields - "completiongradeitemnumber" is derived from the final
     * state of "completionusegrade" (course/modlib.php line ~628-631).
     * "completionunlocked" is set ONLY if at least one lock field actually
     * changes - otherwise "completionexpected" would be the only reason for
     * the change and Moodle would call `reset_all_state()` anyway, even though
     * no lock-field change exists.
     *
     * @param \stdClass $moduleinfo Is extended in place.
     * @param array $before
     * @param array $patch
     * @param bool $lockedchanged
     * @param string $modname The modname.
     * @return void
     */
    private static function apply_patch(
        \stdClass $moduleinfo,
        array $before,
        array $patch,
        bool $lockedchanged,
        string $modname
    ): void {
        $allowedfields = self::allowed_fields($modname);
        $final = [];
        foreach (array_keys($allowedfields) as $field) {
            $final[$field] = array_key_exists($field, $patch) ? (int) $patch[$field] : (int) ($before[$field] ?? 0);
        }

        $moduleinfo->completion = $final['completion'];
        $moduleinfo->completionview = $final['completionview'];
        $moduleinfo->completionexpected = $final['completionexpected'];
        $moduleinfo->completionusegrade = $final['completionusegrade'];
        $moduleinfo->completionpassgrade = $final['completionpassgrade'];
        $moduleinfo->completiongradeitemnumber = $final['completionusegrade'] ? 0 : null;

        // The module-specific fields of this activity type (ticket #461) -
        // each a real column of the instance table, which update_moduleinfo()
        // passes to add_instance()/update_instance() via the form path.
        foreach (array_keys(self::MODULE_SPECIFIC_FIELDS) as $field) {
            if (array_key_exists($field, $allowedfields)) {
                $moduleinfo->$field = $final[$field];
            }
        }

        if ($lockedchanged) {
            $moduleinfo->completionunlocked = 1;
        }
    }

    /**
     * Before/after values per actually changed field - a real
     * before/after comparison like update_module_settings::diff_and_side_effects().
     *
     * @param string[] $fields
     * @param array $before
     * @param array $after
     * @return array
     */
    private static function diff(array $fields, array $before, array $after): array {
        $changes = [];
        foreach (array_unique($fields) as $fieldname) {
            $oldvalue = $before[$fieldname] ?? null;
            $newvalue = $after[$fieldname] ?? null;
            if ($oldvalue != $newvalue) {
                $changes[] = [
                    'field' => $fieldname,
                    'before_json' => json_encode($oldvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'after_json' => json_encode($newvalue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
            }
        }
        return $changes;
    }

    /**
     * Builds message.
     *
     * @param array $changes
     * @return string
     */
    private static function build_message(array $changes): string {
        if (!$changes) {
            return 'No change: the patch already matched the current state.';
        }
        $parts = [];
        foreach ($changes as $change) {
            $parts[] = '"' . $change['field'] . '" from ' . $change['before_json'] . ' to ' . $change['after_json'];
        }
        return 'Completion changed: ' . implode(', ', $parts) . '.';
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'modname' => new external_value(PARAM_TEXT, 'Activity type'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value before the write'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value after the write'),
                ]),
                'One entry per field that actually changed'
            ),
        ]);
    }
}
