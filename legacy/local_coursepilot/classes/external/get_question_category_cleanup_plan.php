<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/classes/local/bank/question_bank_helper.php');

use context_course;
use context_module;
use core_question\local\bank\question_bank_helper;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * Builds a manual, non-destructive cleanup plan for empty, leaf question
 * categories in a selected named question bank (#316, Folge-Issue zu #315:
 * solche entarteten Testkategorien loesten dort einen Moodle-Core-
 * Restore-Fehler aus).
 *
 * Erkennungskriterium: Kategorie ohne eigene Fragen (keine
 * question_bank_entries) und ohne Kindkategorien. Loescht nichts – nennt
 * nur Kategorie und direkten Moodle-Link zur manuellen Pruefung/Loeschung.
 */
class get_question_category_cleanup_plan extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'       => new external_value(PARAM_INT, 'Course ID'),
            'questionbankid' => new external_value(PARAM_INT, 'Course module ID of the selected named question bank'),
        ]);
    }

    public static function execute(int $courseid, int $questionbankid): array {
        global $CFG, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'       => $courseid,
            'questionbankid' => $questionbankid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        $course = $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST);
        $modulename = question_bank_helper::get_default_question_bank_activity_name();
        $sql = "SELECT cm.id, qb.name
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {{$modulename}} qb ON qb.id = cm.instance
                 WHERE cm.id = :questionbankid
                   AND cm.course = :courseid
                   AND m.name = :modulename";
        $bankrecord = $DB->get_record_sql($sql, [
            'questionbankid' => $params['questionbankid'],
            'courseid'       => $course->id,
            'modulename'     => $modulename,
        ]);

        if (!$bankrecord) {
            throw new \invalid_parameter_exception('Selected question bank was not found in this course.');
        }

        $qbankcontext = context_module::instance((int) $bankrecord->id);
        self::validate_context($qbankcontext);
        require_capability('local/coursepilot:use', $qbankcontext);
        require_capability('moodle/question:managecategory', $qbankcontext);

        $topcategory = question_get_top_category($qbankcontext->id, true);

        $categories = $DB->get_records('question_categories', ['contextid' => $qbankcontext->id], 'id ASC');

        $childcounts = [];
        $questioncounts = $DB->get_records_sql(
            'SELECT questioncategoryid, COUNT(*) AS entrycount
               FROM {question_bank_entries}
              WHERE questioncategoryid IN (
                    SELECT id FROM {question_categories} WHERE contextid = :contextid
                    )
           GROUP BY questioncategoryid',
            ['contextid' => $qbankcontext->id]
        );
        foreach ($categories as $c) {
            $childcounts[(int) $c->parent] = ($childcounts[(int) $c->parent] ?? 0) + 1;
        }

        $removals = [];
        foreach ($categories as $c) {
            $id = (int) $c->id;
            if ($id === (int) $topcategory->id) {
                continue;
            }
            $haschildren = ($childcounts[$id] ?? 0) > 0;
            $hasquestions = isset($questioncounts[$id]);
            if ($haschildren || $hasquestions) {
                continue;
            }
            $removals[] = [
                'id'      => $id,
                'name'    => $c->name,
                'parent'  => (int) $c->parent,
                'editurl' => $CFG->wwwroot . '/question/edit.php?cmid=' . $bankrecord->id
                    . '&cat=' . $id . ',' . $qbankcontext->id,
                'reason'  => 'Leere, blattlose Kategorie ohne eigene Fragen und ohne Unterkategorien. Kurspilot löscht sie nicht automatisch; manuell prüfen und ggf. über den Link löschen.',
            ];
        }

        return [
            'questionbankname' => (string) $bankrecord->name,
            'removals'          => $removals,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'questionbankname' => new external_value(PARAM_TEXT, 'Name der geprüften Fragensammlung'),
            'removals'          => new external_multiple_structure(new external_single_structure([
                'id'      => new external_value(PARAM_INT, 'Kategorie-ID'),
                'name'    => new external_value(PARAM_TEXT, 'Kategorie-Name'),
                'parent'  => new external_value(PARAM_INT, 'ID der übergeordneten Kategorie'),
                'editurl' => new external_value(PARAM_URL, 'Direkter Link zur Fragenbank-Verwaltung in Moodle'),
                'reason'  => new external_value(PARAM_TEXT, 'Manuelle, nicht-destruktive Handlungsanweisung'),
            ]), 'Leere, blattlose Testkategorien, die Kurspilot nicht löscht'),
        ]);
    }
}
