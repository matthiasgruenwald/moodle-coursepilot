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

use mod_quiz\question\display_options;
use mod_quiz\quiz_settings as native_quiz_settings;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Bridge for external/create_quiz and external/update_quiz_settings
 * (Spec 0015 §5, Ticket #398). Translate quiz catalog vocabulary to the
 * form properties required by quiz_process_options(), using catalog
 * validation and update_moduleinfo() rather than direct DB writes.
 *
 * Carry forward three form-specific representations when absent from a patch:
 * - password as quizpassword (mirrored by quiz_process_options());
 * - eight review bitmasks as 32 type/timing checkboxes, since Moodle always
 *   recomputes masks and missing checkboxes would reset all review options;
 * - overall feedback strings as editor arrays plus boundaries, since Moodle
 *   deletes existing feedback before reinserting it.
 *
 * grade and sumgrades stay blocklisted. Endpoints set them directly or
 * through apply_grade_change(), using Moodle's grade calculator.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class quiz_write_bridge {
    /** @var string[] The eight review types; see quiz::REVIEW_TYPES. */
    private const REVIEW_TYPES = [
        'attempt', 'correctness', 'maxmarks', 'marks',
        'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback',
    ];

    /**
     * Timing suffix to bitmask, using Moodle's own constants rather than
     * a plugin-specific bitmask vocabulary.
     *
     * @var array<string, int>
     */
    private static function review_timings(): array {
        return [
            'during' => display_options::DURING,
            'immediately' => display_options::IMMEDIATELY_AFTER,
            'open' => display_options::LATER_WHILE_OPEN,
            'closed' => display_options::AFTER_CLOSE,
        ];
    }

    /**
     * All 32 type/timing pseudofield names in catalog vocabulary.
     *
     * @return string[]
     */
    public static function review_field_names(): array {
        $names = [];
        foreach (self::REVIEW_TYPES as $type) {
            foreach (array_keys(self::review_timings()) as $timing) {
                $names[] = $type . $timing;
            }
        }
        return $names;
    }

    /**
     * Decompose eight quiz review bitmasks into 32 catalog pseudofields for
     * carry-forward when the patch omits review checkboxes.
     *
     * @param \stdClass $quiz Raw quiz table row.
     * @return array<string, int>
     */
    public static function decompose_review_bitmasks(\stdClass $quiz): array {
        $result = [];
        foreach (self::REVIEW_TYPES as $type) {
            $mask = (int) ($quiz->{'review' . $type} ?? 0);
            foreach (self::review_timings() as $timing => $bit) {
                $result[$type . $timing] = ($mask & $bit) ? 1 : 0;
            }
        }
        return $result;
    }

    /**
     * Read current overall feedback as catalog strings and boundaries,
     * for carry-forward and before/after change comparisons.
     *
     * @param int $quizid
     * @return array{feedbacktext: string[], feedbackboundaries: float[]}
     */
    public static function read_feedback(int $quizid): array {
        global $DB;

        $records = array_values($DB->get_records('quiz_feedback', ['quizid' => $quizid], 'mingrade DESC, id ASC'));
        $texts = [];
        $boundaries = [];
        foreach ($records as $index => $record) {
            $texts[] = (string) $record->feedbacktext;
            if ($index < count($records) - 1) {
                $boundaries[] = (float) $record->mingrade;
            }
        }
        return ['feedbacktext' => $texts, 'feedbackboundaries' => $boundaries];
    }

    /**
     * Translate catalog feedback strings to quiz_process_options() form
     * properties. At least one text is required (validate_combination_rules());
     * an empty list would crash quiz_after_add_or_update() with an undefined index.
     *
     * @param \stdClass $moduleinfo Updated in place.
     * @param string[] $texts
     * @param array<int, int|float|string> $boundaries
     * @return void
     */
    public static function apply_feedback_pseudofields(\stdClass $moduleinfo, array $texts, array $boundaries): void {
        $moduleinfo->feedbacktext = [];
        $moduleinfo->feedbackboundaries = [];
        foreach (array_values($texts) as $index => $text) {
            $moduleinfo->feedbacktext[$index] = ['text' => (string) $text, 'format' => FORMAT_HTML, 'itemid' => 0];
        }
        foreach (array_values($boundaries) as $index => $boundary) {
            $moduleinfo->feedbackboundaries[$index] = $boundary;
        }
    }

    /**
     * Form default for grade on creation (mod/quiz/mod_form.php:388,
     * quizconfig->maximumgrade). grade is blocklisted and has no catalog default.
     *
     * @return float
     */
    public static function default_grade(): float {
        $configured = (float) get_config('quiz', 'maximumgrade');
        return $configured > 0 ? $configured : 10.0;
    }

    /**
     * Change maximum grade through Moodle's grade_calculator::update_quiz_maximum_grade(),
     * replacing deprecated quiz_set_grade(). Rescale attempt grades and feedback
     * boundaries and update grade items/book through Moodle APIs, without direct
     * quiz-table writes (ADR 0016, Spec 0015 §5).
     *
     * @param int $quizid
     * @param float $newgrade
     * @return void
     */
    public static function apply_grade_change(int $quizid, float $newgrade): void {
        native_quiz_settings::create($quizid)->get_grade_calculator()->update_quiz_maximum_grade($newgrade);
    }

    /**
     * Catalog field name to actual moduleinfo property name: same exception
     * as in write_target.
     *
     * @param string $fieldname
     * @return string
     */
    public static function moduleinfo_property(string $fieldname): string {
        return $fieldname === 'idnumber' ? 'cmidnumber' : $fieldname;
    }

    /**
     * Quiz-only combination rules (Spec 0015 §2.2 category 4, quiz::combination_rules()),
     * all BEFORE writing (Spec 0015 §3.6). The date order is a catalog rule
     * decided by {@see write_target}; like there, a rule only fires when the
     * patch touches one of its fields, so unchanged legacy values are not re-judged.
     *
     * @param array $effective Checked target state ({@see write_target::$state}).
     * @param array $patch Fields explicitly set by the patch/bundle. Without feedbacktext,
     *        there is no feedback rule to check; carried-forward current values are valid.
     * @param float $grade Effective maximum grade for feedback boundaries: the new grade
     *        if changed in this call, otherwise the current grade.
     * @return void
     * @throws moodle_exception combinationruleviolation
     */
    public static function validate_combination_rules(array $effective, array $patch, float $grade): void {
        // The form validation is not run by update_moduleinfo. Validate before
        // any grade change or activity patch, and never coerce strings/bools to points.
        if (array_key_exists('gradepass', $patch)) {
            $passing = $patch['gradepass'];
            if (
                (!is_int($passing) && !is_float($passing)) || !is_finite((float) $passing)
                    || $passing < 0 || $passing > $grade
            ) {
                throw new moodle_exception('invalidquizgradepass', 'local_coursepilot', '', ['maximum' => $grade]);
            }
        }
        $gracetouched = array_key_exists('overduehandling', $patch) || array_key_exists('graceperiod', $patch);
        if ($gracetouched && ($effective['overduehandling'] ?? '') === 'graceperiod') {
            $min = (int) get_config('quiz', 'graceperiodmin');
            $grace = (int) ($effective['graceperiod'] ?? 0);
            if ($grace <= $min) {
                self::throw_combination_violation(
                    '"graceperiod" must exceed the server-wide minimum duration ('
                        . $min . ' seconds) when "overduehandling"="graceperiod".'
                );
            }
        }

        if (!array_key_exists('feedbacktext', $patch)) {
            return;
        }
        $texts = $patch['feedbacktext'];
        if (!is_array($texts) || !$texts) {
            self::throw_combination_violation('"feedbacktext" must contain at least one entry.');
        }
        $boundaries = $patch['feedbackboundaries'] ?? [];
        if (!is_array($boundaries) || count($boundaries) !== count($texts) - 1) {
            self::throw_combination_violation(
                '"feedbackboundaries" must have exactly one fewer entry than "feedbacktext" ('
                    . count($texts) . ' text(s), ' . count($boundaries) . ' boundary/boundaries).'
            );
        }

        $previous = null;
        foreach ($boundaries as $boundary) {
            $numeric = self::resolve_boundary($boundary, $grade);
            if ($numeric <= 0 || $numeric >= $grade) {
                self::throw_combination_violation(
                    '"feedbackboundaries" must be between 0 and the maximum grade (' . $grade . ').'
                );
            }
            if ($previous !== null && $numeric >= $previous) {
                self::throw_combination_violation('"feedbackboundaries" must be sorted descending.');
            }
            $previous = $numeric;
        }
    }

    /**
     * @param string $message
     * @return never
     * @throws moodle_exception combinationruleviolation
     */
    private static function throw_combination_violation(string $message): void {
        throw new moodle_exception('combinationruleviolation', 'local_coursepilot', '', [
            'modname' => 'quiz',
            'message' => $message,
        ]);
    }

    /**
     * Resolve an absolute or percentage boundary (e.g. "50%") against the
     * effective maximum grade, as quiz_process_options() does
     * (mod/quiz/lib.php:1005).
     *
     * @param int|float|string $boundary
     * @param float $grade
     * @return float
     */
    private static function resolve_boundary($boundary, float $grade): float {
        $value = trim((string) $boundary);
        if ($value !== '' && $value[strlen($value) - 1] === '%') {
            return ((float) trim(substr($value, 0, -1))) * $grade / 100.0;
        }
        return (float) $value;
    }
}
