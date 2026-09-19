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

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Abstammungs-Meldung nach dem Klon (Spec 0017 §7.5, Ticket #422): meldet je
 * Frage eines Tests, ob eine eigene Kopie entstand oder die Referenz
 * weiterhin auf den Quellkurs zeigt - reines Lesen, nichts wird geschrieben.
 *
 * Ein gemischter Bestand wird hier nicht ueber einen echten
 * kursuebergreifenden Klon (clone_activity, #421) hergestellt, sondern
 * direkt: eine Fragenkategorie im Zielkurs (= "eigene Kopie" nach einem
 * Klon) und eine Fragenkategorie in einem zweiten, fremden Kurs (= "geteilte
 * Referenz", genau der Zustand, den Moodles Backup/Restore fuer eine
 * Kategorie ausserhalb des Backup-Umfangs hinterlaesst). Der zu pruefende
 * Endpunkt vergleicht ausschliesslich den Kurs der Fragenkategorie mit dem
 * Kurs des Tests - dieser Zustand deckt genau das ab, unabhaengig davon, ob
 * er durch einen echten Klon oder direkt hergestellt wurde.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(report_clone_lineage::class)]
final class report_clone_lineage_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs, Test.
     */
    private function course_with_quiz_and_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $quiz];
    }

    /**
     * @param \stdClass $course Kurs, dessen Modulkontext die Kategorie traegt.
     * @return \stdClass question_categories-Zeile
     */
    private function category_in_own_qbank(\stdClass $course): \stdClass {
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        return $this->getDataGenerator()->get_plugin_generator('core_question')
            ->create_question_category(['contextid' => $qbankcontext->id]);
    }

    private function add_question_to_quiz(\stdClass $quiz, \stdClass $category, string $name): void {
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => $name]);
        quiz_add_quiz_question((int) $question->id, $quiz);
    }

    /**
     * Abnahmekriterium: gemischter Bestand meldet Kopie und geteilte
     * Referenz korrekt, je Frage.
     */
    public function test_reports_own_copy_and_shared_reference_for_mixed_bank(): void {
        $this->resetAfterTest();
        [$course, $quiz] = $this->course_with_quiz_and_teacher();
        $foreigncourse = $this->getDataGenerator()->create_course();

        $owncategory = $this->category_in_own_qbank($course);
        $sharedcategory = $this->category_in_own_qbank($foreigncourse);

        $this->add_question_to_quiz($quiz, $owncategory, 'Eigene Kopie');
        $this->add_question_to_quiz($quiz, $sharedcategory, 'Geteilte Referenz');

        $result = report_clone_lineage::execute((int) $quiz->cmid);
        $result = external_api::clean_returnvalue(report_clone_lineage::execute_returns(), $result);

        $this->assertCount(2, $result['questions']);
        $bystatus = [];
        foreach ($result['questions'] as $entry) {
            $bystatus[$entry['name']] = $entry;
        }

        $this->assertSame('eigene_kopie', $bystatus['Eigene Kopie']['status']);
        $this->assertSame(0, $bystatus['Eigene Kopie']['quellkurs_id']);

        $this->assertSame('geteilte_referenz', $bystatus['Geteilte Referenz']['status']);
        $this->assertSame((int) $foreigncourse->id, $bystatus['Geteilte Referenz']['quellkurs_id']);

        $this->assertStringContainsString('eigene Kopie angelegt', $result['meldung']);
        $this->assertStringContainsString('geteilte Referenz', $result['meldung']);
    }

    /**
     * Das Verdachtsfall-Envelope (T3) wird NICHT mitgefuehrt: dieses
     * Werkzeug schreibt nichts und kann folglich kein Gate ausloesen -
     * fuenf konstant leere Felder in jeder Antwort waeren nur Rauschen
     * (#424 Nachlauf 4).
     */
    public function test_does_not_carry_the_suspect_gate_envelope(): void {
        $this->resetAfterTest();
        [$course, $quiz] = $this->course_with_quiz_and_teacher();
        $category = $this->category_in_own_qbank($course);
        $this->add_question_to_quiz($quiz, $category, 'Frage');

        $result = report_clone_lineage::execute((int) $quiz->cmid);
        $result = external_api::clean_returnvalue(report_clone_lineage::execute_returns(), $result);

        $this->assertSame(['cmid', 'questions', 'meldung'], array_keys($result));
        foreach (array_keys(\local_coursepilot\question_suspect_gate::empty_result()) as $field) {
            $this->assertArrayNotHasKey($field, $result);
        }
    }

    /**
     * Abnahmekriterium: die Pruefung ist ein reiner Lesevorgang - nach dem
     * Aufruf ist der Fragenbestand unveraendert (keine neue idnumber, keine
     * neue/geaenderte question_references-Zeile).
     */
    public function test_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $quiz] = $this->course_with_quiz_and_teacher();
        $foreigncourse = $this->getDataGenerator()->create_course();
        $owncategory = $this->category_in_own_qbank($course);
        $sharedcategory = $this->category_in_own_qbank($foreigncourse);
        $this->add_question_to_quiz($quiz, $owncategory, 'Eigene Kopie');
        $this->add_question_to_quiz($quiz, $sharedcategory, 'Geteilte Referenz');

        $beforeentries = $DB->get_records('question_bank_entries');
        $beforereferences = $DB->get_records('question_references');
        $beforequestions = $DB->get_records('question');

        report_clone_lineage::execute((int) $quiz->cmid);

        $this->assertEquals($beforeentries, $DB->get_records('question_bank_entries'));
        $this->assertEquals($beforereferences, $DB->get_records('question_references'));
        $this->assertEquals($beforequestions, $DB->get_records('question'));
    }

    /**
     * Ein leerer Test meldet das statt eine leere Liste stillschweigend
     * zurueckzugeben.
     */
    public function test_empty_quiz_reports_no_questions(): void {
        $this->resetAfterTest();
        [, $quiz] = $this->course_with_quiz_and_teacher();

        $result = report_clone_lineage::execute((int) $quiz->cmid);
        $result = external_api::clean_returnvalue(report_clone_lineage::execute_returns(), $result);

        $this->assertSame([], $result['questions']);
        $this->assertStringContainsString('keine Fragen', $result['meldung']);
    }

    /**
     * Ein cmid, das kein Test ist, wird klar abgelehnt statt mit einem
     * internen Fehler zu scheitern.
     */
    public function test_rejects_non_quiz_cmid(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_quiz_and_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->expectException(\invalid_parameter_exception::class);
        report_clone_lineage::execute((int) $page->cmid);
    }
}
