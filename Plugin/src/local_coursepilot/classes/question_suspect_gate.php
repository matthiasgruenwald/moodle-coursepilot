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

namespace local_coursepilot;

use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared suspected-question collision response (ADR 0015, Spec 0017 §7.1,
 * #414): endpoint-specific gates would be four separate gates. Started
 * with move_question, then intended for import_questions_xml,
 * create_mc_question and clone follow-up rather than separate shapes.
 *
 * A suspected collision writes nothing. Return supplied idnumber, target
 * category, nearby candidates and available old/new question text.
 * Only an explicitly confirmed repeated call writes, as with
 * set_completion and restore_activity_version.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class question_suspect_gate {
    /**
     * Gate declarations merged into each endpoint's execute_returns() through
     * array_merge. Always present, with empty defaults outside a suspected
     * collision, keeping a fixed response shape for every endpoint.
     *
     * @return array<string, \core_external\external_description>
     */
    public static function response_fields(): array {
        return [
            'idnumber' => new external_value(
                PARAM_TEXT,
                'Supplied idnumber for a suspected collision; empty otherwise',
                VALUE_DEFAULT,
                ''
            ),
            'categoryid' => new external_value(
                PARAM_INT,
                'Target category for a suspected collision; 0 otherwise',
                VALUE_DEFAULT,
                0
            ),
            'candidates' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_INT, 'questionid of the candidate\'s latest version'),
                    'name' => new external_value(PARAM_TEXT, 'Candidate question name'),
                    'idnumber' => new external_value(PARAM_TEXT, 'Candidate idnumber'),
                ]),
                'Nearby candidates in the target category; empty without suspicion',
                VALUE_DEFAULT,
                []
            ),
            'questiontext_old' => new external_value(
                PARAM_RAW,
                'Existing candidate question text, when available',
                VALUE_DEFAULT,
                ''
            ),
            'questiontext_new' => new external_value(
                PARAM_RAW,
                'Question text to write or move, when available',
                VALUE_DEFAULT,
                ''
            ),
        ];
    }

    /**
     * Empty fields for the non-collision case, using the same keys as
     * {@see response_fields()} for a stable response shape.
     *
     * @return array{idnumber: string, categoryid: int, candidates: mixed[], questiontext_old: string, questiontext_new: string}
     */
    public static function empty_result(): array {
        return [
            'idnumber' => '',
            'categoryid' => 0,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => '',
        ];
    }

    /**
     * Finds an existing bank entry with the same idnumber in the target
     * category, excluding the current entry to avoid false collisions when
     * moving a question within its own category. Moodle uniquely indexes
     * (questioncategoryid, idnumber), so at most one candidate exists.
     *
     * @param int $categoryid
     * @param string $idnumber
     * @param int $excludeentryid questionbankentryid excluded from collision detection
     * @return \stdClass|null {entryid, questionid, name, idnumber, questiontext}, or null without collision
     */
    public static function find_idnumber_collision(int $categoryid, string $idnumber, int $excludeentryid): ?\stdClass {
        global $DB;

        if ($idnumber === '') {
            return null;
        }

        $sql = 'SELECT qbe.id AS entryid, qbe.idnumber AS idnumber, q.id AS questionid, q.name AS name,
                       q.questiontext AS questiontext
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND qbe.idnumber = :idnumber
                   AND qbe.id <> :excludeentryid
              ORDER BY qv.version DESC';
        $rows = $DB->get_records_sql($sql, [
            'catid' => $categoryid,
            'idnumber' => $idnumber,
            'excludeentryid' => $excludeentryid,
        ], 0, 1);

        return $rows ? reset($rows) : null;
    }

    /**
     * Builds the suspected-collision response from a matching entry.
     *
     * @param \stdClass $collision Result of {@see find_idnumber_collision()}
     * @param int $categoryid Target category
     * @param string $newquestiontext Question text to write or move
     * @return array{idnumber: string, categoryid: int, candidates: mixed[], questiontext_old: string, questiontext_new: string}
     */
    public static function response(\stdClass $collision, int $categoryid, string $newquestiontext): array {
        return [
            'idnumber' => (string) $collision->idnumber,
            'categoryid' => $categoryid,
            'candidates' => [[
                'questionid' => (int) $collision->questionid,
                'name' => (string) $collision->name,
                'idnumber' => (string) $collision->idnumber,
            ]],
            'questiontext_old' => (string) $collision->questiontext,
            'questiontext_new' => $newquestiontext,
        ];
    }

    /**
     * Nearby candidates for name-based suspicion: identically named entries
     * in the target category. Shared by import_questions_xml (supplied
     * idnumber without a match) and create_mc_question (new questions have
     * no supplied idnumber to match).
     *
     * @param int $categoryid
     * @param string $name
     * @return mixed[]
     */
    public static function find_name_candidates(int $categoryid, string $name): array {
        global $DB;

        $sql = 'SELECT DISTINCT qbe.id AS entryid, qbe.idnumber AS idnumber
                  FROM {question_bank_entries} qbe
                  JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                  JOIN {question} q           ON q.id = qv.questionid
                 WHERE qbe.questioncategoryid = :catid
                   AND q.name = :name';
        $rows = $DB->get_records_sql($sql, ['catid' => $categoryid, 'name' => $name]);

        $candidates = [];
        foreach ($rows as $row) {
            $latest = self::latest_version_question((int) $row->entryid);
            $candidates[] = [
                'questionid' => (int) $latest->id,
                'name' => (string) $latest->name,
                'idnumber' => (string) ($row->idnumber ?? ''),
            ];
        }
        return $candidates;
    }

    /**
     * Loads the question row for the latest version of a question-bank entry.
     *
     * @param int $entryid
     * @return \stdClass
     */
    public static function latest_version_question(int $entryid): \stdClass {
        global $DB;
        $version = $DB->get_record_sql(
            'SELECT * FROM {question_versions} WHERE questionbankentryid = ? ORDER BY version DESC',
            [$entryid],
            IGNORE_MULTIPLE
        );
        return $DB->get_record('question', ['id' => $version->questionid], '*', MUST_EXIST);
    }
}
