<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\question_bank_context;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a manual, non-destructive cleanup plan for empty leaf categories.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_question_category_cleanup_plan extends external_api {
    /** @return external_function_parameters */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the selected named question bank'),
        ]);
    }

    /**
     * @param int $courseid Course ID
     * @param int $questionbankid Question bank course module ID
     * @return array
     */
    public static function execute(int $courseid, int $questionbankid): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'questionbankid' => $questionbankid,
        ]);
        [$bankrecord, $bankcontext] = question_bank_context::resolve($params['courseid'], $params['questionbankid']);
        require_capability('moodle/question:managecategory', $bankcontext);

        $sql = 'SELECT c.id, c.name, c.parent
                  FROM {question_categories} c
                 WHERE c.contextid = :contextid AND c.parent <> 0
                   AND NOT EXISTS (SELECT 1 FROM {question_bank_entries} e WHERE e.questioncategoryid = c.id)
                   AND NOT EXISTS (SELECT 1 FROM {question_categories} child WHERE child.parent = c.id)
              ORDER BY c.id ASC';
        $categories = $DB->get_records_sql($sql, ['contextid' => $bankcontext->id]);

        $removals = [];
        foreach ($categories as $category) {
            $removals[] = [
                'id' => (int) $category->id,
                'name' => $category->name,
                'parent' => (int) $category->parent,
                'editurl' => $CFG->wwwroot . '/question/edit.php?cmid=' . $bankrecord->id
                    . '&cat=' . $category->id . ',' . $bankcontext->id,
                'reason' => get_string('questioncategorycleanupreason', 'local_coursepilot'),
            ];
        }
        return ['questionbankname' => $bankrecord->name, 'removals' => $removals];
    }

    /** @return external_single_structure */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionbankname' => new external_value(PARAM_TEXT, 'Name of the checked question bank'),
            'removals' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category ID'),
                'name' => new external_value(PARAM_TEXT, 'Category name'),
                'parent' => new external_value(PARAM_INT, 'Parent category ID'),
                'editurl' => new external_value(PARAM_URL, 'Direct link to question bank management in Moodle'),
                'reason' => new external_value(PARAM_TEXT, 'Manual, non-destructive instruction'),
            ]), 'Empty leaf categories; Coursepilot does not delete them'),
        ]);
    }
}
