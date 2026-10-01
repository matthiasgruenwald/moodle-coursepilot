<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_module;
use moodle_exception;

/**
 * Setzt Zugangsvoraussetzungen (Restrict Access) fuer eine Aktivitaet.
 *
 * Eine Aktivitaet kann mehrere Voraussetzungen haben:
 *   - Abschluss einer anderen Aktivitaet (type=completion)
 *   - Quiz bestanden (type=grade auf grade_item des Quiz, min=gradepass) – #10
 *
 * Das availability-JSON-Format von Moodle (Completion-Modus):
 * {
 *   "op": "&",           // & = alle Bedingungen, | = eine reicht
 *   "c": [
 *     {"type":"completion","cm":CMID,"e":1}   // e=1: muss abgeschlossen sein
 *   ],
 *   "showc": [true]      // true = gesperrte Aktivitaet anzeigen (ausgegraut)
 * }
 *
 * Das availability-JSON-Format fuer "Quiz bestanden" (#10):
 * {
 *   "op": "&",
 *   "c": [
 *     {"type":"grade","id":GRADE_ITEM_ID,"min":GRADEPASS}
 *   ],
 *   "showc": [true]
 * }
 *
 * condition_type:
 *   ''             = Standard, completion-basiert auf require_cmids
 *   'quiz_passed'  = Notenbedingung "Bestehensgrenze des Quiz erreicht"
 */
