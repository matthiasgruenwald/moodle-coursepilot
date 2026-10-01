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
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/folder/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;

/**
 * Creates a mod_folder (Verzeichnis) activity in a course section. Files are
 * added afterwards via upload_folder_file into the mod_folder content
 * filearea (itemid 0, subdirectories via filepath).
 */
class create_folder extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT,  'Course ID'),
            'sectionnum' => new external_value(PARAM_INT,  'Section number (0-based)'),
            'name'       => new external_value(PARAM_TEXT, 'Folder title'),
            'visible'    => new external_value(PARAM_INT,  'Visible (1) or hidden (0)', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute(int $courseid, int $sectionnum, string $name, int $visible = 1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'name'       => $name,
            'visible'    => $visible,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename        = 'folder';
        $moduleinfo->module            = $DB->get_field('modules', 'id', ['name' => 'folder'], MUST_EXIST);
        $moduleinfo->course            = $params['courseid'];
        $moduleinfo->section           = $params['sectionnum'];
        $moduleinfo->name              = $params['name'];
        $moduleinfo->visible           = $params['visible'];
        $moduleinfo->intro             = '';
        $moduleinfo->introformat       = FORMAT_HTML;
        $moduleinfo->display           = 0; // FOLDER_DISPLAY_PAGE: contents on a separate page.
        $moduleinfo->showexpanded      = 1;
        $moduleinfo->showdownloadfolder = 1;
        $moduleinfo->files             = 0; // Kein Draft-Bereich; folder_add_instance() liest das Feld unconditional.

        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $moduleinfo->coursemodule;

        rebuild_course_cache($course->id, true);

        return [
            'cmid'    => $cmid,
            'message' => 'Folder "' . $params['name'] . '" successfully created.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID of the created folder'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
