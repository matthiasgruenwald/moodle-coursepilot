<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/choice/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;

/**
 * Creates a mod_choice (Abstimmung) activity in a course section. Options are
 * passed as a string array (2-6 entries) and stored as separate rows in
 * choice_options by choice_add_instance(), which add_moduleinfo() delegates to.
 */
class create_choice extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT,  'Course ID'),
            'sectionnum' => new external_value(PARAM_INT,  'Section number (0-based)'),
            'name'       => new external_value(PARAM_TEXT, 'Choice title'),
            'intro'      => new external_value(PARAM_RAW,  'Description shown on the course page (optional)', VALUE_DEFAULT, ''),
            'options'    => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Option text'),
                'Choice options, 2 to 6 entries'
            ),
            'visible'    => new external_value(PARAM_INT,  'Visible (1) or hidden (0)', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute(int $courseid, int $sectionnum, string $name, string $intro = '', array $options = [], int $visible = 1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'name'       => $name,
            'intro'      => $intro,
            'options'    => $options,
            'visible'    => $visible,
        ]);

        $cleanoptions = array_values(array_filter(
            array_map('trim', $params['options']),
            static fn($text) => $text !== ''
        ));
        if (count($cleanoptions) < 2) {
            throw new \invalid_parameter_exception('Eine Abstimmung braucht mindestens 2 Optionen.');
        }
        // Kurspilot-Vorgabe, keine Moodle-Grenze: mod_choice erlaubt beliebig viele Optionen.
        // Bewusste didaktische Vereinfachung fuer Lehrkraefte (siehe Issue #357).
        if (count($cleanoptions) > 6) {
            throw new \invalid_parameter_exception('Eine Abstimmung hat maximal 6 Optionen.');
        }

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename      = 'choice';
        $moduleinfo->module          = $DB->get_field('modules', 'id', ['name' => 'choice'], MUST_EXIST);
        $moduleinfo->course          = $params['courseid'];
        $moduleinfo->section         = $params['sectionnum'];
        $moduleinfo->name            = $params['name'];
        $moduleinfo->visible         = $params['visible'];
        $moduleinfo->intro           = $params['intro'];
        $moduleinfo->introformat     = FORMAT_HTML;
        $moduleinfo->publish         = 0; // Anonymous results.
        $moduleinfo->showresults     = 0; // Do not publish results.
        $moduleinfo->display         = 0; // Horizontal layout.
        $moduleinfo->allowupdate     = 0;
        $moduleinfo->allowmultiple   = 0;
        $moduleinfo->showunanswered  = 0;
        $moduleinfo->includeinactive = 1;
        $moduleinfo->limitanswers    = 0;
        $moduleinfo->timeopen        = 0;
        $moduleinfo->timeclose       = 0;
        $moduleinfo->showpreview     = 0;
        $moduleinfo->completionsubmit = 0;
        $moduleinfo->showavailable   = 0;
        $moduleinfo->option          = $cleanoptions;
        $moduleinfo->limit           = array_fill(0, count($cleanoptions), 0); // 0 = unlimited.

        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $moduleinfo->coursemodule;

        rebuild_course_cache($course->id, true);

        return [
            'cmid'    => $cmid,
            'name'    => $params['name'],
            'options' => $cleanoptions,
            'message' => 'Choice "' . $params['name'] . '" successfully created.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID of the created choice'),
            'name'    => new external_value(PARAM_TEXT, 'Choice title'),
            'options' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Option text'),
                'Stored choice options'
            ),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
