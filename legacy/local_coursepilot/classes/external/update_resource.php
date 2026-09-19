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
require_once($CFG->dirroot . '/mod/resource/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;

/**
 * Updates name and/or replaces the main file of an existing mod_resource
 * (Datei) activity.
 */
class update_resource extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT,  'Course module ID'),
            'name'     => new external_value(PARAM_TEXT, 'New resource title', VALUE_DEFAULT, ''),
            'filename' => new external_value(PARAM_FILE, 'New file name including extension', VALUE_DEFAULT, ''),
            'content'  => new external_value(PARAM_RAW,  'Base64-encoded new file content', VALUE_DEFAULT, ''),
            'mimetype' => new external_value(PARAM_RAW,  'MIME type', VALUE_DEFAULT, 'application/octet-stream'),
            'visible'  => new external_value(PARAM_INT,  '1 = visible, 0 = hidden, -1 = no change', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(int $cmid, string $name = '', string $filename = '', string $content = '', string $mimetype = 'application/octet-stream', int $visible = -1): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'name'     => $name,
            'filename' => $filename,
            'content'  => $content,
            'mimetype' => $mimetype,
            'visible'  => $visible,
        ]);

        $cm = get_coursemodule_from_id('resource', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($params['name'] !== '') {
            $resource->name = $params['name'];
        }

        $fs = get_file_storage();
        if ($params['content'] !== '') {
            if ($params['filename'] === '') {
                throw new \invalid_parameter_exception('filename ist erforderlich, wenn content uebergeben wird.');
            }
            $filedata = base64_decode($params['content'], true);
            if ($filedata === false) {
                throw new \invalid_parameter_exception('Ungueltige Base64-Kodierung.');
            }
            $detectedmimetype = finfo_buffer(new \finfo(FILEINFO_MIME_TYPE), $filedata) ?: $params['mimetype'];

            $existingfiles = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder', false);
            foreach ($existingfiles as $existing) {
                $existing->delete();
            }

            $filerecord = [
                'contextid' => $context->id,
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
            $fs->create_file_from_string($filerecord, $filedata);
            $resource->revision = (int) $resource->revision + 1;
        }

        $resource->timemodified = time();
        $DB->update_record('resource', $resource);

        if ($params['visible'] >= 0) {
            $DB->set_field('course_modules', 'visible', $params['visible'], ['id' => $cm->id]);
        }

        rebuild_course_cache($cm->course, true);

        $mainfile = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC', false);
        $mainfilename = $mainfile ? reset($mainfile)->get_filename() : '';

        return [
            'cmid'     => $params['cmid'],
            'filename' => $mainfilename,
            'message'  => 'Resource updated successfully.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'     => new external_value(PARAM_INT,  'Course module ID'),
            'filename' => new external_value(PARAM_FILE, 'Current main file name in the content filearea'),
            'message'  => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
