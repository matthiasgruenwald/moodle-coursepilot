<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_module;

/**
 * Fuegt Fragenbank-Fragen (#9) zu einem Quiz (#6) hinzu, als
 * question_references mit version=null ("immer aktuellste Version", #13,
 * vgl. ADR-0001).
 *
 * - Fragenreihenfolge folgt der Reihenfolge in questionids[].
 * - Bereits im Quiz vorhandene Fragen (gleiche questionbankentryid) werden
 *   von quiz_add_quiz_question() automatisch uebersprungen (added=false).
 * - Die Antwort enthaelt 'slots': den aktuellen Inhalt des Quiz in
 *   Slot-Reihenfolge, mit der jeweils aktuellsten questionid pro Frage
 *   (version=null wird hier dynamisch aufgeloest) – damit Aufrufer nach
 *   einem moodle_update_mc_question sehen, dass das Quiz die neue Version
 *   zeigt, ohne die Frage erneut hinzufuegen zu muessen.
 */
class add_questions_to_quiz extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'        => new external_value(PARAM_INT, 'Course module ID des Quiz (aus moodle_create_quiz)'),
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'questionid der latest version einer Frage (aus moodle_create_mc_question/moodle_get_question)'),
                'Fragen in der gewuenschten Reihenfolge'
            ),
        ]);
    }

    public static function execute(int $cmid, array $questionids): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'        => $cmid,
            'questionids' => $questionids,
        ]);

        if (empty($params['questionids'])) {
            throw new \invalid_parameter_exception('questionids darf nicht leer sein.');
        }

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        $added = [];
        foreach ($params['questionids'] as $questionid) {
            $result = quiz_add_quiz_question((int) $questionid, $quiz);
            $added[] = [
                'questionid' => (int) $questionid,
                'added'      => $result !== false,
            ];
        }

        quiz_update_sumgrades($quiz);
        rebuild_course_cache($quiz->course, true);

        return [
            'cmid'    => $params['cmid'],
            'results' => $added,
            'slots'   => self::current_slots((int) $quiz->id),
            'message' => count($params['questionids']) . ' Frage(n) verarbeitet.',
        ];
    }

    /**
     * Liefert den aktuellen Inhalt des Quiz in Slot-Reihenfolge. Pro Slot
     * wird die aktuell letzte Version (hoechste question_versions.version)
     * der referenzierten questionbankentryid aufgeloest, analog zu
     * question_references.version=null ("immer aktuellste Version").
     */
    private static function current_slots(int $quizid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT qs.id AS slotid, qs.slot, qr.questionbankentryid
               FROM {quiz_slots} qs
               JOIN {question_references} qr
                 ON qr.itemid = qs.id
                AND qr.component = :component
                AND qr.questionarea = :area
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]
        );

        $slots = [];
        foreach ($rows as $row) {
            $latest = $DB->get_record_sql(
                'SELECT questionid, version
                   FROM {question_versions}
                  WHERE questionbankentryid = ?
               ORDER BY version DESC',
                [$row->questionbankentryid],
                IGNORE_MULTIPLE
            );
            $slots[] = [
                'slot'                => (int) $row->slot,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid'          => $latest ? (int) $latest->questionid : 0,
                'version'             => $latest ? (int) $latest->version : 0,
            ];
        }
        return $slots;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT, 'Course module ID des Quiz'),
            'results' => new external_multiple_structure(
                new external_single_structure([
                    'questionid' => new external_value(PARAM_INT,  'questionid aus questionids[]'),
                    'added'      => new external_value(PARAM_BOOL, 'true wenn neu hinzugefuegt, false wenn bereits vorhanden (Duplikat uebersprungen)'),
                ]),
                'Ergebnis je questionid in Eingabe-Reihenfolge'
            ),
            'slots'   => new external_multiple_structure(
                new external_single_structure([
                    'slot'                => new external_value(PARAM_INT, 'Slot-Nummer (Reihenfolge im Quiz)'),
                    'questionbankentryid' => new external_value(PARAM_INT, 'questionbankentryid der Frage'),
                    'questionid'          => new external_value(PARAM_INT, 'questionid der aktuell letzten Version'),
                    'version'             => new external_value(PARAM_INT, 'Versionsnummer der aktuell letzten Version'),
                ]),
                'Aktueller Quiz-Inhalt in Slot-Reihenfolge (latest version je Frage)'
            ),
            'message' => new external_value(PARAM_TEXT, 'Status-Nachricht'),
        ]);
    }
}
