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

use context;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use invalid_parameter_exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Abstammungs-Meldung nach dem Klon (Spec 0017 SS7.5, Ticket #422): meldet je
 * Frage eines Tests, ob der Klon (#421) eine eigene Kopie der Frage angelegt
 * hat oder ob die Fragereferenz weiterhin auf den Bank-Eintrag im Quellkurs
 * zeigt. Reines Lesen ueber {question_references} - keine idnumber wird
 * nachgetragen, keine Frage oder Referenz veraendert; die Anbindung an eine
 * Fragenidentitaet (ADR 0015) geschieht weiterhin erst beim ersten echten
 * Schreibzugriff auf die einzelne Frage (z.B. update_mc_question).
 *
 * Unterscheidung eigene Kopie vs. geteilte Referenz: Moodles Backup/Restore
 * kopiert beim kursuebergreifenden Klonen nur Fragenkategorien, die im
 * Backup-Umfang der einzelnen Aktivitaet lagen (typischerweise der eigene
 * Modulkontext des Tests) - eine Referenz auf eine Kategorie ausserhalb
 * dieses Umfangs (z.B. eine separate Fragenbank-Aktivitaet im Quellkurs)
 * bleibt unveraendert auf denselben questionbankentryid zeigen. Der Kurs, in
 * dem die Fragenkategorie tatsaechlich liegt (ueber deren contextid), wird
 * daher mit dem Kurs des Tests verglichen: gleicher Kurs = eigene Kopie,
 * anderer Kurs = geteilte Referenz.
 *
 * Antwortform ohne das gemeinsame Verdachtsfall-Gate-Format
 * ({@see \local_coursepilot\question_suspect_gate}): das Envelope wurde
 * anfangs der Einheitlichkeit halber mitgefuehrt, kann hier aber nie
 * gefuellt werden - dieses Werkzeug schreibt nichts und loest folglich kein
 * Gate aus. Fuenf konstant leere Felder in jeder Antwort sind keine
 * Einheitlichkeit, sondern Rauschen (#424 Nachlauf 4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class report_clone_lineage extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID des Tests (mod_quiz), i.d.R. das Ergebnis eines vorherigen clone_activity'),
        ]);
    }

    /**
     * @param int $cmid
     * @return array
     * @throws invalid_parameter_exception
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        if ($cm->modname !== 'quiz') {
            throw new invalid_parameter_exception('cmid muss ein Test (mod_quiz) sein, hier: "' . $cm->modname . '".');
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // moodle/question:view existiert nicht (mehr); Moodle kennt nur
        // moodle/question:viewall/viewmine (Vorbild: get_question, export_questions_xml).
        require_capability('moodle/question:viewall', $context);

        $rows = self::slot_lineage_rows((int) $cm->instance);
        $courseid = (int) $cm->course;

        $questions = [];
        $contextcoursecache = [];
        foreach ($rows as $row) {
            $entrycourseid = self::course_of_context((int) $row->categorycontextid, $contextcoursecache);
            $owncopy = $entrycourseid !== 0 && $entrycourseid === $courseid;

            $questions[] = [
                'slot' => (int) $row->slot,
                'questionbankentryid' => (int) $row->questionbankentryid,
                'questionid' => (int) $row->questionid,
                'name' => (string) $row->questionname,
                'idnumber' => (string) ($row->entryidnumber ?? ''),
                'status' => $owncopy ? 'eigene_kopie' : 'geteilte_referenz',
                'quellkurs_id' => $owncopy ? 0 : $entrycourseid,
            ];
        }

        return [
            'cmid' => $cm->id,
            'questions' => $questions,
            'meldung' => self::build_message($questions),
        ];
    }

    /**
     * Slots eines Tests mit Fragereferenz auf die jeweils aktuellste Version -
     * dasselbe Join-Muster wie {@see \local_coursepilot\external\add_questions_to_quiz::slot_state()},
     * zusaetzlich die Fragenkategorie (fuer die Kurszuordnung ueber deren
     * contextid) und die idnumber des Bank-Eintrags.
     *
     * @param int $quizid
     * @return \stdClass[]
     */
    private static function slot_lineage_rows(int $quizid): array {
        global $DB;

        return array_values($DB->get_records_sql(
            'SELECT qs.slot, qr.questionbankentryid, qbe.idnumber AS entryidnumber, qc.contextid AS categorycontextid,
                    qv.questionid, q.name AS questionname
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.itemid = qs.id
                    AND qr.component = :component AND qr.questionarea = :area
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
                    AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                       WHERE v.questionbankentryid = qbe.id)
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]
        ));
    }

    /**
     * Kurs-ID des Kurses, dem ein Kontext angehoert (0, wenn keiner - z.B.
     * Systemkontext). Je Kontext-ID gecacht, damit ein Test mit vielen
     * Fragen aus derselben Kategorie nicht wiederholt aufloest.
     *
     * @param int $contextid
     * @param array<int, int> $cache Referenz, contextid => courseid
     * @return int
     */
    private static function course_of_context(int $contextid, array &$cache): int {
        if (array_key_exists($contextid, $cache)) {
            return $cache[$contextid];
        }

        $context = context::instance_by_id($contextid, IGNORE_MISSING);
        $coursecontext = $context ? $context->get_course_context(false) : false;
        $courseid = $coursecontext ? (int) $coursecontext->instanceid : 0;

        $cache[$contextid] = $courseid;
        return $courseid;
    }

    /**
     * @param array $questions
     * @return string
     */
    private static function build_message(array $questions): string {
        if (!$questions) {
            return 'Der Test enthält keine Fragen.';
        }

        $owncopies = 0;
        $shared = 0;
        foreach ($questions as $question) {
            if ($question['status'] === 'eigene_kopie') {
                $owncopies++;
            } else {
                $shared++;
            }
        }

        if ($shared === 0) {
            return $owncopies . ' Frage(n) als eigene Kopie angelegt.';
        }
        if ($owncopies === 0) {
            return $shared . ' Frage(n) zeigen weiterhin auf den Quellkurs (geteilte Referenz) - eine Korrektur '
                . 'dort ändert auch dort die Frage.';
        }

        return $owncopies . ' Frage(n) als eigene Kopie angelegt, ' . $shared . ' Frage(n) zeigen weiterhin auf '
            . 'den Quellkurs (geteilte Referenz).';
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID des gepruften Tests'),
            'questions' => new external_multiple_structure(new external_single_structure([
                'slot' => new external_value(PARAM_INT, 'Slotnummer im Test'),
                'questionbankentryid' => new external_value(PARAM_INT, 'Frage-Identitaet (Bank-Eintrag), auf den der Slot zeigt'),
                'questionid' => new external_value(PARAM_INT, 'ID der aktuellsten Fragen-Version'),
                'name' => new external_value(PARAM_TEXT, 'Fragename'),
                'idnumber' => new external_value(PARAM_TEXT, 'idnumber des Bank-Eintrags, leer wenn keine vergeben'),
                'status' => new external_value(PARAM_ALPHANUMEXT, '"eigene_kopie" oder "geteilte_referenz"'),
                'quellkurs_id' => new external_value(PARAM_INT, 'Kurs-ID, auf den die Referenz noch zeigt (0 bei "eigene_kopie")'),
            ]), 'Ergebnis je Slot des Tests, in Slot-Reihenfolge'),
            'meldung' => new external_value(PARAM_RAW, 'Lehrkraft-deutsche Zusammenfassung: eigene Kopien vs. geteilte Referenzen'),
        ]);
    }
}
