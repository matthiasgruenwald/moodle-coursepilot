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

use completion_info;
use context_module;
use core_external\external_api;
use moodle_exception;

/**
 * Completion rules and their safe native read/write operation in the module catalog.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class completion_settings {
    /**
     * Single completion vocabulary: allowed values, activity types and write locks.
     * Null activity types mean shared course-module fields; an empty list is derived.
     *
     * @var array<string, array{modnames: ?string[], values: ?int[], locked: bool, writable: bool}>
     */
    private const FIELDS = [
        'completion' => ['modnames' => null, 'values' => [0, 1, 2], 'locked' => true, 'writable' => true],
        'completionview' => ['modnames' => null, 'values' => [0, 1], 'locked' => true, 'writable' => true],
        'completionusegrade' => ['modnames' => [], 'values' => [0, 1], 'locked' => true, 'writable' => true],
        'completionpassgrade' => ['modnames' => null, 'values' => [0, 1], 'locked' => true, 'writable' => true],
        'completionexpected' => ['modnames' => null, 'values' => null, 'locked' => false, 'writable' => true],
        'completiongradeitemnumber' => ['modnames' => null, 'values' => null, 'locked' => false, 'writable' => false],
        'completionunlocked' => ['modnames' => [], 'values' => null, 'locked' => false, 'writable' => false],
        'completionsubmit' => ['modnames' => ['assign', 'choice'], 'values' => [0, 1], 'locked' => true, 'writable' => true],
        'completionattemptsexhausted' => ['modnames' => ['quiz'], 'values' => [0, 1], 'locked' => true, 'writable' => false],
        'completionminattempts' => ['modnames' => ['quiz'], 'values' => null, 'locked' => true, 'writable' => true],
    ];

    /**
     * Names reserved for the dedicated completion operation.
     *
     * @return string[]
     */
    public static function blocked_fields(): array {
        return array_keys(self::FIELDS);
    }

    /**
     * Completion columns in the activity's own instance table.
     *
     * @param string $modname
     * @return string[]
     */
    public static function module_fields(string $modname): array {
        return array_keys(array_filter(
            self::FIELDS,
            static fn(array $spec): bool => in_array($modname, $spec['modnames'] ?? [], true)
        ));
    }

    /**
     * Writable completion fields for this activity type and their value ranges.
     *
     * @param string $modname
     * @return array<string, ?int[]>
     */
    public static function allowed_fields(string $modname): array {
        $fields = [];
        foreach (self::FIELDS as $name => $spec) {
            if ($spec['writable'] && (!$spec['modnames'] || in_array($modname, $spec['modnames'], true))) {
                $fields[$name] = $spec['values'];
            }
        }
        return $fields;
    }

    /**
     * Shared completion state, including the derived grade requirement.
     *
     * @param \stdClass $cm
     * @return mixed[]
     */
    public static function course_settings(\stdClass $cm): array {
        $data = [];
        foreach (self::FIELDS as $name => $spec) {
            if ($spec['modnames'] === null) {
                $data[$name] = $cm->$name === null ? null : (int) $cm->$name;
            }
        }
        $data['completionusegrade'] = $cm->completiongradeitemnumber === null ? 0 : 1;
        return $data;
    }

    /**
     * Fields whose actual change can reset learner completion data.
     *
     * @param string $modname
     * @return string[]
     */
    private static function locked_fields(string $modname): array {
        return array_keys(array_filter(
            array_intersect_key(self::FIELDS, self::allowed_fields($modname)),
            static fn(array $spec): bool => $spec['locked']
        ));
    }

    /**
     * Patch completion rules through the native Moodle write path, preserving
     * learner-lock and data-loss confirmations and the tool's result vocabulary.
     *
     * @param int $cmid
     * @param string $fieldsjson
     * @param bool $confirmed
     * @param string[] $confirmlearnerlocks
     * @return mixed[]
     */
    public static function write(
        int $cmid,
        string $fieldsjson,
        bool $confirmed = false,
        array $confirmlearnerlocks = []
    ): array {
        global $CFG;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        external_api::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native permission check in the course context (Spec 0015 §3.3/§9.2),
        // identical to update_module_settings/create_module - no separate
        // Coursepilot write capability.
        require_capability('moodle/course:manageactivities', $context);

        $modname = (string) $cm->modname;
        $catalogclass = registry::require_catalogued($modname);

        $patch = json_decode($fieldsjson, true);
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

        $before = self::read($cmid);
        self::assert_attempt_limit($before, $patch);
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
            $confirmlearnerlocks
        );

        self::assert_data_loss_confirmed($cmid, (bool) $changedlocked, $confirmed);

        require_once($CFG->dirroot . '/course/modlib.php');
        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        pseudofield_carry_forward::apply($modname, $catalogclass, $moduleinfo, $before, $cm, $patch);
        self::apply_patch($moduleinfo, $before, $patch, (bool) $changedlocked, $modname);

        \update_moduleinfo($cm, $moduleinfo, $course);

        $after = self::read($cmid);
        $changes = self::diff(array_merge($changedlocked, $changedexpected), $before, $after);

        return [
            'cmid' => (int) $cmid,
            'modname' => (string) $cm->modname,
            'message' => self::build_message($changes),
            'changes' => $changes,
        ];
    }

    /**
     * Automatic completion via the grade waits for
     * the teacher if the grade comes from them
     * ({@see learner_locks::GRADE_TEACHER}). Counts as soon as the call itself
     * first enables the grade field or automatic completion - an
     * unchanged existing state needs no renewed confirmation.
     *
     * @param string $catalogclass Type: class-string<\local_coursepilot\catalog\module_catalog>.
     * @param int $instanceid
     * @param mixed[] $before
     * @param mixed[] $patch
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
     * Refuse a reset of existing completion data until the caller confirms it.
     *
     * @param int $cmid
     * @param bool $lockedchanged
     * @param bool $confirmed
     * @return void
     */
    private static function assert_data_loss_confirmed(int $cmid, bool $lockedchanged, bool $confirmed): void {
        global $DB;
        if (!$lockedchanged || $confirmed) {
            return;
        }
        $affected = (int) $DB->count_records('course_modules_completion', ['coursemoduleid' => $cmid]);
        if ($affected > 0) {
            throw new moodle_exception('completiondatalossconfirmationrequired', 'local_coursepilot', '', [
                'affected_learners' => $affected,
            ]);
        }
    }

    /**
     * An unknown field or a value outside the allowed range fails
     * BEFORE any write access (all-or-nothing, like update_module_settings).
     *
     * @param mixed[] $patch
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
            self::validate_value($fieldname, $value, $allowedfields[$fieldname]);
        }
    }

    /**
     * Validate integer values against the field's native completion range.
     *
     * @param string $fieldname
     * @param mixed $value
     * @param int[]|null $allowedvalues
     * @return void
     */
    private static function validate_value(string $fieldname, mixed $value, ?array $allowedvalues): void {
        if (
            !is_int($value)
            || ($allowedvalues !== null && !in_array($value, $allowedvalues, true))
            || ($fieldname === 'completionminattempts' && $value < 0)
        ) {
            throw new moodle_exception('completioninvalidfieldvalue', 'local_coursepilot', '', [
                'field' => $fieldname, 'value' => json_encode($value),
            ]);
        }
    }

    /**
     * Mirror the native quiz form's minimum-attempt validation; zero means unlimited.
     *
     * @param mixed[] $before
     * @param mixed[] $patch
     * @return void
     */
    private static function assert_attempt_limit(array $before, array $patch): void {
        $minimum = $patch['completionminattempts'] ?? 0;
        $limit = (int) ($before['attempts'] ?? 0);
        if ($limit > 0 && $minimum > $limit) {
            throw new moodle_exception('completioninvalidfieldvalue', 'local_coursepilot', '', [
                'field' => 'completionminattempts', 'value' => json_encode($minimum),
            ]);
        }
    }

    /**
     * A module-specific completion field that exists - just not
     * for this activity type: own message listing the activity types that
     * carry it, instead of "Unknown field" (same stance as
     * shared_block::assert_not_read_only_vocabulary()).
     *
     * @param string $fieldname
     * @param string $modname
     * @return void
     * @throws moodle_exception completionfieldnotformodname
     */
    private static function assert_not_foreign_module_field(string $fieldname, string $modname): void {
        if (empty(self::FIELDS[$fieldname]['modnames']) || !self::FIELDS[$fieldname]['writable']) {
            return;
        }
        throw new moodle_exception('completionfieldnotformodname', 'local_coursepilot', '', [
            'field' => $fieldname,
            'modname' => $modname,
            'modnames' => implode(', ', self::FIELDS[$fieldname]['modnames']),
        ]);
    }

    /**
     * Reads settings.
     *
     * @param int $cmid
     * @return mixed[] Current state, same shape as get_module_settings (already contains
     *         the shared and module-specific completion fields).
     */
    public static function read(int $cmid): array {
        $result = module_state::read_settings($cmid);
        return json_decode($result['settings_json'], true);
    }

    /**
     * Which of the named fields does $patch patch AND thereby actually change
     * the value compared to $before? A patch that merely repeats the existing
     * value triggers neither the two-step flow nor "completionunlocked".
     *
     * @param mixed[] $before
     * @param mixed[] $patch
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
     * writable fields - "completiongradeitemnumber" is derived from the final
     * state of "completionusegrade" (course/modlib.php line ~628-631).
     * "completionunlocked" is set ONLY if at least one lock field actually
     * changes - otherwise "completionexpected" would be the only reason for
     * the change and Moodle would call `reset_all_state()` anyway, even though
     * no lock-field change exists.
     *
     * @param \stdClass $moduleinfo Is extended in place.
     * @param mixed[] $before
     * @param mixed[] $patch
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

        foreach ($final as $field => $value) {
            $moduleinfo->$field = $value;
        }
        $moduleinfo->completiongradeitemnumber = $final['completionusegrade'] ? 0 : null;
        if ($modname === 'quiz') {
            // Core resets the count unless its form checkbox is carried alongside it.
            $moduleinfo->completionminattemptsenabled = $final['completionminattempts'] > 0;
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
     * @param mixed[] $before
     * @param mixed[] $after
     * @return mixed[]
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
     * @param mixed[] $changes
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
}
