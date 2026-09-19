<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use context_course;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use invalid_parameter_exception;

/**
 * Moves an existing course section to a different position.
 */
class move_section extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Current section number (0-based, section 0 excluded)'),
            'targetsectionnum' => new external_value(PARAM_INT, 'Target section number after move (0-based, section 0 excluded)'),
        ]);
    }

    public static function execute(int $courseid, int $sectionnum, int $targetsectionnum): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'targetsectionnum' => $targetsectionnum,
        ]);

        if ($params['sectionnum'] < 1) {
            throw new invalid_parameter_exception('Abschnitt 0 ("Allgemeines") kann nicht verschoben werden.');
        }

        if ($params['targetsectionnum'] < 1) {
            throw new invalid_parameter_exception('Zielposition 0 ist fuer Abschnittsverschiebungen nicht erlaubt.');
        }

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:update', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $section = $DB->get_record('course_sections', [
            'course' => $params['courseid'],
            'section' => $params['sectionnum'],
        ], 'id, section', MUST_EXIST);
        course_create_sections_if_missing($params['courseid'], $params['targetsectionnum']);
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        if ($params['sectionnum'] === $params['targetsectionnum']) {
            return [
                'sectionid' => (int) $section->id,
                'sectionnum' => (int) $section->section,
                'targetsectionnum' => (int) $params['targetsectionnum'],
                'moved' => 0,
                'message' => 'Section is already at the requested position.',
            ];
        }

        if (!move_section_to($course, $params['sectionnum'], $params['targetsectionnum'])) {
            throw new \runtimeException('Section could not be moved.');
        }

        return [
            'sectionid' => (int) $section->id,
            'sectionnum' => (int) $section->section,
            'targetsectionnum' => (int) $params['targetsectionnum'],
            'moved' => 1,
            'message' => 'Section moved successfully.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sectionid' => new external_value(PARAM_INT, 'Section DB ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Original section number'),
            'targetsectionnum' => new external_value(PARAM_INT, 'Target section number'),
            'moved' => new external_value(PARAM_INT, '1 if the section moved, otherwise 0'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
