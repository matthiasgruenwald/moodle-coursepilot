<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/external/create_quiz.php');

use local_coursepilot\external\create_quiz;

/**
 * Snapshot+Patch-Modul fuer mod_quiz-Settings (analog assign_settings, #322).
 *
 * update_quiz_settings (External-Klasse) ruft snapshot() -> patch() ->
 * persist() -> result() nur noch duenn auf. mode wird nur angewendet, wenn
 * explizit uebergeben (Sentinel '' = kein Moduswechsel); ohne mode aendern
 * sich nur die explizit gesetzten Einzelfelder (reines Patch, kein
 * automatischer Sentinel-Default mehr).
 */
class quiz_settings {

    /** Felder in course_modules statt in der quiz-Tabelle (course_modules-Spalten). */
    private const COURSE_MODULE_FIELDS = ['completion', 'completionusegrade', 'completionpassgrade'];

    /** Felder, die bei explizitem mode komplett aus mode_defaults() kommen. */
    private static function mode_field_names(): array {
        return array_merge(create_quiz::OVERRIDABLE_STRING_FIELDS, create_quiz::OVERRIDABLE_INT_FIELDS);
    }

    /** Teilmenge von mode_field_names(), die tatsaechlich Spalten der quiz-Tabelle sind. */
    private static function quiz_table_field_names(): array {
        return array_diff(self::mode_field_names(), self::COURSE_MODULE_FIELDS);
    }

