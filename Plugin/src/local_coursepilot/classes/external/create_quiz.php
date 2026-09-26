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
use local_coursepilot\catalog\quiz;
use local_coursepilot\catalog\quiz_write_bridge;
use local_coursepilot\catalog\shared_block;
use local_coursepilot\write_gate;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Das Quiz-Gegenstueck zu {@see create_module} (Spec 0015 §5, Ticket #398):
 * quiz ist eine begruendete Ausnahme vom generischen Vehikel, der Katalog
 * (#383) fuehrt es trotzdem mit `schreibweg(): 'update_quiz_settings'`.
 *
 * Wie beim generischen Anlegen (Spec 0015 §3.4): fehlende Felder kommen aus
 * dem katalogisierten FORMULAR-Default, ein Pflichtfeld ganz ohne Default
 * (name, intro, preferredbehaviour, subnet, browsersecurity) muss die
 * Lehrkraft nennen. "grade" ist kein Katalogfeld (Sperrliste) - es kommt aus
 * dem eigenen Parameter "grade" bzw. dem Moodle-Formular-Default
 * (Admin-Einstellung quiz/maximumgrade), niemals aus fields_json.
 *
 * Die drei Modus-Buendel kommen aus dem Katalog ({@see quiz::bundles()}) -
 * ein Buendelwert gilt nur fuer Felder, die fields_json nicht bereits selbst
 * nennt (Spec 0015 §2.4).
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
                'Modus-Buendel: "mini-check", "lernstandscheck" oder "abschlusstest". Buendelwerte gelten nur '
                    . 'fuer Felder, die fields_json nicht bereits selbst nennt. Leer = kein Buendel.',
                VALUE_DEFAULT,
                ''
            ),
            'grade' => new external_value(
                PARAM_FLOAT,
                'Maximale Bewertung des Tests. -1 = Moodle-Formular-Default (Admin-Einstellung quiz/maximumgrade) '
                    . 'verwenden.',
                VALUE_DEFAULT,
                -1.0
            ),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $fieldsjson
     * @param string $mode
     * @param float $grade
     * @return array
     */
    public static function execute(int $courseid, int $sectionnum, string $fieldsjson, string $mode = '', float $grade = -1.0): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'fields_json' => $fieldsjson,
            'mode' => $mode,
            'grade' => $grade,
        ]);

        $coursecontext = context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        // Native Berechtigungspruefung vorgezogen, wie {@see create_module::execute()}.
        require_capability('moodle/course:manageactivities', $coursecontext);

        // Billigteil der Selbstfreigabe (Spec 0015 §11, ADR 0017, Ticket #399):
        // dasselbe Regime wie fuer das generische Vehikel gilt unveraendert
        // fuer das Quiz-Einzelwerkzeug. Lesen bleibt unberuehrt.
        write_gate::assert_writable('quiz');

        $patch = json_decode($params['fields_json'], true);
        if (!is_array($patch) || json_last_error() !== JSON_ERROR_NONE) {
            throw new moodle_exception('invalidpatchjson', 'local_coursepilot');
        }

        $merged = array_merge(self::bundle_fields($params['mode']), $patch);

        quiz_write_bridge::validate_fields($merged);
        $newgrade = $params['grade'] >= 0 ? $params['grade'] : quiz_write_bridge::default_grade();
        $effective = array_merge(self::catalog_defaults(), $merged);
        quiz_write_bridge::validate_combination_rules($effective, $merged, $newgrade);
        self::assert_no_required_field_missing($merged);
        quiz_write_bridge::assert_stealth_allowed($merged);

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

        self::fill_form_defaults($moduleinfo, $fieldstowrite);
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
     * Modus-Buendel aus dem Katalog, oder leer ohne Buendel.
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
                'modi' => implode(', ', array_keys($bundles)),
            ]);
        }
        return $bundles[$mode];
    }

    /**
     * Katalog-Defaults (Kategorie 1) als Feldname => Wert - fuer die
     * Kombinationsregeln beim Anlegen (z.B. timeopen/timeclose sind ohne
     * Angabe beide 0 und verletzen dadurch keine Regel).
     *
     * @return array<string, mixed>
     */
    private static function catalog_defaults(): array {
        $defaults = [];
        foreach (array_merge(shared_block::fields(), quiz::fields()) as $field) {
            if ($field->default !== null) {
                $defaults[$field->name] = $field->default;
            }
        }
        return $defaults;
    }

    /**
     * Ein Pflichtfeld ganz ohne Formular-Default muss die Lehrkraft nennen -
     * identische Regel wie {@see create_module::assert_no_required_field_missing()}.
     *
     * @param array $merged
     * @return void
     * @throws moodle_exception requiredfieldwithoutdefault
     */
    private static function assert_no_required_field_missing(array $merged): void {
        $allfields = array_merge(shared_block::fields(), quiz::fields(), quiz::pseudofields());
        foreach ($allfields as $field) {
            if (!$field->required || $field->default !== null) {
                continue;
            }
            if (array_key_exists($field->name, $merged)) {
                continue;
            }
            throw new moodle_exception('requiredfieldwithoutdefault', 'local_coursepilot', '', [
                'field' => $field->name,
                'modname' => 'quiz',
            ]);
        }
    }

    /**
     * Fuellt jedes vom Patch/Buendel nicht genannte Feld mit seinem
     * katalogisierten FORMULAR-Default - identisches Prinzip wie
     * {@see create_module::fill_form_defaults()}. Die 32 Review-Checkboxen
     * sind ganz normale Pseudofelder mit Default 0 (keine Sonderbehandlung
     * noetig, anders als beim Patch: es gibt beim Anlegen keinen Ist-Stand
     * zum Carry-forward).
     *
     * @param \stdClass $moduleinfo Wird in-place ergaenzt.
     * @param array $merged
     * @return void
     */
    private static function fill_form_defaults(\stdClass $moduleinfo, array $merged): void {
        $allfields = array_merge(shared_block::fields(), quiz::fields(), quiz::pseudofields());
        foreach ($allfields as $field) {
            if (array_key_exists($field->name, $merged) || $field->default === null) {
                continue;
            }
            $moduleinfo->{quiz_write_bridge::moduleinfo_property($field->name)} = $field->default;
        }
    }

    /**
     * Die tatsaechlich vom Patch/Buendel gesetzten Felder mit ihrem Wert,
     * plus "grade" (immer gesetzt, kommt nie aus fields_json) und
     * ausgeloeste Nebenwirkungen - identisches Prinzip wie
     * {@see create_module::report_and_side_effects()}.
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
     * Die Lehrkraft-deutsche Anlegemeldung (Spec 0015 §3.4/§5).
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
