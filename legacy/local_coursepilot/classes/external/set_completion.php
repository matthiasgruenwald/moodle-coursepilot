<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/completionlib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_module;
use context_course;

/**
 * Aktiviert die Abschlussverfolgung fuer eine Aktivitaet.
 *
 * completion-Werte:
 *   0 = keine Verfolgung
 *   1 = manuell (SuS klickt "Abgeschlossen")
 *   2 = automatisch (z.B. bei Einreichung)
 *
 * completionsubmit:
 *   1 = bei Einreichung abschliessen (nur mod_assign)
 *
 * completionpassgrade:
 *   1 = Aktivitaet gilt erst als abgeschlossen, wenn die Bestehensgrenze
 *       erreicht ist (Moodle 4.x: course_modules.completionpassgrade,
 *       gepaart mit completion=2). Aktuell unterstuetzt fuer mod_quiz
 *       (#10) – setzt zusaetzlich, dass das zugehoerige grade_item eine
 *       gradepass > 0 hat (wird ueblicherweise in create_quiz gesetzt).
 */
class set_completion extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'                => new external_value(PARAM_INT, 'Course module ID'),
            'completion'          => new external_value(PARAM_INT,
                '0 = keine Verfolgung, 1 = manuell, 2 = automatisch', VALUE_DEFAULT, 1),
            'completionsubmit'    => new external_value(PARAM_INT,
                '1 = bei Einreichung abschliessen (nur assign)', VALUE_DEFAULT, 0),
            'completionpassgrade' => new external_value(PARAM_INT,
                '1 = abgeschlossen sobald Bestehensgrenze erreicht (nur Module mit gradepass, z.B. quiz)',
                VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $cmid,
        int $completion = 1,
        int $completionsubmit = 0,
        int $completionpassgrade = 0
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'                => $cmid,
            'completion'          => $completion,
            'completionsubmit'    => $completionsubmit,
            'completionpassgrade' => $completionpassgrade,
        ]);

        // Kontext holen und Rechte pruefen
        $cm = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        // Sicherstellen dass Completion auf Kursebene aktiviert ist
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        if (!$course->enablecompletion) {
            $DB->set_field('course', 'enablecompletion', 1, ['id' => $cm->course]);
        }

        // course_modules updaten
        $DB->set_field('course_modules', 'completion', $params['completion'], ['id' => $cm->id]);
        $DB->set_field('course_modules', 'completionview', 0, ['id' => $cm->id]);

        // Bei automatischer Vervollstaendigung: completionsubmit in mod-Tabelle
        if ($params['completion'] == 2 && $params['completionsubmit'] == 1) {
            // Funktioniert fuer mod_assign
            if ($cm->modname === 'assign') {
                $DB->set_field('assign', 'completionsubmit', 1, ['id' => $cm->instance]);
            }
        }

        // completionpassgrade: nur in Kombination mit completion=2 sinnvoll.
        // Moodle 4.x speichert die Bedingung in course_modules.completionpassgrade
        // (TINYINT). Bei mod_quiz reicht das – mod_quiz wertet das Flag
        // zusammen mit dem grade_item (gradepass>0) automatisch aus.
        $passgrade = ($params['completion'] == 2 && $params['completionpassgrade'] == 1) ? 1 : 0;
        $DB->set_field('course_modules', 'completionpassgrade', $passgrade, ['id' => $cm->id]);

        // Completion-Daten neu aufbauen
        rebuild_course_cache($cm->course, true);

        return [
            'cmid'    => (int) $cm->id,
            'message' => 'Completion fuer cmid ' . $cm->id . ' gesetzt (Typ: ' . $params['completion']
                . ($passgrade ? ', passgrade=1' : '') . ').',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