    /** Laedt den Ist-Zustand, sodass ein Teil-Patch nichts verwirft. */
    public static function snapshot(\stdClass $cm): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $cmrecord = $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST);
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'courseid' => $cm->course,
        ], 'gradepass', IGNORE_MISSING);

        $snapshot = [
            'quizid' => (int) $quiz->id,
            'name' => (string) $quiz->name,
            'intro' => (string) $quiz->intro,
            'visible' => (int) $cmrecord->visible,
            'gradepass' => (float) ($gradeitem->gradepass ?? 0),
            'timelimit' => (int) $quiz->timelimit,
            'timeopen' => (int) $quiz->timeopen,
            'timeclose' => (int) $quiz->timeclose,
            'completion' => (int) $cmrecord->completion,
            'completionusegrade' => isset($cmrecord->completiongradeitemnumber) && $cmrecord->completiongradeitemnumber !== null ? 1 : 0,
            'completionpassgrade' => (int) $cmrecord->completionpassgrade,
        ];
        foreach (create_quiz::OVERRIDABLE_STRING_FIELDS as $field) {
            $snapshot[$field] = (string) $quiz->{$field};
        }
        foreach (create_quiz::OVERRIDABLE_INT_FIELDS as $field) {
            if (in_array($field, self::COURSE_MODULE_FIELDS, true)) {
                continue; // Bereits aus course_modules oben gelesen (praeziser als quiz->*).
            }
            $snapshot[$field] = (int) $quiz->{$field};
        }
        return $snapshot;
    }

    /** Wendet nur explizit uebergebene Werte an; Sentinels behalten den Snapshot-Wert. */
    public static function patch(array $snapshot, array $params): array {
        $result = $snapshot;
        $modegiven = ($params['mode'] ?? '') !== '';
        $textgiven = !empty($params['overallfeedbacktextpass']) || !empty($params['overallfeedbacktextfail']);
        $fieldnames = self::mode_field_names();

        if ($modegiven) {
            $moderesolution = create_quiz::normalize_mode($params['mode']);
            $result['mode'] = $moderesolution['mode'];
            $result['deprecatedmode'] = $moderesolution['deprecated'];
            $base = create_quiz::mode_defaults($moderesolution['mode']);
        } else {
            $result['mode'] = '';
            $result['deprecatedmode'] = false;
            $base = array_intersect_key($snapshot, array_flip($fieldnames));
            $base['overallfeedback'] = self::current_overallfeedback_structure((int) $snapshot['quizid']);
        }

        $overridden = create_quiz::apply_field_overrides($base, $params);
        foreach ($fieldnames as $field) {
            $result[$field] = $overridden[$field];
        }

        if (($params['gradepass'] ?? -1) >= 0) {
            $result['gradepass'] = $params['gradepass'];
        } elseif ($modegiven) {
            $result['gradepass'] = $base['gradepass'];
        }

        if (($params['timelimit'] ?? -1) >= 0) {
            $result['timelimit'] = $params['timelimit'];
        } elseif ($modegiven) {
            $result['timelimit'] = $base['timelimit'];
        }

        // timeopen/timeclose (#325): kein Modus-Default, reines Patch-Feld wie
        // timelimit. Sentinel -1 = nicht aendern, 0 = kein Limit.
        if (($params['timeopen'] ?? -1) >= 0) {
            $result['timeopen'] = $params['timeopen'];
        }
        if (($params['timeclose'] ?? -1) >= 0) {
            $result['timeclose'] = $params['timeclose'];
        }

        $result['overallfeedback'] = ($modegiven || $textgiven) ? $overridden['overallfeedback'] : null;

        if (($params['name'] ?? '') !== '') {
            $result['name'] = $params['name'];
        }
        if (($params['intro'] ?? '') !== '') {
            $result['intro'] = $params['intro'];
        }
        if (($params['visible'] ?? -1) >= 0) {
            $result['visible'] = $params['visible'];
        }

        return $result;
    }

    /** Liest die aktuell gespeicherte Gesamtfeedback-Struktur (2- oder 3-stufig). */
    private static function current_overallfeedback_structure(int $quizid): array {
        $records = create_quiz::read_feedback_records($quizid);
        if (count($records) === 3) {
            return ['high' => $records[0]['text'], 'middle' => $records[1]['text'], 'low' => $records[2]['text']];
        }
        if (count($records) === 2) {
            return ['pass' => $records[0]['text'], 'fail' => $records[1]['text']];
        }
        return ['pass' => '', 'fail' => ''];
    }

    /** Schreibt den gepatchten Zustand zurueck; nur explizit geaenderte Werte weichen vom Ist-Zustand ab. */
    public static function persist(\stdClass $cm, array $patched): void {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $quiz->name = $patched['name'];
        $quiz->intro = $patched['intro'];
        $quiz->introformat = FORMAT_HTML;
        $quiz->timelimit = $patched['timelimit'];
        $quiz->timeopen = $patched['timeopen'];
        $quiz->timeclose = $patched['timeclose'];
        foreach (self::quiz_table_field_names() as $field) {
            $quiz->{$field} = $patched[$field];
        }
        $quiz->timemodified = time();
        $DB->update_record('quiz', $quiz);

        $gradeitem = $DB->get_record('grade_items', [
            'courseid' => $cm->course,
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
        ]);
        if ($gradeitem) {
            $gradeitem->gradepass = $patched['gradepass'];
            $gradeitem->timemodified = time();
            $DB->update_record('grade_items', $gradeitem);
        }

        // Nur einschalten, wenn Abschlussverfolgung fuer dieses Quiz tatsaechlich
        // aktiv ist (patched['completion'] > 0) - ein reiner Name-/Sichtbarkeits-
        // Patch soll das Kurs-weite Flag nicht antasten (reines Patch, #322).
        if ($patched['completion'] > 0) {
            $course = $DB->get_record('course', ['id' => $cm->course], 'id, enablecompletion', MUST_EXIST);
            if (!$course->enablecompletion) {
                $DB->set_field('course', 'enablecompletion', 1, ['id' => $cm->course]);
            }
        }
        $DB->set_field('course_modules', 'visible', $patched['visible'], ['id' => $cm->id]);
        $DB->set_field('course_modules', 'completion', $patched['completion'], ['id' => $cm->id]);
        $DB->set_field('course_modules', 'completionview', 0, ['id' => $cm->id]);
        // null statt 0, wenn completionusegrade aus ist - snapshot() liest diese
        // Spalte ueber "!== null" zurueck, ein hartes 0 wuerde immer als "aktiv"
        // gelesen und faelschlich re-persistiert (Snapshot/Patch-Rundreise, #322).
        $DB->set_field('course_modules', 'completiongradeitemnumber', $patched['completionusegrade'] ? 0 : null, ['id' => $cm->id]);
        $DB->set_field('course_modules', 'completionpassgrade', $patched['completionpassgrade'], ['id' => $cm->id]);

        if ($patched['overallfeedback'] !== null) {
            create_quiz::save_overall_feedback((int) $quiz->id, $patched['overallfeedback'], (float) $patched['gradepass']);
        }
    }

    /** Liest die effektiven Settings nach dem Update (konsistent mit assign_settings::result()). */
    public static function result(\stdClass $cm): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $cmrecord = $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST);
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'quiz',
            'iteminstance' => $quiz->id,
            'courseid' => $cm->course,
        ], 'gradepass', IGNORE_MISSING);
        $reviewflags = create_quiz::review_form_flags($quiz);
        $feedbackrecords = create_quiz::read_feedback_records((int) $quiz->id);

        return array_merge([
            'name'               => (string) $quiz->name,
            'visible'            => (int) $cmrecord->visible,
            'preferredbehaviour' => (string) $quiz->preferredbehaviour,
            'questionsperpage'   => (int) $quiz->questionsperpage,
            'attempts'           => (int) $quiz->attempts,
            'grademethod'        => (int) $quiz->grademethod,
            'gradepass'          => (float) ($gradeitem->gradepass ?? 0),
            'timeopen'           => (int) $quiz->timeopen,
            'timeclose'          => (int) $quiz->timeclose,
            'decimalpoints'      => (int) $quiz->decimalpoints,
            'completion'         => (int) $cmrecord->completion,
            'completionusegrade' => isset($cmrecord->completiongradeitemnumber) && $cmrecord->completiongradeitemnumber !== null ? 1 : 0,
            'completionpassgrade'=> (int) $cmrecord->completionpassgrade,
            'reviewrightanswer'  => (int) $quiz->reviewrightanswer,
            'reviewmaxmarks'     => (int) $quiz->reviewmaxmarks,
            'reviewmarks'        => (int) $quiz->reviewmarks,
            'reviewoverallfeedback' => (int) $quiz->reviewoverallfeedback,
            'feedbackboundaries' => create_quiz::feedback_boundaries($feedbackrecords),
            'feedbackrecords'    => $feedbackrecords,
        ], $reviewflags);
    }
}
