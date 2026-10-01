<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

use context_course;
use context_module;
use core_question\local\bank\question_bank_helper;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Creates or reuses a named standard question bank activity in a course.
 */
class ensure_question_bank extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'name'     => new external_value(PARAM_TEXT, 'Teacher-readable question bank name, e.g. "Biologie 9a - Immunsystem"'),
        ]);
    }

    public static function execute(int $courseid, string $name): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'name'     => $name,
        ]);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $coursecontext = context_course::instance($course->id);
        self::validate_context($coursecontext);
        require_capability('local/coursepilot:use', $coursecontext);
        require_capability('moodle/course:manageactivities', $coursecontext);

        $modulename = question_bank_helper::get_default_question_bank_activity_name();
        $sql = "SELECT cm.id
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modulename}} qb ON qb.id = cm.instance
                 WHERE cm.course = :courseid
                   AND m.name = :modulename
                   AND qb.type = :type
                   AND qb.name = :name
              ORDER BY cm.id ASC";

        $existing = $DB->get_record_sql($sql, [
            'courseid'   => $course->id,
            'modulename' => $modulename,
            'type'       => question_bank_helper::TYPE_STANDARD,
            'name'       => $params['name'],
        ]);

        if ($existing) {
            $bankcontext = context_module::instance((int) $existing->id);
            self::validate_context($bankcontext);
            require_capability('local/coursepilot:use', $bankcontext);
            $topcategory = question_get_top_category($bankcontext->id, true);

            return [
                'questionbankid' => (int) $existing->id,
                'name'           => $params['name'],
                'contextid'      => (int) $bankcontext->id,
                'topcategoryid'  => (int) $topcategory->id,
                'created'        => false,
                'message'        => 'Question bank "' . $params['name'] . '" already exists.',
            ];
        }

        $bankcm = question_bank_helper::create_default_open_instance(
            $course,
            $params['name'],
            question_bank_helper::TYPE_STANDARD
        );
        $bankcontext = $bankcm->context;
        self::validate_context($bankcontext);
        require_capability('local/coursepilot:use', $bankcontext);
        $topcategory = question_get_top_category($bankcontext->id, true);

        return [
            'questionbankid' => (int) $bankcm->id,
            'name'           => $params['name'],
            'contextid'      => (int) $bankcontext->id,
            'topcategoryid'  => (int) $topcategory->id,
            'created'        => true,
            'message'        => 'Question bank "' . $params['name'] . '" successfully created.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the selected question bank'),
            'name'           => new external_value(PARAM_TEXT, 'Question bank name'),
            'contextid'      => new external_value(PARAM_INT, 'Context ID of the selected question bank'),
            'topcategoryid'  => new external_value(PARAM_INT, 'Top category ID of the selected question bank'),
            'created'        => new external_value(PARAM_BOOL, 'true if a new question bank was created, false if an existing one was reused'),
            'message'        => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