class set_restriction extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'                  => new external_value(PARAM_INT,
                'Course module ID der Aktivitaet die gesperrt werden soll'),
            'require_cmids'         => new external_multiple_structure(
                new external_value(PARAM_INT, 'cmid einer Aktivitaet die abgeschlossen sein muss'),
                'Liste der cmids die abgeschlossen sein muessen (AND-Verknuepfung)',
                VALUE_DEFAULT, []
            ),
            'show_locked'           => new external_value(PARAM_INT,
                '1 = gesperrte Aktivitaet ausgegraut anzeigen, 0 = komplett verstecken',
                VALUE_DEFAULT, 1),
            'operator'              => new external_value(PARAM_ALPHA,
                'AND = alle Bedingungen, OR = eine reicht',
                VALUE_DEFAULT, 'AND'),
            'condition_type'        => new external_value(PARAM_ALPHANUMEXT,
                'Spezial-Bedingung: "" (Standard, completion) oder "quiz_passed" (Notenbedingung Bestehensgrenze, #10)',
                VALUE_DEFAULT, ''),
            'condition_target_cmid' => new external_value(PARAM_INT,
                'Ziel-cmid fuer Spezial-Bedingung (z.B. cmid des Quiz fuer quiz_passed). 0 = nicht verwendet.',
                VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int    $cmid,
        array  $require_cmids = [],
        int    $show_locked = 1,
        string $operator = 'AND',
        string $condition_type = '',
        int    $condition_target_cmid = 0
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'                  => $cmid,
            'require_cmids'         => $require_cmids,
            'show_locked'           => $show_locked,
            'operator'              => $operator,
            'condition_type'        => $condition_type,
            'condition_target_cmid' => $condition_target_cmid,
        ]);

        // Kontext und Rechte
        $cm = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);

        $op = ($params['operator'] === 'OR') ? '|' : '&';
        $showc_bool = (bool) $params['show_locked'];

        self::guard_existing_availability($cm);

        // -------------------------------------------------------------
        // Spezial-Pfad: quiz_passed (Notenbedingung auf grade_item)
        // -------------------------------------------------------------
        if ($params['condition_type'] === 'quiz_passed') {
            if ($params['condition_target_cmid'] <= 0) {
                throw new moodle_exception('invalidparameter', 'debug',
                    '', null, 'condition_target_cmid muss > 0 sein fuer condition_type=quiz_passed');
            }

            $targetcm = get_coursemodule_from_id('quiz', $params['condition_target_cmid'], 0, false, MUST_EXIST);

            // Quiz-Instanz + grade_item ermitteln. gradepass liegt nur in
            // grade_items (mod_quiz hat seit Moodle 5.0 keine eigene
            // gradepass-Spalte mehr).
            $quiz = $DB->get_record('quiz', ['id' => $targetcm->instance], 'id', MUST_EXIST);
            $gradeitem = $DB->get_record('grade_items', [
                'itemtype'     => 'mod',
                'itemmodule'   => 'quiz',
                'iteminstance' => $quiz->id,
                'courseid'     => $targetcm->course,
            ], 'id, gradepass, grademax', MUST_EXIST);

            $gradepass = (float) $gradeitem->gradepass;
            if ($gradepass <= 0) {
                throw new moodle_exception('invalidparameter', 'debug',
                    '', null, 'Ziel-Quiz hat keine Bestehensgrenze (gradepass=0) – Restriction nicht moeglich.');
            }

            $availability = json_encode([
                'op'    => $op,
                'c'     => [[
                    'type' => 'grade',
                    'id'   => (int) $gradeitem->id,
                    'min'  => $gradepass,
                ]],
                'showc' => [$showc_bool],
            ]);

            $DB->set_field('course_modules', 'availability', $availability, ['id' => $cm->id]);
            rebuild_course_cache($cm->course, true);

            return [
                'cmid'    => (int) $cm->id,
                'message' => 'Restriction "quiz bestanden" (cmid ' . $params['condition_target_cmid']
                    . ', min=' . $gradepass . ') fuer cmid ' . $cm->id . ' gesetzt.',
            ];
        }

        // -------------------------------------------------------------
        // Standard-Pfad: completion-basierte Bedingungen
        // -------------------------------------------------------------
        if (empty($params['require_cmids'])) {
            // Voraussetzungen loeschen
            $DB->set_field('course_modules', 'availability', null, ['id' => $cm->id]);
            rebuild_course_cache($cm->course, true);
            return [
                'cmid'    => (int) $cm->id,
                'message' => 'Voraussetzungen fuer cmid ' . $cm->id . ' entfernt.',
            ];
        }

        $conditions = [];
        $showconds  = [];
        foreach ($params['require_cmids'] as $req_cmid) {
            $conditions[] = [
                'type' => 'completion',
                'cm'   => (int) $req_cmid,
                'e'    => 1,  // e=1: muss abgeschlossen sein
            ];
            $showconds[] = $showc_bool;
        }

        $availability = json_encode([
            'op'    => $op,
            'c'     => $conditions,
            'showc' => $showconds,
        ]);

        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cm->id]);
        rebuild_course_cache($cm->course, true);

        return [
            'cmid'    => (int) $cm->id,
            'message' => 'Voraussetzungen fuer cmid ' . $cm->id . ' gesetzt: '
                . implode(', ', $params['require_cmids']),
        ];
    }

    /**
     * Prüft, ob die vorhandene availability nur Bedingungen enthält, die
     * dieses Tool selbst erzeugt haben könnte (completion oder grade, flach).
     * Alles andere → moodle_exception, kein Schreiben.
     */
    private static function guard_existing_availability(object $cm): void {
        global $DB;
        $existing = $DB->get_field('course_modules', 'availability', ['id' => $cm->id]);
        if (empty($existing)) {
            return;
        }
        $data = json_decode($existing, true);
        if ($data === null || !isset($data['c']) || !is_array($data['c'])) {
            throw new moodle_exception('invalidparameter', 'local_coursepilot', '', null,
                'Diese Aktivität hat Zugangsvoraussetzungen, die Kurspilot nicht verwalten kann '
                . '(z. B. personenbezogen oder datumsabhängig). Bitte im Moodle-UI ändern.');
        }
        foreach ($data['c'] as $condition) {
            $type = $condition['type'] ?? '';
            // Nested operator node or unknown type → foreign condition.
            if (isset($condition['c']) || !in_array($type, ['completion', 'grade'], true)) {
                throw new moodle_exception('invalidparameter', 'local_coursepilot', '', null,
                    'Diese Aktivität hat Zugangsvoraussetzungen, die Kurspilot nicht verwalten kann '
                    . '(z. B. personenbezogen oder datumsabhängig). Bitte im Moodle-UI ändern.');
            }
        }
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid'    => new external_value(PARAM_INT,  'Course module ID'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
