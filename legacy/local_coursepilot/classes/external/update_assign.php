<?php
namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/assign/lib.php');

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursepilot\assign_settings;

class update_assign extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
            'name' => new external_value(PARAM_TEXT, 'New assignment title', VALUE_DEFAULT, ''),
            'description' => new external_value(PARAM_RAW, 'New HTML description', VALUE_DEFAULT, ''),
            'duedate' => new external_value(PARAM_INT, 'New due date (-1 = no change)', VALUE_DEFAULT, -1),
            'visible' => new external_value(PARAM_INT, '1 = visible, 0 = hidden, -1 = no change', VALUE_DEFAULT, -1),
            'grade' => new external_value(PARAM_INT, 'Maximale Bewertung (0 = unbewertet, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'gradepass' => new external_value(PARAM_FLOAT, 'Bestehensgrenze in Punkten (-1 = nicht ändern)', VALUE_DEFAULT, -1),
            'submissiondrafts' => new external_value(PARAM_INT, 'Endgültige Abgabe nötig (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'maxattempts' => new external_value(PARAM_INT, 'Maximale Versuche (-1 = unbegrenzt, -2 = nicht ändern)', VALUE_DEFAULT, -2),
            'attemptreopenmethod' => new external_value(PARAM_TEXT, 'Wiedereröffnungsmethode (leer = nicht ändern)', VALUE_DEFAULT, ''),
            'allowsubmissionsfromdate' => new external_value(PARAM_INT, 'Abgabebeginn (0 = sofort, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'cutoffdate' => new external_value(PARAM_INT, 'Letzter Abgabetermin (0 = keiner, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'gradingduedate' => new external_value(PARAM_INT, 'Bewertungsfälligkeit (0 = keine, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'requiresubmissionstatement' => new external_value(PARAM_INT, 'Eigenständigkeitserklärung (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'teamsubmission' => new external_value(PARAM_INT, 'Gruppenabgabe (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'requireallteammemberssubmit' => new external_value(PARAM_INT, 'Pflichtabgabe aller Gruppenmitglieder (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'teamsubmissiongroupingid' => new external_value(PARAM_INT, 'Vorhandene Kurs-Gruppierung (0 = alle, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'sendnotifications' => new external_value(PARAM_INT, 'Abgabebenachrichtigungen (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'sendlatenotifications' => new external_value(PARAM_INT, 'Verspätungsbenachrichtigungen (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'sendstudentnotifications' => new external_value(PARAM_INT, 'Bewertungsbenachrichtigungen (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'blindmarking' => new external_value(PARAM_INT, 'Anonyme Bewertung (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'markingworkflow' => new external_value(PARAM_INT, 'Bewertungsworkflow (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'markingallocation' => new external_value(PARAM_INT, 'Bewertungszuordnung (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'gradecat' => new external_value(PARAM_INT, 'Vorhandene Bewertungskategorie (0 = Standard, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'gradingmethod' => new external_value(PARAM_ALPHA, 'Bewertungsmethode (leer = nicht ändern)', VALUE_DEFAULT, ''),
            'onlinetext_enabled' => new external_value(PARAM_INT, 'Online-Text (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'onlinetext_wordlimit_enabled' => new external_value(PARAM_INT, 'Wortlimit (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'onlinetext_wordlimit' => new external_value(PARAM_INT, 'Maximale Wörter (-1 = nicht ändern)', VALUE_DEFAULT, -1),
            'submission_file_enabled' => new external_value(PARAM_INT, 'Dateiabgabe (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'submission_file_maxfiles' => new external_value(PARAM_INT, 'Maximale Abgabe-Dateien (-1 = nicht ändern)', VALUE_DEFAULT, -1),
            'submission_file_maxsizebytes' => new external_value(PARAM_INT, 'Maximale Abgabe-Dateigröße (-1 = nicht ändern)', VALUE_DEFAULT, -1),
            'submission_file_filetypes' => new external_value(PARAM_RAW, 'Akzeptierte Abgabe-Dateitypen (leer = nicht ändern)', VALUE_DEFAULT, ''),
            'feedback_comments_enabled' => new external_value(PARAM_INT, 'Feedback-Kommentare (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'feedback_editpdf_enabled' => new external_value(PARAM_INT, 'PDF-Annotation (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'feedback_file_enabled' => new external_value(PARAM_INT, 'Feedback-Dateien (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
            'feedback_file_maxfiles' => new external_value(PARAM_INT, 'Maximale Feedback-Dateien (-1 = nicht ändern)', VALUE_DEFAULT, -1),
            'feedback_file_maxsizebytes' => new external_value(PARAM_INT, 'Maximale Feedback-Dateigröße (-1 = nicht ändern)', VALUE_DEFAULT, -1),
            'feedback_file_filetypes' => new external_value(PARAM_RAW, 'Akzeptierte Feedback-Dateitypen (leer = nicht ändern)', VALUE_DEFAULT, ''),
            'feedback_offline_enabled' => new external_value(PARAM_INT, 'Offline-Bewertungsbogen (0 oder 1, -1 = nicht ändern)', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(int $cmid, string $name = '', string $description = '', int $duedate = -1, int $visible = -1, int $grade = -1, float $gradepass = -1, int $submissiondrafts = -1, int $maxattempts = -2, string $attemptreopenmethod = '', int $allowsubmissionsfromdate = -1, int $cutoffdate = -1, int $gradingduedate = -1, int $requiresubmissionstatement = -1, int $teamsubmission = -1, int $requireallteammemberssubmit = -1, int $teamsubmissiongroupingid = -1, int $sendnotifications = -1, int $sendlatenotifications = -1, int $sendstudentnotifications = -1, int $blindmarking = -1, int $markingworkflow = -1, int $markingallocation = -1, int $gradecat = -1, string $gradingmethod = '', int $onlinetext_enabled = -1, int $onlinetext_wordlimit_enabled = -1, int $onlinetext_wordlimit = -1, int $submission_file_enabled = -1, int $submission_file_maxfiles = -1, int $submission_file_maxsizebytes = -1, string $submission_file_filetypes = '', int $feedback_comments_enabled = -1, int $feedback_editpdf_enabled = -1, int $feedback_file_enabled = -1, int $feedback_file_maxfiles = -1, int $feedback_file_maxsizebytes = -1, string $feedback_file_filetypes = '', int $feedback_offline_enabled = -1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), compact('cmid', 'name', 'description', 'duedate', 'visible', 'grade', 'gradepass', 'submissiondrafts', 'maxattempts', 'attemptreopenmethod', 'allowsubmissionsfromdate', 'cutoffdate', 'gradingduedate', 'requiresubmissionstatement', 'teamsubmission', 'requireallteammemberssubmit', 'teamsubmissiongroupingid', 'sendnotifications', 'sendlatenotifications', 'sendstudentnotifications', 'blindmarking', 'markingworkflow', 'markingallocation', 'gradecat', 'gradingmethod', 'onlinetext_enabled', 'onlinetext_wordlimit_enabled', 'onlinetext_wordlimit', 'submission_file_enabled', 'submission_file_maxfiles', 'submission_file_maxsizebytes', 'submission_file_filetypes', 'feedback_comments_enabled', 'feedback_editpdf_enabled', 'feedback_file_enabled', 'feedback_file_maxfiles', 'feedback_file_maxsizebytes', 'feedback_file_filetypes', 'feedback_offline_enabled'));
        $cm = get_coursemodule_from_id('assign', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $moduleinfo = assign_settings::snapshot($cm, $course);
        assign_settings::validate_submissiondrafts_change($moduleinfo, $params);
        assign_settings::validate_frozen_core_changes($moduleinfo, $params);
        $moduleinfo = assign_settings::patch($moduleinfo, $params);
        assign_settings::validate_attempt_settings($moduleinfo, $params);
        assign_settings::validate_core_settings($moduleinfo, $params, $course->id);
        update_moduleinfo($cm, $moduleinfo, $course);
        rebuild_course_cache($cm->course, true);

        if (($params['description'] ?? '') !== '') {
            $savedintro = $DB->get_field('assign', 'intro', ['id' => $cm->instance], MUST_EXIST);
            if ($savedintro !== $params['description']) {
                throw new \invalid_parameter_exception('Die Aufgabenbeschreibung wurde nicht gespeichert.');
            }
        }

        return array_merge([
            'cmid' => $cm->id,
            'message' => 'Assignment updated successfully.',
        ], assign_settings::result($cm->id));
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
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
