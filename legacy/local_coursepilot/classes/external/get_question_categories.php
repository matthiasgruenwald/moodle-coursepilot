<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use context_course;
use context_module;
use core_question\local\bank\question_bank_helper;

/**
 * Returns all question bank categories of a selected named question bank.
 *
 * Includes the "top" category (parent=0, name='top') so that the returned
 * `parent` values are always resolvable within the result set.
 */
class get_question_categories extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID'),
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the selected named question bank'),
        ]);
    }

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
