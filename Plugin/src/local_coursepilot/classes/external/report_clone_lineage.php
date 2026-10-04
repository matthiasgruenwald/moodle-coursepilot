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

use context;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Report question lineage after cloning (Spec 0017 §7.5, #422). For each
 * quiz question, identify whether clone_activity (#421) made an own copy or
 * kept a reference to the source course's bank entry. Only reads
 * question_references: no idnumber, question or reference is changed.
 * Identity binding (ADR 0015) still happens at the first actual write to an
 * individual question, e.g. update_mc_question.
 *
 * Own copy versus shared reference: cross-course backup/restore copies only
 * question categories inside the single-activity backup scope, usually the
 * quiz's own module context. References outside that scope, e.g. to a separate
 * question bank activity in the source course, retain their questionbankentryid.
 * Compare the category's course (via contextid) with the quiz's course:
 * same course means own copy, another course means shared reference.
 *
 * The response omits question_suspect_gate's shared envelope. It was initially
 * included for consistency but this read-only tool never triggers a gate;
 * five always-empty fields add noise (#424 follow-up 4).
 *
 * The published contract directly uses message/source_course_id instead of
 * legacy meldung/quellkurs_id (#572, Spec 0025 §A). own_copy/shared_reference
 * remain domain vocabulary, glossed in execute_returns(), as with list_skills' kind (#571).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class report_clone_lineage extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the quiz (mod_quiz), usually returned by clone_activity'),
        ]);
    }

    /**
     * @param int $cmid
     * @return array
     * @throws invalid_parameter_exception
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        if ($cm->modname !== 'quiz') {
            throw new invalid_parameter_exception('cmid muss ein Test (mod_quiz) sein, hier: "' . $cm->modname . '".');
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // moodle/question:view no longer exists. Use moodle/question:viewall/viewmine,
        // as in get_question and export_questions_xml.
        require_capability('moodle/question:viewall', $context);

        $rows = self::slot_lineage_rows((int) $cm->instance);
        $courseid = (int) $cm->course;

        $questions = [];
        $contextcoursecache = [];
        foreach ($rows as $row) {
            $entrycourseid = self::course_of_context((int) $row->categorycontextid, $contextcoursecache);
            $owncopy = $entrycourseid !== 0 && $entrycourseid === $courseid;

            $questions[] = [
                'slot' => (int) $row->slot,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid' => (int) $row->questionid,
                'name' => (string) $row->questionname,
                'idnumber' => (string) ($row->entryidnumber ?? ''),
                'status' => $owncopy ? 'own_copy' : 'shared_reference',
                'source_course_id' => $owncopy ? 0 : $entrycourseid,
            ];
        }

        return [
            'cmid' => $cm->id,
            'questions' => $questions,
            'message' => self::build_message($questions),
        ];
    }

    /**
     * Quiz slots referencing the latest question version, following
     * add_questions_to_quiz::slot_state()'s joins. Include the category context
     * for course attribution and the bank entry idnumber.
     *
     * @param int $quizid
     * @return \stdClass[]
     */
    private static function slot_lineage_rows(int $quizid): array {
        global $DB;

        return array_values($DB->get_records_sql(
            'SELECT qs.slot, qr.questionbankentryid, qbe.idnumber AS entryidnumber, qc.contextid AS categorycontextid,
                    qv.questionid, q.name AS questionname
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = :component AND qr.questionarea = :area
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                       WHERE v.questionbankentryid = qbe.id)
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]
        ));
    }

    /**
     * Course ID belonging to a context, or zero for e.g. the system context.
     * Cache by context ID so a quiz with many questions in one category does
     * not repeatedly resolve it.
     *
     * @param int $contextid
     * @param array<int, int> $cache By reference, contextid => courseid
     * @return int
     */
    private static function course_of_context(int $contextid, array &$cache): int {
        if (array_key_exists($contextid, $cache)) {
            return $cache[$contextid];
        }

        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        $courseid = $coursecontext ? (int) $coursecontext->instanceid : 0;

        $cache[$contextid] = $courseid;
        return $courseid;
    }

    /**
     * @param array $questions
     * @return string
     */
    private static function build_message(array $questions): string {
        if (!$questions) {
            return 'Der Test enthält keine Fragen.';
        }

        $owncopies = 0;
        $shared = 0;
        foreach ($questions as $question) {
            if ($question['status'] === 'own_copy') {
                $owncopies++;
            } else {
                $shared++;
            }
        }

        if ($shared === 0) {
            return $owncopies . ' Frage(n) als eigene Kopie angelegt.';
        }
        if ($owncopies === 0) {
            return $shared . ' Frage(n) zeigen weiterhin auf den Quellkurs (geteilte Referenz) - eine Korrektur '
                . 'dort ändert auch dort die Frage.';
        }

        return $owncopies . ' Frage(n) als eigene Kopie angelegt, ' . $shared . ' Frage(n) zeigen weiterhin auf '
            . 'den Quellkurs (geteilte Referenz).';
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the inspected quiz'),
            'questions' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Slot number in the quiz'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Question identity (bank entry) referenced by the slot'),
                'questionid' => new external_value(PARAM_INT, 'ID of the latest question version'),
                'name' => new external_value(PARAM_TEXT, 'Question name'),
                'idnumber' => new external_value(PARAM_TEXT, 'Bank entry idnumber, empty if none is assigned'),
                'status' => new external_value(
                    PARAM_ALPHANUMEXT,
                    '"own_copy" (own copy) or "shared_reference" (shared reference)'
                ),
                'source_course_id' => new external_value(
                    PARAM_INT,
                    'Course ID the reference still points to (0 when status = "own_copy")'
                ),
            ]), 'One result per quiz slot, ordered by slot'),
            'message' => new external_value(PARAM_RAW, 'Localized teacher-facing summary of own copies and shared references'),
        ]);
    }
}
