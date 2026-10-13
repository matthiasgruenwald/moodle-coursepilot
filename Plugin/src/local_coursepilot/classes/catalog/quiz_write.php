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

use moodle_exception;

/**
 * Atomic quiz creation and settings updates through Moodle's native write APIs.
 *
 * Owns mode precedence, checked targets, grading order and form carry-forward.
 * Adapters keep only protocol validation and access checks (ADR 0030).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class quiz_write {
    /**
     * Creates a checked quiz, including its section, grading and settings, atomically.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param mixed[] $patch
     * @param string $mode
     * @param float $grade
     * @param string[] $confirmlearnerlocks
     * @return mixed[] Published creation result.
     */
    public static function create(
        int $courseid,
        int $sectionnum,
        array $patch,
        string $mode = '',
        float $grade = -1.0,
        array $confirmlearnerlocks = []
    ): array {
        $bundle = self::bundle_fields($mode);
        $merged = array_merge($bundle, $patch);

        $target = write_target::create(
            quiz::class,
            $merged,
            learner_locks::confirmed_with_mode($confirmlearnerlocks, $bundle, $patch)
        );
        $newgrade = $grade >= 0 ? $grade : quiz_write_bridge::default_grade();
        quiz_write_bridge::validate_combination_rules($target->state, $merged, $newgrade);

        return self::in_transaction(static function () use ($courseid, $sectionnum, $target, $merged, $newgrade): array {
            global $CFG;
            $course = get_course($courseid);
            require_once($CFG->dirroot . '/course/modlib.php');
            [$module] = \can_add_moduleinfo($course, 'quiz', $sectionnum);

            $moduleinfo = new \stdClass();
            $moduleinfo->modulename = 'quiz';
            $moduleinfo->module = (int) $module->id;
            $moduleinfo->section = $sectionnum;
            $moduleinfo->grade = $newgrade;

            $feedbacktextpatch = $merged['feedbacktext'] ?? null;
            $feedbackboundariespatch = $merged['feedbackboundaries'] ?? [];
            $fieldstowrite = $merged;
            unset($fieldstowrite['feedbacktext'], $fieldstowrite['feedbackboundaries']);

            foreach ($target->defaults() as $fieldname => $value) {
                $moduleinfo->{quiz_write_bridge::moduleinfo_property($fieldname)} = $value;
            }
            foreach ($fieldstowrite as $fieldname => $value) {
                $moduleinfo->{quiz_write_bridge::moduleinfo_property($fieldname)} = $value;
            }
            if ($feedbacktextpatch !== null) {
                quiz_write_bridge::apply_feedback_pseudofields($moduleinfo, $feedbacktextpatch, $feedbackboundariespatch);
            }

            $created = \add_moduleinfo($moduleinfo, $course);

            $cmid = (int) $created->coursemodule;
            [$createdfields, $sideeffects] = self::report_and_side_effects($merged, $newgrade);

            return [
                'cmid' => $cmid,
                'message' => self::create_message($createdfields, $sideeffects),
                'created_fields' => $createdfields,
                'side_effects' => $sideeffects,
            ];
        });
    }

    /**
     * Updates grading and settings as one checked write, preserving omitted form values.
     *
     * @param \stdClass $cm
     * @param mixed[] $patch
     * @param string $mode
     * @param float $grade
     * @param string[] $confirmlearnerlocks
     * @return mixed[] Published update result.
     */
    public static function update(
        \stdClass $cm,
        array $patch,
        string $mode = '',
        float $grade = -1.0,
        array $confirmlearnerlocks = []
    ): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $before = quiz::effective_state($cm, $quiz);

        $bundle = self::bundle_fields($mode);
        $merged = array_merge($bundle, $patch);

        $target = write_target::update(
            quiz::class,
            $merged,
            $before,
            learner_locks::confirmed_with_mode($confirmlearnerlocks, $bundle, $patch)
        );
        $newgrade = $grade >= 0 ? $grade : (float) $quiz->grade;
        quiz_write_bridge::validate_combination_rules($target->state, $merged, $newgrade);

        $gradechanged = $grade >= 0 && abs($grade - (float) $quiz->grade) > 0.00001;
        return self::in_transaction(static function () use ($cm, $quiz, $merged, $before, $grade, $gradechanged): array {
            global $CFG, $DB;
            // Scale existing feedback first; explicitly supplied boundaries already use the new maximum.
            if ($gradechanged) {
                quiz_write_bridge::apply_grade_change((int) $quiz->id, $grade);
            }

            $course = get_course((int) $cm->course);
            require_once($CFG->dirroot . '/course/modlib.php');
            // Note: get_moduleinfo_data() returns the raw quiz row plus the shared block
            // (visible, groupmode, cmidnumber, ...), as for update_module_settings.
            [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
            // The form state rounds gradepass to display decimals. Preserve the
            // persisted points exactly unless the patch explicitly replaces them.
            if ($before['gradepass'] !== null) {
                $moduleinfo->gradepass = $before['gradepass'];
            }

            self::apply_patch($moduleinfo, $quiz, $merged);

            \update_moduleinfo($cm, $moduleinfo, $course);

            $after = quiz::effective_state($cm, $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST));
            [$changes, $sideeffects] = self::diff_and_side_effects($merged, $before, $after, $gradechanged);

            return [
                'cmid' => (int) $cm->id,
                'message' => self::update_message($changes, $sideeffects),
                'changes' => $changes,
                'side_effects' => $sideeffects,
            ];
        });
    }

    /**
     * Applies a patch and carries forward the native form values it omits.
     *
     * @param \stdClass $moduleinfo
     * @param \stdClass $quiz
     * @param mixed[] $merged
     * @return void
     */
    private static function apply_patch(\stdClass $moduleinfo, \stdClass $quiz, array $merged): void {
        $feedbacktextpatch = $merged['feedbacktext'] ?? null;
        $feedbackboundariespatch = $merged['feedbackboundaries'] ?? [];
        $fieldstowrite = $merged;
        unset($fieldstowrite['feedbacktext'], $fieldstowrite['feedbackboundaries']);

        foreach ($fieldstowrite as $fieldname => $value) {
            $moduleinfo->{quiz_write_bridge::moduleinfo_property($fieldname)} = $value;
        }

        pseudofield_carry_forward::sync_intro_editor_from_patch($moduleinfo, $fieldstowrite);

        // Carry forward the 32 review checkboxes: quiz_process_options() ALWAYS
        // recomputes the eight bitmasks from them (see quiz_write_bridge).
        foreach (quiz_write_bridge::decompose_review_bitmasks($quiz) as $name => $value) {
            if (!array_key_exists($name, $merged)) {
                $moduleinfo->{$name} = $value;
            }
        }

        // Carry forward the password (form name quizpassword; see the catalog class documentation).
        if (!array_key_exists('quizpassword', $merged)) {
            $moduleinfo->quizpassword = (string) $quiz->password;
        }

        self::carry_feedback($moduleinfo, (int) $quiz->id, $feedbacktextpatch, $feedbackboundariespatch);

        // Get_moduleinfo_data() returns gradepass in display format
        // ("0,00"). Writing it back unchecked fails at the database after the
        // change has already persisted.
        pseudofield_carry_forward::unformat_localised_gradepass($moduleinfo);
    }

    /**
     * Restores overall feedback unless the patch explicitly replaces it.
     *
     * @param \stdClass $moduleinfo
     * @param int $quizid
     * @param string[]|null $texts
     * @param mixed[] $boundaries
     * @return void
     */
    private static function carry_feedback(\stdClass $moduleinfo, int $quizid, ?array $texts, array $boundaries): void {
        // Carry forward overall feedback (see quiz_write_bridge).
        if ($texts !== null) {
            quiz_write_bridge::apply_feedback_pseudofields($moduleinfo, $texts, $boundaries);
        } else {
            $current = quiz_write_bridge::read_feedback($quizid);
            if ($current['feedbacktext']) {
                quiz_write_bridge::apply_feedback_pseudofields(
                    $moduleinfo,
                    $current['feedbacktext'],
                    $current['feedbackboundaries']
                );
            }
        }
    }

    /**
     * Returns the catalog mode bundle, or an empty array without a mode.
     *
     * @param string $mode
     * @return array<string, mixed>
     * @throws moodle_exception unknownmode
     */
    private static function bundle_fields(string $mode): array {
        if ($mode === '') {
            return [];
        }
        $bundles = quiz::bundles();
        if (!array_key_exists($mode, $bundles)) {
            throw new moodle_exception('unknownmode', 'local_coursepilot', '', [
                'mode' => $mode,
                'modes' => implode(', ', array_keys($bundles)),
            ]);
        }
        return $bundles[$mode];
    }

    /**
     * Reports fields actually set by patch/bundle with their values, plus
     * grade (always set, never from fields_json), and side effects. The grade is always included.
     *
     * @param mixed[] $merged
     * @param float $grade
     * @return array{0: mixed[], 1: string[]}
     */
    private static function report_and_side_effects(array $merged, float $grade): array {
        $createdfields = [];
        foreach ($merged as $fieldname => $value) {
            $createdfields[] = [
                'field' => $fieldname,
                'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        $createdfields[] = [
            'field' => 'grade',
            'value_json' => json_encode($grade, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $sideeffects = [];
        if ((int) ($merged['timeopen'] ?? 0) > 0 || (int) ($merged['timeclose'] ?? 0) > 0) {
            $sideeffects[] = get_string('quizcalendarcreated', 'local_coursepilot');
        }

        return [$createdfields, $sideeffects];
    }

    /**
     * Teacher-facing creation message (Spec 0015 §3.4/§5).
     *
     * @param mixed[] $createdfields
     * @param string[] $sideeffects
     * @return string
     */
    private static function create_message(array $createdfields, array $sideeffects): string {
        $parts = [];
        foreach ($createdfields as $field) {
            $parts[] = '"' . $field['field'] . '" = ' . $field['value_json'];
        }
        $message = get_string('quizcreatedfields', 'local_coursepilot', implode(', ', $parts));

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * Diff each field that actually changed, comparing before and after rather
     * than echoing the requested patch.
     * Also report calendar changes for timeopen/timeclose (quiz::side_effects())
     * and effects of a grade change.
     *
     * @param mixed[] $merged
     * @param mixed[] $before
     * @param mixed[] $after
     * @param bool $gradechanged
     * @return array{0: mixed[], 1: string[]}
     */
    private static function diff_and_side_effects(array $merged, array $before, array $after, bool $gradechanged): array {
        $changes = [];
        $fieldnames = array_keys($merged);
        if ($gradechanged) {
            $fieldnames[] = 'grade';
        }

        foreach (array_unique($fieldnames) as $fieldname) {
            if (in_array($fieldname, ['feedbacktext', 'feedbackboundaries'], true)) {
                continue;
            }
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

        if (array_key_exists('feedbacktext', $merged) && $before['feedbacktext'] !== $after['feedbacktext']) {
            $changes[] = [
                'field' => 'feedbacktext',
                'before_json' => json_encode($before['feedbacktext'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after_json' => json_encode($after['feedbacktext'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return [$changes, self::update_side_effects($merged, $after, $gradechanged)];
    }

    /**
     * Reports the calendar and grading consequences of a persisted patch.
     *
     * @param mixed[] $merged
     * @param mixed[] $after
     * @param bool $gradechanged
     * @return string[]
     */
    private static function update_side_effects(array $merged, array $after, bool $gradechanged): array {
        $sideeffects = [];
        if (
            (array_key_exists('timeopen', $merged) && (int) $after['timeopen'] > 0)
                || (array_key_exists('timeclose', $merged) && (int) $after['timeclose'] > 0)
        ) {
            $sideeffects[] = get_string('quizcalendarupdated', 'local_coursepilot');
        }
        if ($gradechanged) {
            $sideeffects[] = get_string('quizgradesrescaled', 'local_coursepilot');
        }

        return $sideeffects;
    }

    /**
     * Teacher-facing change message (Spec 0015 §3.3/§5).
     *
     * @param mixed[] $changes
     * @param string[] $sideeffects
     * @return string
     */
    private static function update_message(array $changes, array $sideeffects): string {
        if (!$changes) {
            return get_string('quiznochange', 'local_coursepilot');
        }

        $parts = [];
        foreach ($changes as $change) {
            $parts[] = get_string('quizfieldchange', 'local_coursepilot', (object) [
                'field' => $change['field'], 'before' => $change['before_json'], 'after' => $change['after_json'],
            ]);
        }
        $message = get_string('quizchangedfields', 'local_coursepilot', implode(', ', $parts));

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * Runs the quiz write sequence atomically and rethrows its original failure.
     *
     * @param callable $write
     * @return mixed The callable's result.
     */
    private static function in_transaction(callable $write): mixed {
        global $DB;
        $nested = $DB->is_transaction_started();
        $transaction = $DB->start_delegated_transaction();
        try {
            $result = $write();
        } catch (\Throwable $e) {
            if ($nested) {
                // Moodle semantics: the caller's outer transaction is marked
                // for rollback and fails as a whole.
                $transaction->rollback($e);
            }
            // We own the stack: a native helper may leave its own inner
            // transaction open (add_moduleinfo() does when a step throws an
            // \Error), where a delegated rollback would only rethrow. Roll
            // back the whole stack so no partial write survives.
            $DB->force_transaction_rollback();
            throw $e;
        }
        $transaction->allow_commit();
        return $result;
    }
}
