<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use context_module;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * Builds a manual, non-destructive cleanup plan for obsolete quiz slots.
 */
class get_quiz_cleanup_plan extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID des Quiz'),
            'keep_questionbankentryids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Frage-Identitaet, die in der neuen Quizversion bleibt'),
                'Question-bank-entry-IDs der neuen Quizversion'
            ),
        ]);
    }

    public static function execute(int $cmid, array $keep_questionbankentryids): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'keep_questionbankentryids' => $keep_questionbankentryids,
        ]);
        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], 'id, name', MUST_EXIST);
        $keep = array_flip(array_map('intval', $params['keep_questionbankentryids']));
        $rows = $DB->get_records_sql(
            'SELECT qs.slot, qr.questionbankentryid, qbe.questioncategoryid, qc.name AS categoryname,
                    qv.questionid, qv.version, q.name AS questionname
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = :component AND qr.questionarea = :area
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid
                AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                   WHERE v.questionbankentryid = qbe.id)
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quiz->id]
        );

        $removals = [];
        foreach ($rows as $row) {
            if (isset($keep[(int) $row->questionbankentryid])) {
                continue;
            }
            $removals[] = [
                'slot' => (int) $row->slot,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid' => (int) $row->questionid,
                'version' => (int) $row->version,
                'questionname' => (string) $row->questionname,
                'categoryid' => (int) $row->questioncategoryid,
                'categoryname' => (string) $row->categoryname,
                'reason' => 'Nicht in der neuen Quizversion vorgesehen. Nur aus diesem Quiz entfernen; die Frage wird nicht aus der Fragensammlung gelöscht und bleibt wiederverwendbar.',
            ];
        }

        return [
            'quizname' => (string) $quiz->name,
            'editurl' => $CFG->wwwroot . '/mod/quiz/edit.php?cmid=' . $cm->id,
            'removals' => $removals,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'quizname' => new external_value(PARAM_TEXT, 'Quizname'),
            'editurl' => new external_value(PARAM_URL, 'Direkter Link zur Quiz-Bearbeitung in Moodle'),
            'removals' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Quiz-Slot, der manuell aus dem Quiz entfernt werden kann'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Wiederverwendbare Frage-Identitaet'),
                'questionid' => new external_value(PARAM_INT, 'Aktuelle Fragen-Version'),
                'version' => new external_value(PARAM_INT, 'Versionsnummer der Frage'),
                'questionname' => new external_value(PARAM_TEXT, 'Fragename'),
                'categoryid' => new external_value(PARAM_INT, 'Fragenkategorie-ID'),
                'categoryname' => new external_value(PARAM_TEXT, 'Fragenkategorie'),
                'reason' => new external_value(PARAM_TEXT, 'Manuelle, nicht-destruktive Handlungsanweisung'),
            ]), 'Slots, die Kurspilot nicht löscht'),
        ]);
    }
}
