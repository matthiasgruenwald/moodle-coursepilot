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

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\catalog\learner_locks;
use local_coursepilot\catalog\quiz;
use local_coursepilot\catalog\quiz_write_bridge;
use local_coursepilot\catalog\write_target;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Quiz counterpart to {@see create_module} (Spec 0015 §5, #398): quiz is
 * a justified exception to the generic tool, but its catalog (#383)
 * still declares write_path: update_quiz_settings.
 *
 * Like generic creation (Spec 0015 §3.4), missing fields use cataloged form
 * defaults. Required fields without defaults (name, intro, preferredbehaviour,
 * subnet, browsersecurity) must be provided. grade is blocked in fields_json:
 * it comes from its own parameter or quiz/maximumgrade form default.
 *
 * The three mode bundles come from {@see quiz::bundles()}. Bundle values
 * apply only to fields not explicitly supplied in fields_json (Spec §2.4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class create_quiz extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'fields_json' => new external_value(
                PARAM_RAW,
                'JSON object field name => value - missing fields come from the form default. Required fields '
                    . 'without a form default must be named: "name", "intro", "subnet" (empty = no restriction), '
                    . '"browsersecurity" ("-" = no restriction); "preferredbehaviour" otherwise comes from "mode". '
                    . '"grade"/"sumgrades" are NOT possible here (blocked), see the "grade" parameter.'
            ),
            'mode' => new external_value(
                PARAM_ALPHANUMEXT,
                'Mode bundle: "mini-check", "progress-check" or "final-test". Bundle values apply only '
                    . 'to fields not explicitly supplied in fields_json. Empty = no bundle.',
                VALUE_DEFAULT,
                ''
            ),
            'grade' => new external_value(
                PARAM_FLOAT,
                'Maximum quiz grade. -1 = use the Moodle form default (admin setting quiz/maximumgrade)'
                    . '.',
                VALUE_DEFAULT,
                -1.0
            ),
            learner_locks::PARAMETER => learner_locks::confirm_parameter(),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $fieldsjson
     * @param string $mode
     * @param float $grade
     * @param string[] $confirmlearnerlocks
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionnum,
        string $fieldsjson,
        string $mode = '',
        float $grade = -1.0,
        array $confirmlearnerlocks = []
    ): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'fields_json' => $fieldsjson,
            'mode' => $mode,
            'grade' => $grade,
            learner_locks::PARAMETER => $confirmlearnerlocks,
        ]);

        $coursecontext = context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        // Check native editing permission early, as in {@see create_module::execute()}.
        require_capability('moodle/course:manageactivities', $coursecontext);

        // Cheap write self-check (Spec 0015 §11, ADR 0017, #399): same policy
        // as generic creation applies to the quiz-specific tool. Reads are unaffected.
        write_gate::assert_writable('quiz');

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        $bundle = self::bundle_fields($params['mode']);
        $merged = array_merge($bundle, $patch);

        // Catalog rules (fields, dates, stealth, required fields, learner
        // locks) are decided once in the write target (#646); the locks of the
        // chosen mode are confirmed by choosing it.
        $target = write_target::create(
            quiz::class,
            $merged,
            learner_locks::confirmed_with_mode($params[learner_locks::PARAMETER], $bundle, $patch)
        );
        $newgrade = $params['grade'] >= 0 ? $params['grade'] : quiz_write_bridge::default_grade();
        quiz_write_bridge::validate_combination_rules($target->state, $merged, $newgrade);

        $course = get_course($params['courseid']);
        require_once($CFG->dirroot . '/course/modlib.php');
        [$module] = \can_add_moduleinfo($course, 'quiz', $params['sectionnum']);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->module = (int) $module->id;
        $moduleinfo->section = $params['sectionnum'];
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
            'message' => self::build_message($createdfields, $sideeffects),
            'created_fields' => $createdfields,
            'side_effects' => $sideeffects,
        ];
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
     * grade (always set, never from fields_json), and side effects. Same
     * principle as {@see create_module::report_and_side_effects()}.
     *
     * @param array $merged
     * @param float $grade
     * @return array{0: array, 1: string[]}
     */
    private static function report_and_side_effects(array $merged, float $grade): array {
        $createdfields = [];
        foreach ($merged as $fieldname => $value) {
            $createdfields[] = ['field' => $fieldname, 'value_json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
        }
        $createdfields[] = ['field' => 'grade', 'value_json' => json_encode($grade, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];

        $sideeffects = [];
        if ((int) ($merged['timeopen'] ?? 0) > 0 || (int) ($merged['timeclose'] ?? 0) > 0) {
            $sideeffects[] = 'Der Kalendereintrag fuer den Test wurde angelegt.';
        }

        return [$createdfields, $sideeffects];
    }

    /**
     * Teacher-facing creation message (Spec 0015 §3.4/§5).
     *
     * @param array $createdfields
     * @param string[] $sideeffects
     * @return string
     */
    private static function build_message(array $createdfields, array $sideeffects): string {
        $parts = [];
        foreach ($createdfields as $field) {
            $parts[] = '"' . $field['field'] . '" = ' . $field['value_json'];
        }
        $message = 'Test angelegt: ' . implode(', ', $parts) . '.';

        if ($sideeffects) {
            $message .= ' ' . implode(' ', $sideeffects);
        }

        return $message;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the newly created quiz'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing German creation message'),
            'created_fields' => new external_multiple_structure(
                new external_single_structure([
                    'field' => new external_value(PARAM_TEXT, 'Field name'),
                    'value_json' => new external_value(PARAM_RAW, 'JSON-encoded, actually persisted value'),
                ]),
                'One entry per field set by the patch/bundle, plus "grade"'
            ),
            'side_effects' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Teacher-facing German side-effect note'),
                'Triggered side effects, empty when none were triggered'
            ),
        ]);
    }
}
