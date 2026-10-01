<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_course;
use context_module;
use core_question\local\bank\question_bank_helper;
use local_coursepilot\question_category_defaults;

/**
 * Creates (or returns the existing) question bank category in a selected
 * named question bank context.
 *
 * Idempotent: if a category with the same name already exists under the
 * given parent, no duplicate is created – the existing id is returned with
 * created=false.
 */
class create_question_category extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID'),
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the selected named question bank'),
            'name'           => new external_value(PARAM_TEXT, 'Category name, convention: "<Abschnittsnummer> <Titel>", e.g. "7.2 Stoffe und ihre Eigenschaften"'),
            'parent'         => new external_value(PARAM_INT, 'Parent category ID (0 = create as child of the selected question bank top category)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $courseid, int $questionbankid, string $name, int $parent = 0): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'       => $courseid,
            'questionbankid' => $questionbankid,
            'name'           => $name,
            'parent'         => $parent,
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
        require_capability('moodle/question:managecategory', $qbankcontext);

        $topcategory = question_get_top_category($qbankcontext->id, true);

        $parentid = $params['parent'] > 0 ? $params['parent'] : (int) $topcategory->id;

        // Idempotenz: existiert bereits eine Kategorie mit gleichem Namen unter
        // demselben Parent (im selben Kontext)?
        $existing = $DB->get_record('question_categories', [
            'contextid' => $qbankcontext->id,
            'parent'    => $parentid,
            'name'      => $params['name'],
        ]);

        if ($existing) {
            return [
                'id'      => (int) $existing->id,
                'created' => false,
                'message' => 'Question category "' . $params['name'] . '" already exists.',
            ];
        }

        $record = new \stdClass();
        $record->name        = $params['name'];
        $record->contextid   = $qbankcontext->id;
        $record->info        = '';
        $record->infoformat  = FORMAT_HTML;
        $record->stamp       = make_unique_id_code();
        $record->parent      = $parentid;
        $record->sortorder   = question_category_defaults::SORTORDER;
        $record->idnumber    = null;

        $newid = $DB->insert_record('question_categories', $record);

        return [
            'id'      => (int) $newid,
            'created' => true,
            'message' => 'Question category "' . $params['name'] . '" successfully created.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id'      => new external_value(PARAM_INT, 'ID of the (existing or newly created) question category'),
            'created' => new external_value(PARAM_BOOL, 'true if a new category was created, false if an existing one was reused'),
            'message' => new external_value(PARAM_TEXT, 'Status message'),
        ]);
    }
}
