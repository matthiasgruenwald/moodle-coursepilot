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
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

global $CFG;

require_once($CFG->libdir . '/questionlib.php');

/**
 * Initializes and lists the native question categories of one quiz (ADR 0031).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class ensure_quiz_question_categories extends external_api {
    /**
     * Describes the course and quiz identifiers required to initialize categories.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID containing the quiz'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the quiz, not a question bank or another activity'),
        ]);
    }

    /**
     * Initializes native quiz categories and returns their identifiers without duplicating existing categories.
     *
     * @param int $courseid Course ID
     * @param int $cmid Quiz course module ID
     * @return mixed[]
     */
    public static function execute(int $courseid, int $cmid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'cmid' => $cmid]);
        $coursecontext = context_course::instance($params['courseid']);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], $params['courseid']);
        if (!$cm) {
            throw new \invalid_parameter_exception('Selected quiz was not found in this course.');
        }
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:managecategory', $context);
        require_capability('moodle/question:viewall', $context);

        $before = $DB->get_records('question_categories', ['contextid' => $context->id]);
        $default = question_get_default_category($context->id, true);
        $categories = $DB->get_records('question_categories', ['contextid' => $context->id], 'parent ASC, sortorder ASC, name ASC');
        $result = [];
        foreach ($categories as $category) {
            // The lazy core initializer does not raise events itself.
            if (!isset($before[$category->id])) {
                \core\event\question_category_created::create_from_question_category_instance($category)->trigger();
            }
            $result[] = ['id' => (int) $category->id, 'name' => $category->name, 'parent' => (int) $category->parent];
        }
        return [
            'contextid' => (int) $context->id,
            'defaultcategoryid' => (int) $default->id,
            'categories' => $result,
        ];
    }

    /**
     * Describes the quiz context, default category and category list returned to clients.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'contextid' => new external_value(PARAM_INT, 'Validated quiz activity context ID'),
            'defaultcategoryid' => new external_value(
                PARAM_INT,
                'Native default category ID; use for questions or as parent for subcategories'
            ),
            'categories' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category ID'),
                'name' => new external_value(PARAM_TEXT, 'Category name'),
                'parent' => new external_value(PARAM_INT, 'Parent category ID (0 for the top category)'),
            ])),
        ]);
    }
}
