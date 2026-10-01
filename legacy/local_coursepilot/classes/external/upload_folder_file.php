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

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_module;
use local_coursepilot\fileupload_helper;

/**
 * Laedt eine Datei (als Base64) in den Content-Dateibereich einer
 * mod_folder-Aktivitaet (component mod_folder, filearea content, itemid 0).
 * Unterverzeichnisse werden ueber den filepath-Parameter angelegt. Eine
 * vorhandene Datei gleichen Namens im selben Verzeichnis wird ersetzt.
 */
class upload_folder_file extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT,  'Course module ID des Verzeichnisses'),
            'filename' => new external_value(PARAM_FILE, 'Dateiname inkl. Erweiterung'),
            'content'  => new external_value(PARAM_RAW,  'Base64-kodierter Dateiinhalt'),
            'mimetype' => new external_value(PARAM_RAW,  'MIME-Type', VALUE_DEFAULT, 'application/octet-stream'),
            'filepath' => new external_value(PARAM_PATH, 'Zielverzeichnis im Ordner, z.B. "/" oder "/material/"', VALUE_DEFAULT, '/'),
        ]);
    }

    public static function execute(int $cmid, string $filename, string $content, string $mimetype = 'application/octet-stream', string $filepath = '/'): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'filename' => $filename,
            'content'  => $content,
            'mimetype' => $mimetype,
            'filepath' => $filepath,
        ]);

        $filepath = self::normalize_filepath($params['filepath']);

        $cm = get_coursemodule_from_id('folder', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        [$filedata, $detectedmimetype] = fileupload_helper::decode_and_validate(
            $params['content'],
            null,
            $params['mimetype']
        );

        $fs = get_file_storage();

        $fs->create_directory($context->id, 'mod_folder', 'content', 0, $filepath);

        fileupload_helper::delete_existing($context->id, 'mod_folder', 'content', 0, $filepath, $params['filename']);

        $file = fileupload_helper::create_file(
            $context->id,
            'mod_folder',
            'content',
            0,
            $filepath,
            $params['filename'],
            $detectedmimetype,
            $USER->id,
            fullname($USER),
            $filedata
        );

        $folder = $DB->get_record('folder', ['id' => $cm->instance], '*', MUST_EXIST);
        $folder->revision = (int) $folder->revision + 1;
        $folder->timemodified = time();
        $DB->update_record('folder', $folder);

        try {
            rebuild_course_cache($cm->course, true);
        } catch (\Exception $e) {
            // Cache-Rebuild ist Optimierung; Datei ist bereits gespeichert.
        }

        $areafiles = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'filepath, filename', false);
        $files = [];
        foreach ($areafiles as $areafile) {
            if ($areafile->is_directory()) {
                continue;
            }
            $files[] = $areafile->get_filepath() . $areafile->get_filename();
        }

        return [
            'fileid'   => (int) $file->get_id(),
            'filename' => $params['filename'],
            'filepath' => $filepath,
            'message'  => 'Datei "' . $params['filename'] . '" erfolgreich hochgeladen (cmid: ' . $cm->id . ').',
            'files'    => $files,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'fileid'   => new external_value(PARAM_INT,  'ID der hochgeladenen Datei in mdl_files'),
            'filename' => new external_value(PARAM_FILE, 'Dateiname im Content-Bereich des Ordners'),
            'filepath' => new external_value(PARAM_PATH, 'Normalisierter Verzeichnispfad im Ordner'),
            'message'  => new external_value(PARAM_TEXT, 'Success message'),
            'files'    => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Voller Pfad einer Datei im Ordner (Verzeichnis + Dateiname)')
            ),
        ]);
    }

    private static function normalize_filepath(string $filepath): string {
        $filepath = trim($filepath);
        if ($filepath === '' || $filepath === '/') {
            return '/';
        }
        $filepath = '/' . trim($filepath, '/') . '/';
        return preg_replace('#/+#', '/', $filepath);
    }
}
