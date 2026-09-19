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
require_once($CFG->dirroot . '/mod/assign/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;
use local_coursepilot\assign_settings;

/**
 * Creates a mod_assign activity inside a given section.
 */
class create_assign extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'        => new external_value(PARAM_INT,  'Course ID'),
            'sectionnum'      => new external_value(PARAM_INT,  'Section number (0-based)'),
            'name'            => new external_value(PARAM_TEXT, 'Assignment title'),
            'description'     => new external_value(PARAM_RAW,  'Assignment description (HTML)', VALUE_DEFAULT, ''),
            'duedate'         => new external_value(PARAM_INT,  'Due date as Unix timestamp (0 = no due date)', VALUE_DEFAULT, 0),
            'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Allow submissions from (Unix timestamp, 0 = always)', VALUE_DEFAULT, 0),
            'maxfiles'        => new external_value(PARAM_INT,  'Max number of uploaded files (0 = no file upload)', VALUE_DEFAULT, 1),
            'submissiondrafts' => new external_value(PARAM_INT, 'Require students to click Submit (1) or auto-submit (0)', VALUE_DEFAULT, -1),
            'visible'         => new external_value(PARAM_INT,  'Visible (1) or hidden (0)', VALUE_DEFAULT, 1),
            'mode' => new external_value(PARAM_TEXT, 'Aufgaben-Preset: standard oder übung', VALUE_DEFAULT, 'standard'),
            'grade' => new external_value(PARAM_INT, 'Maximale Bewertung (0 = unbewertet, -1 = Preset-Standard)', VALUE_DEFAULT, -1),
            'gradepass' => new external_value(PARAM_FLOAT, 'Bestehensgrenze in Punkten (-1 = Preset-Standard)', VALUE_DEFAULT, -1),
            'maxattempts' => new external_value(PARAM_INT, 'Maximale Versuche (-1 = unbegrenzt, -2 = Preset-Standard)', VALUE_DEFAULT, -2),
            'attemptreopenmethod' => new external_value(PARAM_TEXT, 'Wiedereröffnungsmethode (leer = Preset-Standard)', VALUE_DEFAULT, ''),
            'cutoffdate' => new external_value(PARAM_INT, 'Letzter Abgabetermin (0 = keiner)', VALUE_DEFAULT, 0),
            'gradingduedate' => new external_value(PARAM_INT, 'Bewertungsfälligkeit (0 = keine)', VALUE_DEFAULT, 0),
            'requiresubmissionstatement' => new external_value(PARAM_INT, 'Eigenständigkeitserklärung (0 oder 1)', VALUE_DEFAULT, 0),
            'teamsubmission' => new external_value(PARAM_INT, 'Gruppenabgabe (0 oder 1)', VALUE_DEFAULT, 0),
            'requireallteammemberssubmit' => new external_value(PARAM_INT, 'Alle Gruppenmitglieder geben ab (0 oder 1)', VALUE_DEFAULT, 0),
            'teamsubmissiongroupingid' => new external_value(PARAM_INT, 'Vorhandene Kurs-Gruppierung (0 = alle Gruppen)', VALUE_DEFAULT, 0),
            'sendnotifications' => new external_value(PARAM_INT, 'Abgabebenachrichtigungen (0 oder 1)', VALUE_DEFAULT, 0),
            'sendlatenotifications' => new external_value(PARAM_INT, 'Verspätungsbenachrichtigungen (0 oder 1)', VALUE_DEFAULT, 0),
            'sendstudentnotifications' => new external_value(PARAM_INT, 'Bewertungsbenachrichtigungen (0 oder 1)', VALUE_DEFAULT, 1),
            'blindmarking' => new external_value(PARAM_INT, 'Anonyme Bewertung (0 oder 1)', VALUE_DEFAULT, 0),
            'markingworkflow' => new external_value(PARAM_INT, 'Bewertungsworkflow (0 oder 1)', VALUE_DEFAULT, 0),
            'markingallocation' => new external_value(PARAM_INT, 'Bewertungszuordnung (0 oder 1)', VALUE_DEFAULT, 0),
            'gradecat' => new external_value(PARAM_INT, 'Vorhandene Bewertungskategorie (0 = Standard)', VALUE_DEFAULT, 0),
            'gradingmethod' => new external_value(PARAM_ALPHA, 'Bewertungsmethode: none, rubric oder guide', VALUE_DEFAULT, 'none'),
            'onlinetext_enabled' => new external_value(PARAM_INT, 'Online-Text erlauben (0 oder 1)', VALUE_DEFAULT, 1),
            'onlinetext_wordlimit_enabled' => new external_value(PARAM_INT, 'Wortlimit für Online-Text (0 oder 1)', VALUE_DEFAULT, 0),
            'onlinetext_wordlimit' => new external_value(PARAM_INT, 'Maximale Wörter für Online-Text (0 = Moodle-Standard)', VALUE_DEFAULT, 0),
            'submission_file_enabled' => new external_value(PARAM_INT, 'Dateiabgabe (0 oder 1, -1 = aus maxfiles ableiten)', VALUE_DEFAULT, -1),
            'submission_file_maxfiles' => new external_value(PARAM_INT, 'Maximale Dateien je Abgabe', VALUE_DEFAULT, 1),
            'submission_file_maxsizebytes' => new external_value(PARAM_INT, 'Maximale Dateigröße in Bytes (0 = Moodle-Standard)', VALUE_DEFAULT, 0),
            'submission_file_filetypes' => new external_value(PARAM_RAW, 'Akzeptierte Moodle-Dateitypen', VALUE_DEFAULT, ''),
            'feedback_comments_enabled' => new external_value(PARAM_INT, 'Feedback-Kommentare (0 oder 1)', VALUE_DEFAULT, 1),
            'feedback_editpdf_enabled' => new external_value(PARAM_INT, 'PDF-Annotation (0 oder 1)', VALUE_DEFAULT, 0),
            'feedback_file_enabled' => new external_value(PARAM_INT, 'Feedback-Dateien (0 oder 1)', VALUE_DEFAULT, 0),
            'feedback_file_maxfiles' => new external_value(PARAM_INT, 'Maximale Feedback-Dateien', VALUE_DEFAULT, 1),
            'feedback_file_maxsizebytes' => new external_value(PARAM_INT, 'Maximale Größe von Feedback-Dateien in Bytes (0 = Moodle-Standard)', VALUE_DEFAULT, 0),
            'feedback_file_filetypes' => new external_value(PARAM_RAW, 'Akzeptierte Feedback-Dateitypen', VALUE_DEFAULT, ''),
            'feedback_offline_enabled' => new external_value(PARAM_INT, 'Offline-Bewertungsbogen (0 oder 1)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int    $courseid,
        int    $sectionnum,
        string $name,
        string $description = '',
        int    $duedate = 0,
        int    $allowsubmissionsfromdate = 0,
        int    $maxfiles = 1,
        int    $submissiondrafts = -1,
        int    $visible = 1,
        string $mode = 'standard',
        int    $grade = -1,
        float  $gradepass = -1,
        int    $maxattempts = -2,
        string $attemptreopenmethod = '', int $cutoffdate = 0, int $gradingduedate = 0,
        int $requiresubmissionstatement = 0, int $teamsubmission = 0, int $requireallteammemberssubmit = 0,
        int $teamsubmissiongroupingid = 0, int $sendnotifications = 0, int $sendlatenotifications = 0,
        int $sendstudentnotifications = 1, int $blindmarking = 0, int $markingworkflow = 0,
        int $markingallocation = 0, int $gradecat = 0, string $gradingmethod = 'none',
        int $onlinetext_enabled = 1, int $onlinetext_wordlimit_enabled = 0, int $onlinetext_wordlimit = 0,
        int $submission_file_enabled = -1, int $submission_file_maxfiles = 1, int $submission_file_maxsizebytes = 0, string $submission_file_filetypes = '',
        int $feedback_comments_enabled = 1, int $feedback_editpdf_enabled = 0, int $feedback_file_enabled = 0,
        int $feedback_file_maxfiles = 1, int $feedback_file_maxsizebytes = 0, string $feedback_file_filetypes = '', int $feedback_offline_enabled = 0
    ): array {
        global $DB, $CFG;

        // Validate parameters.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'                 => $courseid,
            'sectionnum'               => $sectionnum,
            'name'                     => $name,
            'description'              => $description,
            'duedate'                  => $duedate,
            'allowsubmissionsfromdate' => $allowsubmissionsfromdate,
            'maxfiles'                 => $maxfiles,
            'submissiondrafts'         => $submissiondrafts,
            'visible'                  => $visible,
            'mode'                     => $mode,
            'grade'                    => $grade,
            'gradepass'                => $gradepass,
            'maxattempts'              => $maxattempts,
            'attemptreopenmethod'      => $attemptreopenmethod,
            'cutoffdate' => $cutoffdate, 'gradingduedate' => $gradingduedate,
            'requiresubmissionstatement' => $requiresubmissionstatement, 'teamsubmission' => $teamsubmission,
            'requireallteammemberssubmit' => $requireallteammemberssubmit, 'teamsubmissiongroupingid' => $teamsubmissiongroupingid,
            'sendnotifications' => $sendnotifications, 'sendlatenotifications' => $sendlatenotifications,
            'sendstudentnotifications' => $sendstudentnotifications, 'blindmarking' => $blindmarking,
            'markingworkflow' => $markingworkflow, 'markingallocation' => $markingallocation,
            'gradecat' => $gradecat, 'gradingmethod' => $gradingmethod,
            'onlinetext_enabled' => $onlinetext_enabled, 'onlinetext_wordlimit_enabled' => $onlinetext_wordlimit_enabled, 'onlinetext_wordlimit' => $onlinetext_wordlimit,
            'submission_file_enabled' => $submission_file_enabled, 'submission_file_maxfiles' => $submission_file_maxfiles, 'submission_file_maxsizebytes' => $submission_file_maxsizebytes, 'submission_file_filetypes' => $submission_file_filetypes,
            'feedback_comments_enabled' => $feedback_comments_enabled, 'feedback_editpdf_enabled' => $feedback_editpdf_enabled, 'feedback_file_enabled' => $feedback_file_enabled,
            'feedback_file_maxfiles' => $feedback_file_maxfiles, 'feedback_file_maxsizebytes' => $feedback_file_maxsizebytes, 'feedback_file_filetypes' => $feedback_file_filetypes, 'feedback_offline_enabled' => $feedback_offline_enabled,
        ]);

        // Check permissions.
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        // Get the course.
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);

        if (!in_array($params['mode'], ['standard', 'übung'], true)) {
            throw new \invalid_parameter_exception('Unzulässiges Aufgaben-Preset.');
        }
        $moduleinfo = assign_settings::create_moduleinfo($params);
        assign_settings::validate_attempt_settings($moduleinfo, $params);
        assign_settings::validate_core_settings($moduleinfo, $params, $course->id);

        // Add the module to the course.
        $moduleinfo = add_moduleinfo($moduleinfo, $course);

        return array_merge([
            'cmid'    => (int) $moduleinfo->coursemodule,
            'message' => 'Assignment "' . $params['name'] . '" successfully created.',
        ], assign_settings::result((int) $moduleinfo->coursemodule));
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID of the created assignment'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
            'name' => new external_value(PARAM_TEXT, 'Gespeicherter Aufgabentitel'),
            'grade' => new external_value(PARAM_INT, 'Gespeicherte maximale Bewertung'),
            'gradepass' => new external_value(PARAM_FLOAT, 'Gespeicherte Bestehensgrenze'),
            'submissiondrafts' => new external_value(PARAM_INT, 'Gespeicherte Einstellung für endgültige Abgabe'),
            'maxattempts' => new external_value(PARAM_INT, 'Gespeicherte maximale Versuche'),
            'attemptreopenmethod' => new external_value(PARAM_TEXT, 'Gespeicherte Wiedereröffnungsmethode'),
            'visible' => new external_value(PARAM_INT, 'Gespeicherte Sichtbarkeit'),
            'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Gespeicherter Abgabebeginn'),
            'cutoffdate' => new external_value(PARAM_INT, 'Gespeicherter letzter Abgabetermin'),
            'gradingduedate' => new external_value(PARAM_INT, 'Gespeicherte Bewertungsfälligkeit'),
            'requiresubmissionstatement' => new external_value(PARAM_INT, 'Gespeicherte Eigenständigkeitserklärung'),
            'teamsubmission' => new external_value(PARAM_INT, 'Gespeicherte Gruppenabgabe'),
            'requireallteammemberssubmit' => new external_value(PARAM_INT, 'Gespeicherte Pflichtabgabe aller Gruppenmitglieder'),
            'teamsubmissiongroupingid' => new external_value(PARAM_INT, 'Gespeicherte Gruppierung'),
            'sendnotifications' => new external_value(PARAM_INT, 'Gespeicherte Abgabebenachrichtigungen'),
            'sendlatenotifications' => new external_value(PARAM_INT, 'Gespeicherte Verspätungsbenachrichtigungen'),
            'sendstudentnotifications' => new external_value(PARAM_INT, 'Gespeicherte Bewertungsbenachrichtigungen'),
            'blindmarking' => new external_value(PARAM_INT, 'Gespeicherte anonyme Bewertung'),
            'markingworkflow' => new external_value(PARAM_INT, 'Gespeicherter Bewertungsworkflow'),
            'markingallocation' => new external_value(PARAM_INT, 'Gespeicherte Bewertungszuordnung'),
            'gradecat' => new external_value(PARAM_INT, 'Gespeicherte Bewertungskategorie'),
            'gradingmethod' => new external_value(PARAM_ALPHA, 'Gespeicherte Bewertungsmethode'),
            'onlinetext_enabled' => new external_value(PARAM_INT, 'Online-Text aktiviert'),
            'onlinetext_wordlimit_enabled' => new external_value(PARAM_INT, 'Wortlimit aktiviert'),
            'onlinetext_wordlimit' => new external_value(PARAM_INT, 'Wortlimit'),
            'submission_file_enabled' => new external_value(PARAM_INT, 'Dateiabgabe aktiviert'),
            'submission_file_maxfiles' => new external_value(PARAM_INT, 'Maximale Abgabe-Dateien'),
            'submission_file_maxsizebytes' => new external_value(PARAM_INT, 'Maximale Abgabe-Dateigröße'),
            'submission_file_filetypes' => new external_value(PARAM_RAW, 'Akzeptierte Abgabe-Dateitypen'),
            'feedback_comments_enabled' => new external_value(PARAM_INT, 'Feedback-Kommentare aktiviert'),
            'feedback_editpdf_enabled' => new external_value(PARAM_INT, 'PDF-Annotation aktiviert'),
            'feedback_file_enabled' => new external_value(PARAM_INT, 'Feedback-Dateien aktiviert'),
            'feedback_file_maxfiles' => new external_value(PARAM_INT, 'Maximale Feedback-Dateien'),
            'feedback_file_maxsizebytes' => new external_value(PARAM_INT, 'Maximale Feedback-Dateigröße'),
            'feedback_file_filetypes' => new external_value(PARAM_RAW, 'Akzeptierte Feedback-Dateitypen'),
            'feedback_offline_enabled' => new external_value(PARAM_INT, 'Offline-Bewertungsbogen aktiviert'),
        ]);
    }
}
