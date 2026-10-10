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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\pseudofield_carry_forward;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\quiz;
use local_coursepilot\catalog\quiz_write_bridge;
use local_coursepilot\catalog\write_target;
use local_coursepilot\write_gate;
use moodle_exception;

/**
 * Quiz patch (Spec 0015 §5, #398): quiz is a justified exception to the
 * generic write vehicle {@see update_module_settings}. The catalog (#383)
 * still lists quiz, with `write_route(): 'update_quiz_settings'`.
 *
 * Read-modify-write follows the generic patch (Spec 0015 §3.3). Most fields
 * use update_moduleinfo(); grade instead uses
 * {@see quiz_write_bridge::apply_grade_change()}, Moodle's grade calculator
 * rather than a direct database write (ADR 0016). Without feedbacktext in
 * the patch, Moodle silently deletes overall feedback: quiz_after_add_or_update()
 * always deletes it first. This endpoint carries the current feedback
 * forward unchanged, as it does the 32 review checkboxes and password
 * (see the quiz_write_bridge class documentation).
 *
 * The three mode bundles come from {@see quiz::bundles()}, not this tool
 * description. A bundle value applies only to fields not explicitly named
 * in fields_json (Spec 0015 §2.4).
 *
 * Question/page/section ordering is outside this endpoint (Spec 0015 §5,
 * ADR 0016). Only the 16 mod_quiz structure events version it (#396).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class update_quiz_settings extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the quiz'),
            'fields_json' => new external_value(
                PARAM_RAW,
                get_string('quizpatchfields', 'local_coursepilot')
            ),
            'mode' => new external_value(
                PARAM_ALPHANUMEXT,
                'Mode bundle: "mini-check", "progress-check" or "final-test". Bundle values apply only '
                    . 'to fields not explicitly named in fields_json. Empty = keep the mode.',
                VALUE_DEFAULT,
                ''
            ),
            'grade' => new external_value(
                PARAM_FLOAT,
                'New maximum grade of the quiz - runs through Moodle\'s own grading path (scales existing '
                    . 'attempt grades and overall feedback boundaries automatically), not via fields_json. '
                    . '-1 = do not change.',
                VALUE_DEFAULT,
                -1.0
            ),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * Runs the update quiz settings tool.
     *
     * @param int $cmid
     * @param string $fieldsjson
     * @param string $mode
     * @param float $grade
     * @param string[] $confirmlearnerlocks
     * @return mixed[]
     */
    public static function execute(
        int $cmid,
        string $fieldsjson,
        string $mode = '',
        float $grade = -1.0,
        array $confirmlearnerlocks = []
    ): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'fields_json' => $fieldsjson,
            'mode' => $mode,
            'grade' => $grade,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Check native permissions early, like update_module_settings::execute().
        // get_moduleinfo_data() checks the same capability again later.
        require_capability('moodle/course:manageactivities', $context);

        // Cheap part of self-release (Spec 0015 §11, ADR 0017, #399): the generic
        // write vehicle's regime also applies to the quiz-specific tool. Reads stay unaffected.
        write_gate::assert_writable('quiz');

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $before = quiz::effective_state($cm, $quiz);

        $bundle = self::bundle_fields($params['mode']);
        $merged = array_merge($bundle, $patch);

        // Catalog rules are decided once in the write target (#646): a
        // repeated existing lock needs no new confirmation, the locks of the
        // chosen mode are confirmed by choosing it.
        $target = write_target::update(
            quiz::class,
            $merged,
            $before,
            learner_locks::confirmed_with_mode($params[learner_locks::PARAMETER], $bundle, $patch)
        );
        $newgrade = $params['grade'] >= 0 ? $params['grade'] : (float) $quiz->grade;
        quiz_write_bridge::validate_combination_rules($target->state, $merged, $newgrade);

        // Apply a grade change FIRST through Moodle's grade calculator (see
        // quiz_write_bridge): it scales existing overall feedback boundaries
        // proportionally. Only THEN does get_moduleinfo_data() read the current
        // state. Otherwise feedbackboundaries explicitly supplied in the same call
        // and already valid for the new grade would be distorted by a second scaling.
        $gradechanged = $params['grade'] >= 0 && abs($params['grade'] - (float) $quiz->grade) > 0.00001;
        if ($gradechanged) {
            quiz_write_bridge::apply_grade_change((int) $quiz->id, $params['grade']);
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

        $feedbacktextpatch = $merged['feedbacktext'] ?? null;
        $feedbackboundariespatch = $merged['feedbackboundaries'] ?? [];
        $fieldstowrite = $merged;
        unset($fieldstowrite['feedbacktext'], $fieldstowrite['feedbackboundaries']);

        foreach ($fieldstowrite as $fieldname => $value) {
            $moduleinfo->{quiz_write_bridge::moduleinfo_property($fieldname)} = $value;
        }

        // A patch setting only ->intro would otherwise silently disappear
        // (acceptance criterion 2: change the description). See
        // pseudofield_carry_forward::sync_intro_editor_from_patch().
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

        // Carry forward overall feedback (see quiz_write_bridge).
        if ($feedbacktextpatch !== null) {
            quiz_write_bridge::apply_feedback_pseudofields($moduleinfo, $feedbacktextpatch, $feedbackboundariespatch);
        } else {
            $current = quiz_write_bridge::read_feedback((int) $quiz->id);
            if ($current['feedbacktext']) {
                quiz_write_bridge::apply_feedback_pseudofields(
                    $moduleinfo,
                    $current['feedbacktext'],
                    $current['feedbackboundaries']
                );
            }
        }

        // Get_moduleinfo_data() returns gradepass in display format
        // ("0,00"). Writing it back unchecked fails at the database after the
        // change has already persisted.
        pseudofield_carry_forward::unformat_localised_gradepass($moduleinfo);

        \update_moduleinfo($cm, $moduleinfo, $course);

        $after = quiz::effective_state($cm, $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST));
        [$changes, $sideeffects] = self::diff_and_side_effects($merged, $before, $after, $gradechanged);

        return [
            'cmid' => (int) $cm->id,
            'message' => self::build_message($changes, $sideeffects),
            'changes' => $changes,
            'side_effects' => $sideeffects,
        ];
    }

    /**
     * Mode bundle from the catalog, or an empty patch when the mode is unchanged.
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
     * Diff each field that actually changed, comparing before and after rather
     * than echoing the patch (as in update_module_settings::diff_and_side_effects()).
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
        $sideeffects = [];
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

        if (
            (array_key_exists('timeopen', $merged) && (int) $after['timeopen'] > 0)
                || (array_key_exists('timeclose', $merged) && (int) $after['timeclose'] > 0)
        ) {
            $sideeffects[] = 'The calendar event for the quiz was updated.';
        }
        if ($gradechanged) {
            $sideeffects[] = 'Existing attempt grades and overall feedback boundaries were rescaled proportionally to the new '
                . 'grade.';
        }

        return [$changes, $sideeffects];
    }

    /**
     * Teacher-facing change message (Spec 0015 §3.3/§5).
     *
     * @param mixed[] $changes
     * @param string[] $sideeffects
     * @return string
     */
    private static function build_message(array $changes, array $sideeffects): string {
        if (!$changes) {
            return 'No change: the patch already matched the current state.';
        }

        $parts = [];
        foreach ($changes as $change) {
            $parts[] = '"' . $change['field'] . '" from ' . $change['before_json'] . ' to ' . $change['after_json'];
        }
        $message = 'Changed: ' . implode(', ', $parts) . '.';

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing change message'),
            'changes' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'before_json' => new external_value(PARAM_RAW, 'JSON-encoded value before the write'),
                    'after_json' => new external_value(PARAM_RAW, 'JSON-encoded value after the write'),
                ]),
                'One entry per field that actually changed'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing side-effect note'),
                'Triggered side effects, empty when none were triggered'
            ),
        ]);
    }
}
