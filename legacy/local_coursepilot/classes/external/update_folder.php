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

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;

/**
 * Updates the name and/or visibility of an existing mod_folder (Verzeichnis)
 * activity. Files are managed separately via upload_folder_file.
 */
class update_folder extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'name'    => new external_value(PARAM_TEXT, 'New folder title', VALUE_DEFAULT, ''),
            'visible' => new external_value(PARAM_INT,  '1 = visible, 0 = hidden, -1 = no change', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(int $cmid, string $name = '', int $visible = -1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'    => $cmid,
            'name'    => $name,
            'visible' => $visible,
        ]);

        $cm = get_coursemodule_from_id('folder', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $folder = $DB->get_record('folder', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($params['name'] !== '') {
            $folder->name = $params['name'];
        }
        $folder->timemodified = time();
        $DB->update_record('folder', $folder);

        if ($params['visible'] >= 0) {
            $DB->set_field('course_modules', 'visible', $params['visible'], ['id' => $cm->id]);
        }

        rebuild_course_cache($cm->course, true);

        return [
            'cmid'    => $params['cmid'],
            'name'    => $folder->name,
            'message' => 'Folder updated successfully.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'name'    => new external_value(PARAM_TEXT, 'Current folder title'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
