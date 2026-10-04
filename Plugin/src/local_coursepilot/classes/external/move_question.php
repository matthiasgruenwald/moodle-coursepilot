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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\question_suspect_gate;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');

/**
 * Moves a question bank entry with all of its versions to another
 * category (Spec 0017 §7.1, ticket #414) - port of
 * local_coursepilot\external\move_question, now with a suspect-case gate
 * *before* the move.
 *
 * The core (question_move_questions_to_category() in lib/questionlib.php,
 * via core_question\external\move_questions ->
 * qbank_bulkmove\helper::bulk_move_questions()) silently resolves an
 * idnumber collision in the target category with a "_N" suffix -
 * exactly at the moment the teacher thinks they are merely tidying up, this
 * tears the lineage apart (ADR 0015). This endpoint checks the same collision
 * (unique DB index (questioncategoryid, idnumber), see
 * lib/db/install.xml) *before* the call and reports it via the shared
 * suspect-case gate format ({@see \local_coursepilot\question_suspect_gate})
 * instead of silently leaving it to the core suffix mechanism.
 * If the teacher explicitly confirms ("confirmed": true), the core suffix
 * mechanism deliberately runs - it is non-destructive (suffix instead of
 * overwriting) and was already the way, before this ticket, in which a
 * question keeps its old idnumber when in doubt.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class move_question extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT, 'questionid of any version of the question to move'),
            'targetcategoryid' => new external_value(PARAM_INT, 'ID of the target question bank category'),
            'confirmed' => new external_value(
                PARAM_BOOL,
                'true explicitly confirms a previously reported suspect case (idnumber collision in the '
                    . 'target category) and moves it anyway. Omit or false on the first call.',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * @param int $questionid
     * @param int $targetcategoryid
     * @param bool $confirmed
     * @return array
     */
    public static function execute(int $questionid, int $targetcategoryid, bool $confirmed = false): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'targetcategoryid' => $targetcategoryid,
            'confirmed' => $confirmed,
        ]);

        $version = $DB->get_record('question_versions', ['questionid' => $params['questionid']], '*', MUST_EXIST);
        $entry = $DB->get_record('question_bank_entries', ['id' => $version->questionbankentryid], '*', MUST_EXIST);
        $sourcecategory = $DB->get_record('question_categories', ['id' => $entry->questioncategoryid], '*', MUST_EXIST);
        $targetcategory = $DB->get_record('question_categories', ['id' => $params['targetcategoryid']], '*', MUST_EXIST);

        $sourcecontext = context::instance_by_id((int) $sourcecategory->contextid, MUST_EXIST);
        self::validate_context($sourcecontext);
        require_capability('local/coursepilot:use', $sourcecontext);

        $targetcontext = context::instance_by_id((int) $targetcategory->contextid, MUST_EXIST);
        if ((int) $targetcontext->id !== (int) $sourcecontext->id) {
            self::validate_context($targetcontext);
        }
        require_capability('local/coursepilot:use', $targetcontext);
        require_capability('moodle/question:add', $targetcontext);

        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id], 'version ASC');
        $questionids = array_values(array_map(static fn($item): int => (int) $item->questionid, $versions));
        foreach ($questionids as $versionquestionid) {
            $question = $DB->get_record('question', ['id' => $versionquestionid], '*', MUST_EXIST);
            question_require_capability_on($question, 'move');
        }

        $idnumber = (string) ($entry->idnumber ?? '');
        $collision = question_suspect_gate::find_idnumber_collision(
            (int) $targetcategory->id,
            $idnumber,
            (int) $entry->id
        );

        if ($collision !== null && !$params['confirmed']) {
            // Suspect case: nothing is written (ADR 0015, Spec 0017 §7.1).
            $latestversion = end($versions);
            $newquestiontext = $latestversion
                ? (string) $DB->get_field('question', 'questiontext', ['id' => (int) $latestversion->questionid], MUST_EXIST)
                : '';

            return array_merge(
                [
                    'status' => 'suspect',
                    'questionbankentryid' => (int) $entry->id,
                    'versionids' => [],
                    'idnumber_disambiguated' => false,
                    'message' => 'Suspect case: the target category already has an entry with the '
                        . 'idnumber "' . $idnumber . '". Nothing was moved. To move despite the collision, '
                        . 'call again with confirmed=true.',
                ],
                question_suspect_gate::response($collision, (int) $targetcategory->id, $newquestiontext)
            );
        }

        // Core quirk (lib/questionlib.php::question_move_questions_to_category()):
        // the function moves per *version* in $questionids, not per
        // bank entry - if several version ids of the same question are
        // passed, the second pass collides with the entry already moved
        // by the first iteration and wrongly appends a suffix to the
        // idnumber although there is no real collision. A bank entry is
        // ONE move (the entryid carries questioncategoryid, not the
        // individual version) - exactly one version id suffices, all
        // versions hang off the same entryid and move along.
        //
        // Core quirk #2: move_questions::execute() is declared as "?string"
        // but falls through without "return null;" when $returnurlstring is
        // empty - a TypeError ("none returned"). A placeholder path avoids
        // that, as in the local model
        // local_coursepilot\external\move_question - the returned URL
        // is discarded here anyway.
        \core_question\external\move_questions::execute(
            $targetcontext->id,
            $targetcategory->id,
            (string) $questionids[0],
            '/question/bank/managecategories/category.php'
        );

        $entry = $DB->get_record('question_bank_entries', ['id' => $entry->id], '*', MUST_EXIST);
        $versions = $DB->get_records('question_versions', ['questionbankentryid' => $entry->id], 'version ASC');
        $newidnumber = (string) ($entry->idnumber ?? '');
        $idnumberdisambiguated = $collision !== null && $newidnumber !== $idnumber;

        $message = 'Question moved to the target category.';
        if ($idnumberdisambiguated) {
            $message .= ' The idnumber "' . $idnumber . '" was already taken in the target category and was '
                . 'renamed to "' . $newidnumber . '".';
        }

        return array_merge(
            [
                'status' => 'moved',
                'questionbankentryid' => (int) $entry->id,
                'versionids' => array_values(array_map(static fn($item): int => (int) $item->questionid, $versions)),
                'idnumber_disambiguated' => $idnumberdisambiguated,
                'message' => $message,
            ],
            question_suspect_gate::empty_result()
        );
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge(
            [
                'status' => new external_value(PARAM_ALPHA, '"moved" (moved) or "suspect" (suspect case)'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Unchanged identity of the question_bank_entries row'),
                'versionids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'questionid of a retained version'),
                    'All versions of the question in version order (empty for "suspect")'
                ),
                'idnumber_disambiguated' => new external_value(
                    PARAM_BOOL,
                    'true if a confirmed move resolved an idnumber collision via the core suffix mechanism'
                ),
                'message' => new external_value(PARAM_RAW, 'Teacher-facing message'),
            ],
            question_suspect_gate::response_fields()
        ));
    }
}
