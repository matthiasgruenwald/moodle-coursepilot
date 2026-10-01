<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once(__DIR__ . '/create_quiz.php');

use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_coursepilot\quiz_settings;

/**
 * Patcht Settings eines bestehenden mod_quiz-Aktivitaet (#322, KP-009/KP-012).
 *
 * Duenner Wrapper um quiz_settings::snapshot()/patch()/persist()/result()
 * (Snapshot+Patch-Modul, analog assign_settings). mode wird nur angewendet,
 * wenn explizit uebergeben ('' = kein Moduswechsel, kein automatischer
 * Sentinel-Default mehr); alle anderen Felder folgen dem gleichen
 * Sentinel-Prinzip wie create_quiz (Layered Defaults).
 */
class update_quiz_settings extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(array_merge([
            'cmid'      => new external_value(PARAM_INT, 'Course module ID of the quiz'),
            'name'      => new external_value(PARAM_TEXT, 'Neuer Quiz-Titel. Leer = nicht ändern.', VALUE_DEFAULT, ''),
            'intro'     => new external_value(PARAM_RAW, 'Neue Beschreibung/Anleitung des Quiz (HTML). Leer = nicht ändern.', VALUE_DEFAULT, ''),
            'visible'   => new external_value(PARAM_INT, '1 = sichtbar, 0 = versteckt, -1 = nicht ändern', VALUE_DEFAULT, -1),
            'mode'      => new external_value(PARAM_ALPHANUMEXT, "Quizmodus: 'mini-check', 'lernstandscheck' oder 'abschlusstest'. Deprecated aliases: 'intensiv', 'lerncheck', 'bewertung'. Leer = kein Moduswechsel (reines Patch der explizit gesetzten Felder).", VALUE_DEFAULT, ''),
            'gradepass' => new external_value(PARAM_FLOAT, 'Bestehensgrenze in Prozent (0-100). -1 = nicht ändern.', VALUE_DEFAULT, -1),
            'timelimit' => new external_value(PARAM_INT, 'Zeitlimit in Sekunden (0 = unbegrenzt). -1 = nicht ändern.', VALUE_DEFAULT, -1),
            'timeopen'  => new external_value(PARAM_INT, 'Öffnungszeitpunkt als Unix-Timestamp (0 = kein Limit). -1 = nicht ändern.', VALUE_DEFAULT, -1),
            'timeclose' => new external_value(PARAM_INT, 'Schließzeitpunkt als Unix-Timestamp (0 = kein Limit). -1 = nicht ändern.', VALUE_DEFAULT, -1),
        ], create_quiz::overridable_field_params()));
    }

    public static function execute(
        int $cmid,
        string $name = '',
        string $intro = '',
        int $visible = -1,
        string $mode = '',
        float $gradepass = -1,
        int $timelimit = -1,
        int $timeopen = -1,
        int $timeclose = -1,
        string $preferredbehaviour = '',
        string $navmethod = '',
        int    $questionsperpage = -1,
        int    $attempts = -1,
        int    $attemptonlast = -1,
        int    $grademethod = -1,
        int    $delay1 = -1,
        int    $delay2 = -1,
        int    $shuffleanswers = -1,
        int    $decimalpoints = -1,
        int    $completion = -1,
        int    $completionusegrade = -1,
        int    $completionpassgrade = -1,
        int    $reviewattempt = -1,
        int    $reviewcorrectness = -1,
        int    $reviewmaxmarks = -1,
        int    $reviewmarks = -1,
        int    $reviewspecificfeedback = -1,
        int    $reviewgeneralfeedback = -1,
        int    $reviewrightanswer = -1,
        int    $reviewoverallfeedback = -1,
        string $overallfeedbacktextpass = '',
        string $overallfeedbacktextfail = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'name'      => $name,
            'intro'     => $intro,
            'visible'   => $visible,
            'mode'      => $mode,
            'gradepass' => $gradepass,
            'timelimit' => $timelimit,
            'timeopen'  => $timeopen,
            'timeclose' => $timeclose,
            'preferredbehaviour' => $preferredbehaviour,
            'navmethod' => $navmethod,
            'questionsperpage' => $questionsperpage,
            'attempts' => $attempts,
            'attemptonlast' => $attemptonlast,
            'grademethod' => $grademethod,
            'delay1' => $delay1,
            'delay2' => $delay2,
            'shuffleanswers' => $shuffleanswers,
            'decimalpoints' => $decimalpoints,
            'completion' => $completion,
            'completionusegrade' => $completionusegrade,
            'completionpassgrade' => $completionpassgrade,
            'reviewattempt' => $reviewattempt,
            'reviewcorrectness' => $reviewcorrectness,
            'reviewmaxmarks' => $reviewmaxmarks,
            'reviewmarks' => $reviewmarks,
            'reviewspecificfeedback' => $reviewspecificfeedback,
            'reviewgeneralfeedback' => $reviewgeneralfeedback,
            'reviewrightanswer' => $reviewrightanswer,
            'reviewoverallfeedback' => $reviewoverallfeedback,
            'overallfeedbacktextpass' => $overallfeedbacktextpass,
            'overallfeedbacktextfail' => $overallfeedbacktextfail,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $snapshot = quiz_settings::snapshot($cm);
        $patched = quiz_settings::patch($snapshot, $params);
        quiz_settings::persist($cm, $patched);
        rebuild_course_cache($cm->course, true);

        return array_merge([
            'cmid'           => (int) $cm->id,
            'mode'           => $patched['mode'],
            'deprecatedmode' => $patched['deprecatedmode'],
            'message'        => 'Quiz settings successfully updated.' . ($patched['mode'] !== '' ? ' (mode=' . $patched['mode'] . ')' : ''),
        ], quiz_settings::result($cm));
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(array_merge([
            'cmid'           => new external_value(PARAM_INT, 'Course module ID of the updated quiz'),
            'mode'           => new external_value(PARAM_TEXT, 'Tatsächlich angewendeter Modus (leer, wenn kein Moduswechsel erfolgte)'),
            'deprecatedmode' => new external_value(PARAM_BOOL, 'True when a deprecated alias was accepted and mapped'),
            'message'        => new external_value(PARAM_TEXT, 'Success message'),
        ], create_quiz::saved_settings_return_structure()));
    }
}
