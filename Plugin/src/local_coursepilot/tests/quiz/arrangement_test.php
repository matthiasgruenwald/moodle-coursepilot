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

namespace local_coursepilot\quiz;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Anordnungs-Stand eines Tests (#396, Spec 0015 §10): Schnappschuss und
 * Rueckschreiben unabhaengig vom Aenderungsverlauf-Beobachter getestet -
 * {@see \local_coursepilot\observer_test} deckt die Beobachter-Anbindung ab.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(arrangement::class)]
final class arrangement_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass} Kurs, Test, Frage 1, Frage 2.
     */
    private function create_quiz_with_two_questions(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $qbankcontext->id]);

        $question1 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage 1']);
        $question2 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage 2']);
        quiz_add_quiz_question($question1->id, $quiz);
        quiz_add_quiz_question($question2->id, $quiz);

        return [$course, $quiz, $question1, $question2];
    }

    /**
     * @param int $quizid
     * @return \stdClass[] Slot-Zeilen, aufsteigend nach "slot".
     */
    private function slots(int $quizid): array {
        global $DB;
        return array_values($DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot'));
    }

    /**
     * Abnahmekriterium: der Anordnungs-Stand enthaelt Slots (mit
     * Fragereferenz), Abschnitte und Feedback.
     */
    public function test_capture_contains_slots_sections_and_feedback(): void {
        global $DB;

        $this->resetAfterTest();
        [, $quiz, $question1] = $this->create_quiz_with_two_questions();
        $DB->insert_record('quiz_feedback', (object) [
            'quizid' => $quiz->id,
            'feedbacktext' => 'Gut gemacht',
            'feedbacktextformat' => FORMAT_HTML,
            'mingrade' => 50,
            'maxgrade' => 100,
        ]);

        $captured = arrangement::capture((int) $quiz->id);

        $this->assertCount(2, $captured['slots']);
        $this->assertGreaterThanOrEqual(1, count($captured['sections']));
        $this->assertCount(1, $captured['feedback']);
        $this->assertSame('Gut gemacht', $captured['feedback'][0]['feedbacktext']);

        $entryid = (int) $DB->get_field(
            'question_versions',
            'questionbankentryid',
            ['questionid' => $question1->id],
            MUST_EXIST
        );
        $this->assertSame($entryid, $captured['slots'][0]['questionbankentryid']);
        // Frisch angelegte Referenzen zeigen "immer aktuellste" (version=null) -
        // die Schutzschiene Fragereferenzen darf das nicht auf einen Wert pinnen.
        $this->assertNull($captured['slots'][0]['version']);
    }

    /**
     * Abnahmekriterium: eine Umsortierung ist ueber den Anordnungs-Stand
     * erkennbar - {@see arrangement::differs()} liefert true.
     */
    public function test_reordering_slots_is_detected_as_a_difference(): void {
        $this->resetAfterTest();
        [, $quiz] = $this->create_quiz_with_two_questions();

        $before = arrangement::capture((int) $quiz->id);

        $slots = $this->slots((int) $quiz->id);
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        $structure = \mod_quiz\structure::create_for_quiz($quizobj);
        $structure->move_slot($slots[1]->id, 0, 1);

        $after = arrangement::capture((int) $quiz->id);

        $this->assertTrue(arrangement::differs($before, $after));
        $this->assertSame($slots[1]->id, $this->slots((int) $quiz->id)[0]->id);
    }

    /**
     * Abnahmekriterium: eine Umsortierung ist ueber restore() zurueckholbar.
     */
    public function test_restore_recreates_original_slot_order(): void {
        $this->resetAfterTest();
        [, $quiz] = $this->create_quiz_with_two_questions();

        $original = arrangement::capture((int) $quiz->id);
        $originalorder = array_column($original['slots'], 'id');

        $slots = $this->slots((int) $quiz->id);
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        \mod_quiz\structure::create_for_quiz($quizobj)->move_slot($slots[1]->id, 0, 1);
        $this->assertNotSame($originalorder, array_column(arrangement::capture((int) $quiz->id)['slots'], 'id'));

        arrangement::restore((int) $quiz->id, $original);

        $this->assertSame($originalorder, array_column(arrangement::capture((int) $quiz->id)['slots'], 'id'));
    }

    /**
     * Schutzschiene Versuche: quiz_has_attempts() wird VOR dem Schreiben
     * geprueft und bricht mit einer eigenen, klaren Meldung ab - nicht als
     * abgefangene coding_exception aus structure::check_can_be_edited().
     * Nichts wird angefasst, auch nicht teilweise.
     */
    public function test_restore_refuses_before_writing_when_quiz_has_attempts(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz] = $this->create_quiz_with_two_questions();
        $target = arrangement::capture((int) $quiz->id);

        $slots = $this->slots((int) $quiz->id);
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        \mod_quiz\structure::create_for_quiz($quizobj)->move_slot($slots[1]->id, 0, 1);
        $changedorder = array_column(arrangement::capture((int) $quiz->id)['slots'], 'id');

        $student = $this->getDataGenerator()->create_user();
        $this->seed_attempt((int) $quiz->id, (int) $student->id);

        try {
            arrangement::restore((int) $quiz->id, $target);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('arrangementrestoreblocked', $e->errorcode);
        }

        // Nichts wurde geschrieben - die Anordnung ist beim veraenderten Stand geblieben.
        $this->assertSame($changedorder, array_column(arrangement::capture((int) $quiz->id)['slots'], 'id'));
    }

    /**
     * Abnahmekriterium: eine inzwischen bearbeitete Frage erscheint nach der
     * Rueckkehr in ihrer aktuellen Fassung - kein nachtraegliches Pinnen.
     * version=null ("immer aktuellste") bleibt unveraendert null.
     */
    public function test_restore_keeps_version_null_and_shows_current_question_edit(): void {
        global $DB;

        $this->resetAfterTest();
        [, $quiz, $question1] = $this->create_quiz_with_two_questions();
        $target = arrangement::capture((int) $quiz->id);
        $this->assertNull($target['slots'][0]['version']);

        // Frage seither bearbeitet: eine neue Version derselben Fragensammlungs-
        // Eintragung entsteht.
        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $questiongenerator->update_question($question1, null, ['name' => 'Frage 1 (bearbeitet)']);

        // Anordnung veraendern und wiederherstellen (die Fragensammlungs-
        // Bearbeitung selbst ist keine Anordnungsaenderung).
        $slots = $this->slots((int) $quiz->id);
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        \mod_quiz\structure::create_for_quiz($quizobj)->move_slot($slots[1]->id, 0, 1);

        arrangement::restore((int) $quiz->id, $target);

        $reference = $DB->get_record('question_references', [
            'component' => 'mod_quiz',
            'questionarea' => 'slot',
            'itemid' => $target['slots'][0]['id'],
        ], '*', MUST_EXIST);
        $this->assertNull($reference->version, 'version=null muss unveraendert bleiben - kein nachtraegliches Pinnen.');

        $displayedname = $DB->get_field_sql(
            'SELECT q.name
               FROM {question_versions} qv
               JOIN {question} q ON q.id = qv.questionid
              WHERE qv.questionbankentryid = ?
           ORDER BY qv.version DESC',
            [$target['slots'][0]['questionbankentryid']],
            IGNORE_MULTIPLE
        );
        $this->assertSame('Frage 1 (bearbeitet)', $displayedname);
    }

    /**
     * @param int $quizid
     * @param int $userid
     * @return void
     */
    private function seed_attempt(int $quizid, int $userid): void {
        global $DB;
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quizid,
            'userid' => $userid,
            'attempt' => 1,
            'uniqueid' => 0,
            'layout' => '',
            'currentpage' => 0,
            'preview' => 0,
            'state' => 'inprogress',
            'timestart' => time(),
            'timefinish' => 0,
            'timemodified' => time(),
            'timemodifiedoffline' => 0,
        ]);
    }
}
