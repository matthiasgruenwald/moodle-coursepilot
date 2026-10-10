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

use core_external\external_multiple_structure;
use core_external\external_value;
use moodle_exception;

/**
 * Evaluate catalog learner locks (Issue #583): settings that require
 * teacher action before a learner can continue or resubmit.
 *
 * Catalog classes declare structured conditions per field through
 * module_catalog::learner_locks(). Write tools evaluate effective values
 * and reject unconfirmed locks unless listed in "confirm_learner_locks".
 * The catalog is the single source, rather than a list in the skill corpus.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class learner_locks {
    /**
     * Allowed operators for a learner-lock condition.
     */
    public const OPS = ['equals', 'not_equals', 'greater', 'nonzero'];

    /**
     * The grade requires teacher action.
     */
    public const GRADE_TEACHER = 'teacher';

    /**
     * The grade is automatic (e.g. a quiz with automatically graded questions).
     */
    public const GRADE_AUTOMATIC = 'automatic';

    /**
     * The activity has no grade.
     */
    public const GRADE_NONE = 'none';

    /** @var string[] */
    public const GRADE_ORIGINS = [self::GRADE_TEACHER, self::GRADE_AUTOMATIC, self::GRADE_NONE];

    /**
     * Tool parameter used to confirm learner locks.
     */
    public const PARAMETER = 'confirm_learner_locks';

    /**
     * Shared write-tool parameter.
     *
     * @return external_multiple_structure
     */
    public static function confirm_parameter(): external_multiple_structure {
        return new external_multiple_structure(
            new external_value(PARAM_TEXT, 'Lock id as named in a rejection: a field name, or "teacher_grade:<cmid>"'),
            'Learner locks this call sets on purpose. A learner lock is a setting after which a learner needs an '
                . 'action by the teacher to continue or resubmit. A call that would set an unconfirmed lock is '
                . 'rejected with each lock and its reason, and nothing is written. Name a lock here only when the '
                . 'teacher explicitly asked for it; otherwise leave the field out or choose an open value.',
            VALUE_DEFAULT,
            []
        );
    }

    /**
     * Whether $value matches the condition. A missing value (null) never
     * creates a learner lock: an unset field cannot prevent progress.
     *
     * @param array $condition
     * @phpstan-param array{op:string,value?:mixed} $condition
     * @param mixed $value
     * @return bool
     */
    public static function matches(array $condition, $value): bool {
        if ($value === null) {
            return false;
        }
        switch ($condition['op']) {
            case 'equals':
                return self::same($value, $condition['value']);
            case 'not_equals':
                return !self::same($value, $condition['value']);
            case 'greater':
                return is_numeric($value) && (float) $value > (float) $condition['value'];
            case 'nonzero':
                return is_numeric($value) && (float) $value != 0;
        }
        return false;
    }

    /**
     * Compare numbers numerically and other values as strings. JSON and the
     * database may represent the same value as int 1, string "1" or true.
     *
     * @param mixed $a
     * @param mixed $b
     * @return bool
     */
    private static function same($a, $b): bool {
        $a = is_bool($a) ? (int) $a : $a;
        $b = is_bool($b) ? (int) $b : $b;
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }
        return (string) $a === (string) $b;
    }

    /**
     * Find all learner locks triggered by the effective values to write.
     *
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @param array $named Fields named by the call, including its field bundle.
     * @param array $defaults Filled form defaults, only for fields not named in $named.
     * @return array<int, array{id: string, detail: string}>
     */
    public static function find(string $catalogclass, array $named, array $defaults = []): array {
        $found = [];
        foreach ($catalogclass::learner_locks() as $fieldname => $condition) {
            $fromdefault = !array_key_exists($fieldname, $named);
            $value = $fromdefault ? ($defaults[$fieldname] ?? null) : $named[$fieldname];
            if (!self::matches($condition, $value)) {
                continue;
            }
            $found[] = [
                'id' => $fieldname,
                'detail' => '"' . $fieldname . '" = ' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    . ($fromdefault ? ' (form default, not named in this call)' : '')
                    . ': ' . $condition['reason'],
            ];
        }
        return $found;
    }

    /**
     * Like {@see find()}, for a patch of existing values. A patch repeating
     * the current value needs no new confirmation: the lock already exists.
     *
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @param array $patch
     * @param array $before
     * @return array<int, array{id: string, detail: string}>
     */
    public static function find_changed(string $catalogclass, array $patch, array $before): array {
        $changed = $patch;
        foreach (array_keys($catalogclass::learner_locks()) as $fieldname) {
            $old = $before[$fieldname] ?? null;
            $new = $patch[$fieldname] ?? null;
            if (is_scalar($old) && is_scalar($new) && self::same($new, $old)) {
                unset($changed[$fieldname]);
            }
        }
        return self::find($catalogclass, $changed);
    }

    /**
     * Existing learner locks for an instance, used by read tools.
     *
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @param array $settings Current state as in get_module_settings (DB columns);
     *        settings_aliases maps differing form names to columns
     *        (quiz: quizpassword -> password).
     * @return array<int, array{field: string, value_json: string, reason: string}>
     */
    public static function existing(string $catalogclass, array $settings): array {
        $result = [];
        $aliases = $catalogclass::write_options()['settings_aliases'] ?? [];
        foreach ($catalogclass::learner_locks() as $fieldname => $condition) {
            $value = $settings[$fieldname] ?? $settings[$aliases[$fieldname] ?? ''] ?? null;
            if (self::matches($condition, $value)) {
                $result[] = [
                    'field' => $fieldname,
                    'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'reason' => $condition['reason'],
                ];
            }
        }
        return $result;
    }

    /**
     * JSON learner-lock condition for describe_module_fields, or "null" when
     * the field cannot create a lock.
     *
     * @param string $catalogclass
     * @phpstan-param class-string<module_catalog> $catalogclass
     * @param string $fieldname
     * @return string
     */
    public static function condition_json(string $catalogclass, string $fieldname): string {
        $condition = $catalogclass::learner_locks()[$fieldname] ?? null;
        return json_encode($condition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * An explicitly selected mode (e.g. quiz "final-test") confirms its own
     * learner locks: selecting the mode is the teacher's settings decision.
     * Values explicitly overridden by the call still require confirmation.
     *
     * @param string[] $confirmed Explicitly confirmed learner locks.
     * @param array $bundle Field values of the selected mode.
     * @param array $named Fields explicitly named in the call.
     * @return string[]
     */
    public static function confirmed_with_mode(array $confirmed, array $bundle, array $named): array {
        return array_values(array_unique(array_merge($confirmed, array_keys(array_diff_key($bundle, $named)))));
    }

    /**
     * Reject any unconfirmed learner lock. Name each outstanding lock and
     * its reason so the agent can decide without further lookup. Write nothing.
     *
     * @param string $modname
     * @param array $found
     * @phpstan-param array<int,array{id:string,detail:string}> $found
     * @param string[] $confirmed
     * @return void
     * @throws moodle_exception learnerlocksunconfirmed
     */
    public static function assert_confirmed(string $modname, array $found, array $confirmed): void {
        $open = array_values(array_filter($found, static fn(array $lock): bool => !in_array($lock['id'], $confirmed, true)));
        if (!$open) {
            return;
        }
        throw new moodle_exception('learnerlocksunconfirmed', 'local_coursepilot', '', [
            'modname' => $modname,
            'locks' => implode(' | ', array_column($open, 'detail')),
            'ids' => json_encode(array_column($open, 'id'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'parameter' => self::PARAMETER,
        ]);
    }
}
