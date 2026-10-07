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
use local_coursepilot\history\version_writer;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Quiz attachment (Spec 0017 §7.4, ticket #420): appends questions in the
 * given order via Moodle's own {@see quiz_add_quiz_question()}
 * - the same function the quiz editing page uses, including the duplicate
 * check via questionbankentryid (false = already present, so no second
 * slot). No removing, no reordering, no page breaks -
 * a pure append operation, layout stays in the Moodle UI (#365).
 *
 * Attempt gate BEFORE any write (quiz_has_attempts()): if attempts already
 * exist, the whole call aborts, no partial success - the same principle
 * as {@see \local_coursepilot\quiz\arrangement::restore()}.
 *
 * Change history: quiz_add_quiz_question() triggers the native
 * slot_created event, which does NOT count among the 16 observed
 * mod_quiz structure events (db/events.php: new question = content,
 * not an arrangement change). This endpoint therefore explicitly captures
 * the same arrangement state as the observer
 * ({@see version_writer::capture_on_update()}) - exactly once, only if
 * at least one question was actually newly appended.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class add_questions_to_quiz extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the quiz'),
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'questionid of any version of the question to append'),
                'Questions in the order they should be appended, at least one'
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param array $questionids
     * @return array
     */
    public static function execute(int $cmid, array $questionids): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'questionids' => $questionids,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('mod/quiz:manage', $context);

        if (empty($params['questionids'])) {
            throw new \invalid_parameter_exception('Specify at least one question.');
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        // Attempt gate BEFORE any write - no partial success, no half
        // filled slot list.
        if (quiz_has_attempts((int) $quiz->id)) {
            throw new moodle_exception('addquestionstoquizblocked', 'local_coursepilot', '', ['quizid' => $quiz->id]);
        }

        $quiz->cmid = (int) $cm->id;

        $appended = [];
        $anyadded = false;
        foreach ($params['questionids'] as $questionid) {
            $question = $DB->get_record('question', ['id' => (int) $questionid], '*', MUST_EXIST);
            question_require_capability_on($question, 'use');

            // quiz_add_quiz_question() returns an explicit false ONLY in the
            // duplicate case - on success the function falls through without
            // "return" (PHP then yields null, not true). "!== false"
            // is therefore the correct success check, not truthiness.
            $result = quiz_add_quiz_question((int) $questionid, $quiz);
            $added = $result !== false;
            $anyadded = $anyadded || $added;
            $entry = get_question_bank_entry((int) $questionid);

            $appended[] = [
                'questionid' => (int) $questionid,
                'questionbankentryid' => (int) $entry->id,
                'name' => (string) $question->name,
                'added' => (bool) $added,
            ];
        }

        if ($anyadded) {
            version_writer::capture_on_update((int) $cm->id, (int) $USER->id);
        }

        return [
            'cmid' => (int) $cm->id,
            'message' => self::build_message($appended),
            'appended' => $appended,
            'slots' => self::slot_state((int) $quiz->id),
        ];
    }

    /**
     * The teacher-facing message: what was appended, what was skipped.
     *
     * @param array $appended
     * @return string
     */
    private static function build_message(array $appended): string {
        $added = array_filter($appended, static fn(array $item): bool => $item['added']);
        $skipped = array_filter($appended, static fn(array $item): bool => !$item['added']);

        if (!$added && !$skipped) {
            return 'No question appended.';
        }

        $parts = [];
        if ($added) {
            $names = array_map(static fn(array $item): string => '"' . $item['name'] . '"', $added);
            $parts[] = count($added) . ' question(s) appended: ' . implode(', ', $names) . '.';
        }
        if ($skipped) {
            $names = array_map(static fn(array $item): string => '"' . $item['name'] . '"', $skipped);
            $parts[] = count($skipped) . ' already present, skipped: ' . implode(', ', $names) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * Slot state of the quiz after appending - bank entry, question ID and
     * version number per slot (latest version, same join pattern as
     * {@see \local_coursepilot\external\get_quiz_cleanup_plan::execute()}),
     * so the quiz can be checked without opening it.
     *
     * @param int $quizid
     * @return array
     */
    private static function slot_state(int $quizid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT qs.slot, qr.questionbankentryid, qv.questionid, qv.version, q.name AS questionname
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = :component AND qr.questionarea = :area
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                       WHERE v.questionbankentryid = qbe.id)
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]
        );

        $slots = [];
        foreach ($rows as $row) {
            $slots[] = [
                'slot' => (int) $row->slot,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid' => (int) $row->questionid,
                'version' => (int) $row->version,
                'name' => (string) $row->questionname,
            ];
        }
        return $slots;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the quiz'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing message: appended vs. skipped'),
            'appended' => new external_multiple_structure(new external_single_structure([
                'questionid' => new external_value(PARAM_INT, 'Requested questionid'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Question identity (bank entry)'),
                'name' => new external_value(PARAM_TEXT, 'Question name'),
                'added' => new external_value(PARAM_BOOL, 'true = newly appended, false = already in the quiz, skipped'),
            ]), 'Result per requested question, in the given order'),
            'slots' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Slot number'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Question identity (bank entry)'),
                'questionid' => new external_value(PARAM_INT, 'ID of the latest question version'),
                'version' => new external_value(PARAM_INT, 'Version number of the latest question version'),
                'name' => new external_value(PARAM_TEXT, 'Question name'),
            ]), 'Slot state of the quiz after appending, ascending by slot'),
        ]);
    }
}
