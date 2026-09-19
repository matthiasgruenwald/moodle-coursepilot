<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\history\version_writer;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/questionlib.php');

/**
 * Quiz-Anschluss (Spec 0017 §7.4, Ticket #420): haengt Fragen in der
 * genannten Reihenfolge ueber Moodles eigenes {@see quiz_add_quiz_question()}
 * an - dieselbe Funktion, die auch die Quiz-Bearbeitungsseite nutzt, inkl.
 * Dublettenpruefung ueber questionbankentryid (false = schon drin, dann kein
 * zweiter Slot). Kein Entfernen, kein Umsortieren, kein Seitenumbruch -
 * reine Anhaenge-Operation, Layout bleibt Moodle-UI (#365).
 *
 * Versuchs-Gate VOR jedem Schreiben (quiz_has_attempts()): gibt es bereits
 * Versuche, bricht der gesamte Aufruf ab, kein Teilerfolg - dasselbe Prinzip
 * wie {@see \local_coursepilot\quiz\arrangement::restore()}.
 *
 * Aenderungsverlauf: quiz_add_quiz_question() loest das native
 * slot_created-Ereignis aus, das NICHT zu den 16 beobachteten
 * mod_quiz-Struktur-Ereignissen zaehlt (db/events.php: neue Frage = Inhalt,
 * keine Anordnungsaenderung). Dieser Endpunkt schnappt deshalb explizit
 * denselben Anordnungs-Stand wie der Beobachter
 * ({@see version_writer::capture_on_update()}) - genau einmal, nur wenn
 * mindestens eine Frage tatsaechlich neu angehaengt wurde.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class add_questions_to_quiz extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID des Tests'),
            'questionids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'questionid einer beliebigen Version der anzuhaengenden Frage'),
                'Fragen in der Reihenfolge, in der sie angehaengt werden sollen, mindestens eine'
            ),
        ]);
    }

    /**
     * @param int $cmid
     * @param array $questionids
     * @return array
     */
    public static function execute(int $cmid, array $questionids): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'questionids' => $questionids,
        ]);

        $cm = get_coursemodule_from_id('quiz', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('mod/quiz:manage', $context);

        if (empty($params['questionids'])) {
            throw new \invalid_parameter_exception('Mindestens eine Frage angeben.');
        }

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        // Versuchs-Gate VOR jedem Schreiben - kein Teilerfolg, keine halb
        // gefuellte Slot-Liste.
        if (quiz_has_attempts((int) $quiz->id)) {
            throw new moodle_exception('addquestionstoquizblocked', 'local_coursepilot', '', ['quizid' => $quiz->id]);
        }

        $quiz->cmid = (int) $cm->id;

        $appended = [];
        $anyadded = false;
        foreach ($params['questionids'] as $questionid) {
            $question = $DB->get_record('question', ['id' => (int) $questionid], '*', MUST_EXIST);
            question_require_capability_on($question, 'use');

            // quiz_add_quiz_question() gibt NUR im Dublettenfall explizit
            // false zurueck - im Erfolgsfall faellt die Funktion ohne
            // "return" durch (PHP liefert dann null, kein true). "!== false"
            // ist deshalb die korrekte Erfolgspruefung, nicht Wahrheitswert.
            $result = quiz_add_quiz_question((int) $questionid, $quiz);
            $added = $result !== false;
            $anyadded = $anyadded || $added;
            $entry = get_question_bank_entry((int) $questionid);

            $appended[] = [
                'questionid' => (int) $questionid,
                'questionbankentryid' => (int) $entry->id,
                'name' => (string) $question->name,
                'added' => (bool) $added,
            ];
        }

        if ($anyadded) {
            version_writer::capture_on_update((int) $cm->id, (int) $USER->id);
        }

        return [
            'cmid' => (int) $cm->id,
            'meldung' => self::build_message($appended),
            'appended' => $appended,
            'slots' => self::slot_state((int) $quiz->id),
        ];
    }

    /**
     * Die Lehrkraft-deutsche Meldung: was wurde angehaengt, was uebersprungen.
     *
     * @param array $appended
     * @return string
     */
    private static function build_message(array $appended): string {
        $added = array_filter($appended, static fn(array $item): bool => $item['added']);
        $skipped = array_filter($appended, static fn(array $item): bool => !$item['added']);

        if (!$added && !$skipped) {
            return 'Keine Frage angehängt.';
        }

        $parts = [];
        if ($added) {
            $names = array_map(static fn(array $item): string => '"' . $item['name'] . '"', $added);
            $parts[] = count($added) . ' Frage(n) angehängt: ' . implode(', ', $names) . '.';
        }
        if ($skipped) {
            $names = array_map(static fn(array $item): string => '"' . $item['name'] . '"', $skipped);
            $parts[] = count($skipped) . ' bereits vorhanden, übersprungen: ' . implode(', ', $names) . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * Slot-Stand des Tests nach dem Anhaengen - Bank-Eintrag, Frage-ID und
     * Versionsnummer je Slot (aktuellste Version, dasselbe Join-Muster wie
     * {@see \local_coursepilot\external\get_quiz_cleanup_plan::execute()}),
     * damit sich das Quiz pruefen laesst, ohne es zu oeffnen.
     *
     * @param int $quizid
     * @return array
     */
    private static function slot_state(int $quizid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT qs.slot, qr.questionbankentryid, qv.questionid, qv.version, q.name AS questionname
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = :component AND qr.questionarea = :area
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                       WHERE v.questionbankentryid = qbe.id)
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]
        );

        $slots = [];
        foreach ($rows as $row) {
            $slots[] = [
                'slot' => (int) $row->slot,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid' => (int) $row->questionid,
                'version' => (int) $row->version,
                'name' => (string) $row->questionname,
            ];
        }
        return $slots;
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID des Tests'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Meldung: angehaengt vs. uebersprungen'),
            'appended' => new external_multiple_structure(new external_single_structure([
                'questionid' => new external_value(PARAM_INT, 'Angefragte questionid'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Frage-Identitaet (Bank-Eintrag)'),
                'name' => new external_value(PARAM_TEXT, 'Fragename'),
                'added' => new external_value(PARAM_BOOL, 'true = neu angehaengt, false = bereits im Quiz, uebersprungen'),
            ]), 'Ergebnis je angefragter Frage, in der angegebenen Reihenfolge'),
            'slots' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Slotnummer'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Frage-Identitaet (Bank-Eintrag)'),
                'questionid' => new external_value(PARAM_INT, 'ID der aktuellsten Fragen-Version'),
                'version' => new external_value(PARAM_INT, 'Versionsnummer der aktuellsten Fragen-Version'),
                'name' => new external_value(PARAM_TEXT, 'Fragename'),
            ]), 'Slot-Stand des Tests nach dem Anhaengen, aufsteigend nach Slot'),
        ]);
    }
}
