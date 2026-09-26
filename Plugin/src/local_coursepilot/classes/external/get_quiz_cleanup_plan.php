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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');

/**
 * Bereinigungsplan fuer Tests (#342): baut einen manuellen,
 * nicht-destruktiven Plan fuer Quiz-Slots, die in einer neuen Quizversion
 * entfallen. Coursepilot loescht selbst weder Quiz-Slots noch Fragen - die
 * Antwort nennt Fundstelle (Slot, Frage, Kategorie) und einen direkten
 * Moodle-Link zur manuellen Bearbeitung.
 *
 * Eigenstaendige Portierung von
 * local_coursepilot\external\get_quiz_cleanup_plan - local_coursepilot hat
 * laut Spec 0012 keine Laufzeitabhaengigkeit auf das andere Plugin (siehe
 * get_course_catalog.php aus #341, derselbe Fund). Vertrag (Feldnamen,
 * nicht-destruktive Handlungsanweisung) bleibt identisch zum lokalen
 * Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_quiz_cleanup_plan extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the quiz'),
            'keep_questionbankentryids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Question identity that remains in the new quiz version'),
                'Question-bank-entry-IDs of the new quiz version'
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param array $keep_questionbankentryids
     * @return array
     */
    public static function execute(int $cmid, array $keep_questionbankentryids): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'keep_questionbankentryids' => $keep_questionbankentryids,
        ]);
        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, name', MUST_EXIST);
        $keep = array_flip(array_map('intval', $params['keep_questionbankentryids']));
        $rows = $DB->get_records_sql(
            'SELECT qs.slot, qr.questionbankentryid, qbe.questioncategoryid, qc.name AS categoryname,
                    qv.questionid, qv.version, q.name AS questionname
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = :component AND qr.questionarea = :area
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid
                AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                   WHERE v.questionbankentryid = qbe.id)
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quiz->id]
        );

        $removals = [];
        foreach ($rows as $row) {
            if (isset($keep[(int) $row->questionbankentryid])) {
                continue;
            }
            $removals[] = [
                'slot' => (int) $row->slot,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid' => (int) $row->questionid,
                'version' => (int) $row->version,
                'questionname' => (string) $row->questionname,
                'categoryid' => (int) $row->questioncategoryid,
                'categoryname' => (string) $row->categoryname,
                'reason' => 'Nicht in der neuen Quizversion vorgesehen. Nur aus diesem Quiz entfernen; die Frage wird nicht aus der Fragensammlung gelöscht und bleibt wiederverwendbar.',
            ];
        }

        return [
            'quizname' => (string) $quiz->name,
            'editurl' => $CFG->wwwroot . '/mod/quiz/edit.php?cmid=' . $cm->id,
            'removals' => $removals,
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'quizname' => new external_value(PARAM_TEXT, 'Quiz name'),
            'editurl' => new external_value(PARAM_URL, 'Direct link to the quiz edit page in Moodle'),
            'removals' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Quiz slot that can be manually removed from the quiz'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Reusable question identity'),
                'questionid' => new external_value(PARAM_INT, 'Current question version'),
                'version' => new external_value(PARAM_INT, 'Version number of the question'),
                'questionname' => new external_value(PARAM_TEXT, 'Question name'),
                'categoryid' => new external_value(PARAM_INT, 'Question category ID'),
                'categoryname' => new external_value(PARAM_TEXT, 'Question category'),
                'reason' => new external_value(PARAM_TEXT, 'Manual, non-destructive instruction'),
            ]), 'Slots that Coursepilot does not delete'),
        ]);
    }
}
