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
require_once($CFG->dirroot . '/mod/resource/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;
use context_module;

/**
 * Creates a mod_resource (Datei) activity in a course section and stores the
 * given Base64 file in the mod_resource content filearea as the main file.
 */
class create_resource extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT,  'Course ID'),
            'sectionnum' => new external_value(PARAM_INT,  'Section number (0-based)'),
            'name'       => new external_value(PARAM_TEXT, 'Resource title'),
            'filename'   => new external_value(PARAM_FILE, 'File name including extension'),
            'content'    => new external_value(PARAM_RAW,  'Base64-encoded file content'),
            'mimetype'   => new external_value(PARAM_RAW,  'MIME type', VALUE_DEFAULT, 'application/octet-stream'),
            'visible'    => new external_value(PARAM_INT,  'Visible (1) or hidden (0)', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute(int $courseid, int $sectionnum, string $name, string $filename, string $content, string $mimetype = 'application/octet-stream', int $visible = 1): array {
        global $DB, $CFG, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'name'       => $name,
            'filename'   => $filename,
            'content'    => $content,
            'mimetype'   => $mimetype,
            'visible'    => $visible,
        ]);

        $filedata = base64_decode($params['content'], true);
        if ($filedata === false) {
            throw new \invalid_parameter_exception('Ungueltige Base64-Kodierung.');
        }
        $detectedmimetype = finfo_buffer(new \finfo(FILEINFO_MIME_TYPE), $filedata) ?: $params['mimetype'];

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename     = 'resource';
        $moduleinfo->module         = $DB->get_field('modules', 'id', ['name' => 'resource'], MUST_EXIST);
        $moduleinfo->course         = $params['courseid'];
        $moduleinfo->section        = $params['sectionnum'];
        $moduleinfo->name           = $params['name'];
        $moduleinfo->visible        = $params['visible'];
        $moduleinfo->intro          = '';
        $moduleinfo->introformat    = FORMAT_HTML;
        $moduleinfo->display        = 0; // Automatic.
        $moduleinfo->printintro     = 0;
        $moduleinfo->revision       = 1;
        $moduleinfo->files          = 0; // Kein Draft-Bereich; Hauptdatei wird unten direkt im content-Filearea angelegt.

        $moduleinfo = add_moduleinfo($moduleinfo, $course);
        $cmid = (int) $moduleinfo->coursemodule;

        $modcontext = context_module::instance($cmid);
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => $modcontext->id,
            'component' => 'mod_resource',
            'filearea'  => 'content',
            'itemid'    => 0,
            'filepath'  => '/',
            'filename'  => $params['filename'],
            'mimetype'  => $detectedmimetype,
            'userid'    => $USER->id,
            'source'    => $params['filename'],
            'author'    => fullname($USER),
            'license'   => 'allrightsreserved',
            'sortorder' => 1,
        ];
        $file = $fs->create_file_from_string($filerecord, $filedata);

        rebuild_course_cache($course->id, true);

        return [
            'cmid'     => $cmid,
            'fileid'   => (int) $file->get_id(),
            'filename' => $params['filename'],
            'message'  => 'Resource "' . $params['name'] . '" successfully created.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'     => new external_value(PARAM_INT,  'Course module ID of the created resource'),
            'fileid'   => new external_value(PARAM_INT,  'ID of the stored file in mdl_files'),
            'filename' => new external_value(PARAM_FILE, 'File name in the content filearea'),
            'message'  => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
