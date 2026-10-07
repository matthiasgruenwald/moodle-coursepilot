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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Single question in its current version (#342): returns the latest
 * version of a question in a category, uniquely identified by name
 * OR by questionid (ID of any version of the same question) - used before
 * an edit via the local route to learn the current
 * questionid.
 *
 * Standalone port of local_coursepilot\external\get_question -
 * local_coursepilot has no runtime dependency on the other plugin per Spec 0012
 * (see get_course_catalog.php from #341, same finding).
 * Contract (field names, answer options including the correct answer) stays
 * identical to the local tool.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_question extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT,  'ID of the question bank category'),
            'name'       => new external_value(PARAM_TEXT, 'Name of the question (alternative to questionid)', VALUE_DEFAULT, ''),
            'questionid' => new external_value(PARAM_INT,  'questionid of any version of the question (alternative to name)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $categoryid
     * @param string $name
     * @param int $questionid
     * @return array
     */
    public static function execute(int $categoryid, string $name = '', int $questionid = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'name'       => $name,
            'questionid' => $questionid,
        ]);

        if ($params['name'] === '' && $params['questionid'] === 0) {
            throw new \invalid_parameter_exception(
                'Either name or questionid must be specified.');
        }

        $category = $DB->get_record('question_categories',
            ['id' => $params['categoryid']], '*', MUST_EXIST);
        $context = \context::instance_by_id($category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // moodle/question:view does not exist (any more); Moodle only knows
        // viewmine/viewall. viewall fits the read capability here.
        require_capability('moodle/question:viewall', $context);

        $entryid = $params['questionid'] > 0
            ? self::entry_id_from_questionid((int) $params['questionid'])
            : self::entry_id_from_name((int) $params['categoryid'], (string) $params['name']);

        if ($entryid === 0) {
            throw new \moodle_exception('notfound', 'error', '',
                null, 'No question found for the given criteria.');
        }

        $latest = $DB->get_record_sql(
            'SELECT * FROM {question_versions}
              WHERE questionbankentryid = ?
           ORDER BY version DESC',
            [$entryid],
            IGNORE_MULTIPLE
        );
        if (!$latest) {
            throw new \moodle_exception('notfound', 'error', '',
                null, 'No version found for questionbankentryid ' . $entryid . '.');
        }

        $question = $DB->get_record('question',
            ['id' => $latest->questionid], '*', MUST_EXIST);

        $answers = $DB->get_records('question_answers',
            ['question' => $question->id], 'id ASC');

        $answerlist = [];
        $correctindex = -1;
        $i = 0;
        foreach ($answers as $a) {
            $answerlist[] = [
                'id'       => (int) $a->id,
                'answer'   => (string) $a->answer,
                'fraction' => (float) $a->fraction,
                'feedback' => (string) $a->feedback,
                'correct'  => (float) $a->fraction > 0,
            ];
            if ((float) $a->fraction >= 1.0 && $correctindex === -1) {
                $correctindex = $i;
            }
            $i++;
        }
        $multichoice = $DB->get_record('qtype_multichoice_options', ['questionid' => $question->id]);
        $selectionmode = $multichoice && empty($multichoice->single) ? 'multiple' : 'single';

        return [
            'questionid'          => (int) $question->id,
            'questionbankentryid' => (int) $entryid,
            'categoryid'          => (int) $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $entryid], MUST_EXIST),
            'version'             => (int) $latest->version,
            'name'                => (string) $question->name,
            'questiontext'        => (string) $question->questiontext,
            'generalfeedback'     => (string) $question->generalfeedback,
            'qtype'               => (string) $question->qtype,
            'defaultmark'         => (float)  $question->defaultmark,
            'answers'             => $answerlist,
            'correctindex'        => $correctindex,
            'selectionmode'       => $selectionmode,
        ];
    }

    /**
     * Determine the entry ID via a known questionid (any version).
     *
     * @param int $questionid
     * @return int
     */
    private static function entry_id_from_questionid(int $questionid): int {
        global $DB;
        $v = $DB->get_record('question_versions', ['questionid' => $questionid]);
        return $v ? (int) $v->questionbankentryid : 0;
    }

    /**
     * Determine the entry ID via category + question name (unambiguous based on the
     * latest version, since the name historically lives in question.name). On
     * multiple matches the one with the highest version is taken.
     *
     * @param int $categoryid
     * @param string $name
     * @return int
     */
    private static function entry_id_from_name(int $categoryid, string $name): int {
        global $DB;
        $sql = 'SELECT qbe.id AS entryid, qv.version AS version
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q           ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND q.name = :name
              ORDER BY qbe.id ASC, qv.version DESC';
        $rows = $DB->get_records_sql($sql, ['catid' => $categoryid, 'name' => $name]);
        if (!$rows) {
            return 0;
        }
        $first = reset($rows);
        return (int) $first->entryid;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionid'          => new external_value(PARAM_INT,   'ID of the latest-version question row'),
            'questionbankentryid' => new external_value(PARAM_INT,   'ID of the question_bank_entries row (question identity)'),
            'categoryid'          => new external_value(PARAM_INT,   'Current question bank category of the question'),
            'version'             => new external_value(PARAM_INT,   'Current version number'),
            'name'                => new external_value(PARAM_TEXT,  'Name of the question'),
            'questiontext'        => new external_value(PARAM_RAW,   'Question text (HTML)'),
            'generalfeedback'     => new external_value(PARAM_RAW,   'General feedback (HTML)'),
            'qtype'               => new external_value(PARAM_TEXT,  'Question type (usually multichoice)'),
            'defaultmark'         => new external_value(PARAM_FLOAT, 'Default mark of the question'),
            'answers'             => new external_multiple_structure(
                new external_single_structure([
                    'id'       => new external_value(PARAM_INT,   'question_answers.id'),
                    'answer'   => new external_value(PARAM_RAW,   'Answer text (HTML)'),
                    'fraction' => new external_value(PARAM_FLOAT, 'Weight of the answer'),
                    'feedback' => new external_value(PARAM_RAW,   'Answer-specific feedback (HTML)'),
                    'correct'  => new external_value(PARAM_BOOL,  'Answer has a positive weight'),
                ]),
                'Answer options in creation order'
            ),
            'correctindex'        => new external_value(PARAM_INT,   '0-based index of the correct answer in answers[] (-1 if none detected)'),
            'selectionmode'       => new external_value(PARAM_ALPHA, 'single or multiple'),
        ]);
    }
}
