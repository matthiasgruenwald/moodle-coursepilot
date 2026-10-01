<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use local_coursepilot\fileupload_helper;

/**
 * Laedt ein Bild in den questiontext- oder answerfeedback-Dateibereich einer
 * MC-Frage hoch (Issue #326, KP-006). Liefert nur das @@PLUGINFILE@@-Snippet
 * zurueck; das Einfuegen in questiontext bzw. answers[].feedback geschieht
 * im zweiten Schritt ueber moodle_update_mc_question (zweiphasiges Muster,
 * analog zu upload_folder_file statt zum auto-embedding von
 * upload_assign_intro_image).
 *
 * Nutzt denselben fileupload_helper-Kern wie die anderen Upload-Webservices
 * (Issue #320): Base64-Decodierung, MIME-Pruefung anhand des Inhalts,
 * Groessenlimit, Delete-vor-Write fuer Idempotenz.
 */
class upload_question_image extends external_api {

    /** 5 MB Limit (Issue #326, KP-006). */
    const MAX_BYTES = 5 * 1024 * 1024;

    const ALLOWED_AREAS = ['questiontext', 'answerfeedback'];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'questionid' => new external_value(PARAM_INT,  'questionid der Frage (latest version), z.B. aus moodle_get_question/create_mc_question/update_mc_question'),
            'area'       => new external_value(PARAM_ALPHA, 'Zielbereich: questiontext oder answerfeedback'),
            'answerid'   => new external_value(PARAM_INT,  'question_answers.id, Pflicht bei area=answerfeedback', VALUE_DEFAULT, 0),
            'filename'   => new external_value(PARAM_FILE, 'Bild-Dateiname inkl. Erweiterung'),
            'content'    => new external_value(PARAM_RAW,  'Base64-kodierter Bildinhalt'),
            'alt'        => new external_value(PARAM_TEXT, 'Alternativtext fuer das Bild', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(
        int $questionid,
        string $area,
        int $answerid = 0,
        string $filename = '',
        string $content = '',
        string $alt = ''
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'questionid' => $questionid,
            'area'       => $area,
            'answerid'   => $answerid,
            'filename'   => $filename,
            'content'    => $content,
            'alt'        => $alt,
        ]);

        if (!in_array($params['area'], self::ALLOWED_AREAS, true)) {
            throw new \invalid_parameter_exception(
                'area muss questiontext oder answerfeedback sein.');
        }

        $entry = get_question_bank_entry($params['questionid']);
        if (!$entry) {
            throw new \invalid_parameter_exception(
                'Keine Frage mit questionid ' . $params['questionid'] . ' gefunden.');
        }
        $category = $DB->get_record('question_categories',
            ['id' => $entry->questioncategoryid], '*', MUST_EXIST);
        $context = \context::instance_by_id($category->contextid);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/question:add', $context);

        $itemid = $params['questionid'];
        if ($params['area'] === 'answerfeedback') {
            if ($params['answerid'] <= 0) {
                throw new \invalid_parameter_exception(
                    'answerid ist bei area=answerfeedback erforderlich.');
            }
            $answer = $DB->get_record('question_answers',
                ['id' => $params['answerid'], 'question' => $params['questionid']]);
            if (!$answer) {
                throw new \invalid_parameter_exception(
                    'answerid ' . $params['answerid'] . ' gehoert nicht zur Frage '
                    . $params['questionid'] . '.');
            }
            $itemid = $params['answerid'];
        }

        [$filedata, $detectedmimetype] = fileupload_helper::decode_and_validate(
            $params['content'], self::MAX_BYTES);
        if (!str_starts_with($detectedmimetype, 'image/')) {
            throw new \invalid_parameter_exception(
                'Hochgeladene Datei ist kein Bild (erkannt: ' . $detectedmimetype
                . '). Nur Bilddateien können eingebettet werden.');
        }

        fileupload_helper::delete_existing(
            $context->id, 'question', $params['area'], $itemid, '/', $params['filename']);

        $file = fileupload_helper::create_file(
            $context->id, 'question', $params['area'], $itemid, '/',
            $params['filename'], $detectedmimetype, $USER->id, fullname($USER), $filedata
        );

        $src = '@@PLUGINFILE@@/' . rawurlencode($params['filename']);
        $alttext = s($params['alt']);
        $html = '<img src="' . $src . '" alt="' . $alttext . '" style="max-width:100%;height:auto;" />';

        return [
            'fileid'   => (int) $file->get_id(),
            'filename' => $params['filename'],
            'area'     => $params['area'],
            'itemid'   => $itemid,
            'html'     => $html,
            'message'  => 'Bild "' . $params['filename']
                . '" erfolgreich hochgeladen. Snippet per moodle_update_mc_question in '
                . 'questiontext bzw. answers[].feedback einfuegen.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'fileid'   => new external_value(PARAM_INT,  'ID der hochgeladenen Datei in mdl_files'),
            'filename' => new external_value(PARAM_FILE, 'Gespeicherter Dateiname'),
            'area'     => new external_value(PARAM_ALPHA, 'Dateibereich (questiontext oder answerfeedback)'),
            'itemid'   => new external_value(PARAM_INT,  'itemid des Dateibereichs (questionid bei questiontext, answerid bei answerfeedback)'),
            'html'     => new external_value(PARAM_RAW,  '@@PLUGINFILE@@-Snippet zum Einfuegen in questiontext bzw. answers[].feedback'),
            'message'  => new external_value(PARAM_TEXT, 'Status-Nachricht'),
        ]);
    }
}
