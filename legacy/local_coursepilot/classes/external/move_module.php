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
 * Moves an existing course module to another position without changing content.
 */
class move_module extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'cmid' => new external_value(PARAM_INT, 'Course module ID to move'),
            'beforecmid' => new external_value(PARAM_INT, 'Move directly before this course module ID; 0 = unused', VALUE_DEFAULT, 0),
            'aftercmid' => new external_value(PARAM_INT, 'Move directly after this course module ID; 0 = unused', VALUE_DEFAULT, 0),
            'targetsectionnum' => new external_value(PARAM_INT, 'Target section number for moving to section end; -1 = infer from before/after', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(
        int $courseid,
        int $cmid,
        int $beforecmid = 0,
        int $aftercmid = 0,
        int $targetsectionnum = -1
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'cmid' => $cmid,
            'beforecmid' => $beforecmid,
            'aftercmid' => $aftercmid,
            'targetsectionnum' => $targetsectionnum,
        ]);

        if ($params['beforecmid'] > 0 && $params['aftercmid'] > 0) {
            throw new invalid_parameter_exception('Nur beforecmid oder aftercmid setzen, nicht beide.');
        }
        if ($params['beforecmid'] === $params['cmid'] || $params['aftercmid'] === $params['cmid']) {
            throw new invalid_parameter_exception('Eine Aktivitaet kann nicht relativ zu sich selbst verschoben werden.');
        }
        if ($params['beforecmid'] <= 0 && $params['aftercmid'] <= 0 && $params['targetsectionnum'] < 0) {
            throw new invalid_parameter_exception('beforecmid, aftercmid oder targetsectionnum ist erforderlich.');
        }

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $cm = get_coursemodule_from_id(null, $params['cmid'], $params['courseid'], false, MUST_EXIST);
        $targetsection = null;
        $beforemod = null;

        if ($params['beforecmid'] > 0) {
            $beforemod = get_coursemodule_from_id(null, $params['beforecmid'], $params['courseid'], false, MUST_EXIST);
            $targetsection = $DB->get_record('course_sections', ['id' => $beforemod->section], '*', MUST_EXIST);
        } else if ($params['aftercmid'] > 0) {
            $aftermod = get_coursemodule_from_id(null, $params['aftercmid'], $params['courseid'], false, MUST_EXIST);
            $targetsection = $DB->get_record('course_sections', ['id' => $aftermod->section], '*', MUST_EXIST);
            $beforemod = self::module_after($targetsection, $params['aftercmid'], $params['cmid']);
        } else {
            $targetsection = $DB->get_record('course_sections', [
                'course' => $params['courseid'],
                'section' => $params['targetsectionnum'],
            ], '*', MUST_EXIST);
        }

        if ($params['targetsectionnum'] >= 0 && (int) $targetsection->section !== $params['targetsectionnum']) {
            throw new invalid_parameter_exception('targetsectionnum passt nicht zur beforecmid/aftercmid-Zielaktivitaet.');
        }

        moveto_module($cm, $targetsection, $beforemod);
        rebuild_course_cache($params['courseid'], true);

        return [
            'cmid' => (int) $params['cmid'],
            'sectionnum' => (int) $targetsection->section,
            'beforecmid' => (int) ($beforemod ? $beforemod->id : 0),
            'aftercmid' => (int) $params['aftercmid'],
            'moved' => 1,
            'message' => 'Module moved successfully.',
        ];
    }

    private static function module_after(\stdClass $section, int $aftercmid, int $movingcmid): ?\stdClass {
        global $DB;

        $sequence = array_values(array_filter(array_map('intval', explode(',', (string) $section->sequence))));
        $sequence = array_values(array_filter($sequence, function($id) use ($movingcmid) {
            return $id !== $movingcmid;
        }));
        $afterindex = array_search($aftercmid, $sequence, true);
        if ($afterindex === false) {
            throw new invalid_parameter_exception('aftercmid liegt nicht im Zielabschnitt.');
        }

        $nextid = $sequence[$afterindex + 1] ?? 0;
        if ($nextid <= 0) {
            return null;
        }

        return get_coursemodule_from_id(null, $nextid, (int) $section->course, false, MUST_EXIST);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Moved course module ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Target section number'),
            'beforecmid' => new external_value(PARAM_INT, 'Course module ID now after the moved module, or 0'),
            'aftercmid' => new external_value(PARAM_INT, 'Requested after course module ID, or 0'),
            'moved' => new external_value(PARAM_INT, '1 if the module move was requested'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
