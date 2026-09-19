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
use core_question\local\bank\question_bank_helper;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

/**
 * Fragenbank-Kategorien einer benannten Fragensammlung (#342): fuer
 * Wiederverwendung statt Doppelanlage.
 *
 * Eigenstaendige Portierung von
 * local_coursepilot\external\get_question_categories - local_coursepilot hat
 * laut Spec 0012 keine Laufzeitabhaengigkeit auf das andere Plugin (siehe
 * get_course_catalog.php aus #341, derselbe Fund). Vertrag (Feldnamen,
 * Top-Kategorie enthalten) bleibt identisch zum lokalen Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_question_categories extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID'),
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the selected named question bank'),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $questionbankid
     * @return array
     */
    public static function execute(int $courseid, int $questionbankid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'       => $courseid,
            'questionbankid' => $questionbankid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $modulename = question_bank_helper::get_default_question_bank_activity_name();
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modulename}} qb ON qb.id = cm.instance
                 WHERE cm.id = :questionbankid
                   AND cm.course = :courseid
                   AND m.name = :modulename";
        $bankrecord = $DB->get_record_sql($sql, [
            'questionbankid' => $params['questionbankid'],
            'courseid'       => $course->id,
            'modulename'     => $modulename,
        ]);

        if (!$bankrecord) {
            throw new \invalid_parameter_exception('Selected question bank was not found in this course.');
        }

        $qbankcontext = context_module::instance((int) $bankrecord->id);
        self::validate_context($qbankcontext);
        require_capability('local/coursepilot:use', $qbankcontext);

        // Stellt sicher, dass die top-Kategorie existiert (legt sie ggf. an).
        question_get_top_category($qbankcontext->id, true);

        $categories = $DB->get_records('question_categories',
            ['contextid' => $qbankcontext->id],
            'parent ASC, sortorder ASC, name ASC',
            'id, name, parent'
        );

        $result = [];
        foreach ($categories as $c) {
            $result[] = [
                'id'     => (int) $c->id,
                'name'   => $c->name,
                'parent' => (int) $c->parent,
            ];
        }

        return $result;
    }

    /**
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'     => new external_value(PARAM_INT,  'Category ID'),
                'name'   => new external_value(PARAM_TEXT, 'Category name'),
                'parent' => new external_value(PARAM_INT,  'Parent category ID (0 for the top category itself)'),
            ])
        );
    }
}
