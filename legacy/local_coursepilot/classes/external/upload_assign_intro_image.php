<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;
use local_coursepilot\fileupload_helper;

/**
 * Laedt ein Bild in den intro-Dateibereich einer Aufgabe und bindet es
 * direkt in die Aufgabenbeschreibung ein.
 */
class upload_assign_intro_image extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT,  'Course module ID der Aufgabe'),
            'filename' => new external_value(PARAM_FILE, 'Bild-Dateiname inkl. Erweiterung'),
            'content'  => new external_value(PARAM_RAW,  'Base64-kodierter Bildinhalt'),
            'mimetype' => new external_value(PARAM_RAW,  'MIME-Type', VALUE_DEFAULT, 'image/png'),
            'alt'      => new external_value(PARAM_TEXT, 'Alternativtext fuer das Bild', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $cmid, string $filename, string $content, string $mimetype = 'image/png', string $alt = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'filename' => $filename,
            'content'  => $content,
            'mimetype' => $mimetype,
            'alt'      => $alt,
        ]);

        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        [$filedata, $detectedmimetype] = fileupload_helper::decode_and_validate($params['content']);
        if (!str_starts_with($detectedmimetype, 'image/')) {
            throw new \invalid_parameter_exception(
                'Hochgeladene Datei ist kein Bild (erkannt: ' . $detectedmimetype . '). Nur Bilddateien können eingebettet werden.'
            );
        }

        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);

        fileupload_helper::delete_existing($context->id, 'mod_assign', 'intro', 0, '/', $params['filename']);

        $file = fileupload_helper::create_file(
            $context->id,
            'mod_assign',
            'intro',
            0,
            '/',
            $params['filename'],
            $detectedmimetype,
            $USER->id,
            fullname($USER),
            $filedata
        );
        $fileid = (int) $file->get_id();

        $src = '@@PLUGINFILE@@/' . rawurlencode($params['filename']);
        $alttext = s($params['alt']);
        $imagehtml = '<p><img src="' . $src . '" alt="' . $alttext . '" style="max-width:100%;height:auto;" /></p>';

        $intro = trim((string) ($assign->intro ?? ''));
        $assign->intro = $intro === '' ? $imagehtml : $intro . "\n" . $imagehtml;
        $assign->introformat = FORMAT_HTML;
        $assign->timemodified = time();
        $DB->update_record('assign', $assign);

        rebuild_course_cache($cm->course, true);

        return [
            'cmid'     => (int) $cm->id,
            'fileid'   => $fileid,
            'filename' => $params['filename'],
            'html'     => $imagehtml,
            'message'  => 'Bild "' . $params['filename'] . '" erfolgreich in die Aufgabenbeschreibung eingebunden.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'     => new external_value(PARAM_INT,  'Course module ID'),
            'fileid'   => new external_value(PARAM_INT,  'ID der hochgeladenen Datei in mdl_files'),
            'filename' => new external_value(PARAM_FILE, 'Gespeicherter Dateiname'),
            'html'     => new external_value(PARAM_RAW,  'Eingefuegtes HTML mit @@PLUGINFILE@@-Referenz'),
            'message'  => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
