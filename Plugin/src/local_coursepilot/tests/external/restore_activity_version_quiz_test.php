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
use local_coursepilot\history\version_writer;
use local_coursepilot\quiz\arrangement;
use local_coursepilot\tool_registry;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Quiz-Anordnungs-Stand im Aenderungsverlauf (#396, Spec 0015 §10): wie
 * {@see restore_activity_version_test} fuer den Feldkatalog, hier fuer die
 * Fragenanordnung - Slots, Fragereferenzen, Abschnitte, Feedback.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(restore_activity_version::class)]
#[CoversClass(arrangement::class)]
final class restore_activity_version_quiz_test extends \advanced_testcase {

    /**
     * Legt Kurs, Test und zwei Fragen an und schnappt danach einen sauberen
     * Ausgangsstand (die beiden slot_created-Ereignisse beim Hinzufuegen der
     * Fragen zaehlen bewusst nicht zu den 16 beobachteten Struktur-Ereignissen,
     * siehe db/events.php - Version 1 aus dem Anlegen selbst enthaelt deshalb
     * noch keine Slots). Tests restaurieren gegen diesen Ausgangsstand, nicht
     * gegen Version 1.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass, 4: int} Kurs, Test, Frage 1,
     *         Frage 2, Versionsnummer des Ausgangsstandes.
     */
    private function create_quiz_with_two_questions(): array {
        global $DB, $USER;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $qbankcontext->id]);

        $question1 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        $question2 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question1->id, $quiz);
        quiz_add_quiz_question($question2->id, $quiz);

        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $versionid = version_writer::capture_on_update($cm->id, (int) $USER->id);
        $baselineversion = (int) $DB->get_field('local_coursepilot_cm_version', 'version', ['id' => $versionid], MUST_EXIST);

        return [$course, $quiz, $question1, $question2, $baselineversion];
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

    /**
     * Abnahmekriterium: eine Rueckkehr auf einen Stand mit abweichender
     * Anordnung schreibt die Fragenanordnung ueber restore_activity_version
     * zurueck - ohne eigenen MCP-Endpunkt, mitgenutzt von diesem.
     */
    public function test_restore_writes_back_reordered_slots(): void {
        global $DB;

        $this->resetAfterTest();
        [, $quiz, , , $baselineversion] = $this->create_quiz_with_two_questions();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $originalorder = array_column($this->slots((int) $quiz->id), 'id');

        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        \mod_quiz\structure::create_for_quiz($quizobj)->move_slot($originalorder[1], 0, 1);
        $this->assertNotSame($originalorder, array_column($this->slots((int) $quiz->id), 'id'));

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cm->id, $baselineversion)
        );

        $this->assertSame($originalorder, array_column($this->slots((int) $quiz->id), 'id'));
        $this->assertStringContainsString('Fragenanordnung', $result['meldung']);
        $this->assertStringContainsString('neuesten Fassung', $result['meldung']);
    }

    /**
     * Abnahmekriterium: hat der Test bereits Versuche, verweigert die
     * Rueckkehr die Anordnung vorher - als eigene moodle_exception, nicht als
     * abgefangene Ausnahme der Core-API -, und die Meldung sagt, dass die
     * Anordnung nur noch Chronik ist.
     */
    public function test_restore_refuses_arrangement_when_quiz_has_attempts(): void {
        global $DB;

        $this->resetAfterTest();
        [, $quiz, , , $baselineversion] = $this->create_quiz_with_two_questions();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);

        $slots = $this->slots((int) $quiz->id);
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        \mod_quiz\structure::create_for_quiz($quizobj)->move_slot($slots[1]->id, 0, 1);

        $student = $this->getDataGenerator()->create_user();
        $this->seed_attempt((int) $quiz->id, (int) $student->id);

        try {
            restore_activity_version::execute($cm->id, $baselineversion);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('arrangementrestoreblocked', $e->errorcode);
            $this->assertStringContainsString('history only', $e->getMessage());
        }

        // Die veraenderte Anordnung blieb stehen - nichts wurde geschrieben.
        $this->assertSame(
            $slots[1]->id,
            $this->slots((int) $quiz->id)[0]->id
        );
    }

    /**
     * Abnahmekriterium: Fragereferenzen werden exakt wie gespeichert
     * wiederhergestellt - version=null bleibt null, und eine inzwischen
     * bearbeitete Frage erscheint in ihrer aktuellen Fassung statt auf die
     * alte Fassung gepinnt zu werden.
     */
    public function test_restore_keeps_question_references_exact_and_unpinned(): void {
        global $DB;

        $this->resetAfterTest();
        [, $quiz, $question1, , $baselineversion] = $this->create_quiz_with_two_questions();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $slots = $this->slots((int) $quiz->id);
        $firstslotid = $slots[0]->id;

        $reference = $DB->get_record('question_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $firstslotid,
        ], '*', MUST_EXIST);
        $this->assertNull($reference->version);

        \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($quiz->id))
            ->move_slot($slots[1]->id, 0, 1);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $questiongenerator->update_question($question1, null, ['name' => 'Neue Fassung']);

        restore_activity_version::execute($cm->id, $baselineversion);

        $referenceafter = $DB->get_record('question_references', [
            'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $firstslotid,
        ], '*', MUST_EXIST);
        $this->assertNull($referenceafter->version, 'version=null darf nicht nachtraeglich gepinnt werden.');

        $currentname = $DB->get_field_sql(
            'SELECT q.name
               FROM {question_versions} qv
               JOIN {question} q ON q.id = qv.questionid
              WHERE qv.questionbankentryid = ?
           ORDER BY qv.version DESC',
            [$reference->questionbankentryid],
            IGNORE_MULTIPLE
        );
        $this->assertSame('Neue Fassung', $currentname, 'Ohne Pinnen zeigt version=null die aktuelle Fassung.');
    }

    /**
     * Abnahmekriterium: die Slot-Manipulation ist nicht als MCP-Werkzeug
     * registriert - die Fragenanordnung als eigenstaendiges Werkzeug ist
     * Spec 0017.
     */
    public function test_arrangement_is_not_registered_as_mcp_tool(): void {
        $this->assertFalse(
            is_subclass_of(arrangement::class, \core_external\external_api::class),
            'arrangement darf kein Webservice-Endpunkt sein.'
        );

        foreach (tool_registry::service_functions() as $function) {
            $this->assertNotSame(arrangement::class, $function['classname']);
        }
    }

    /**
     * Abnahmekriterium 7: die uebrigen Bestandteile eines Test-Standes
     * (Einstellungen) bleiben unveraendert wie in Ticket 07 - quiz hat laut
     * ADR 0016 fuer Einstellungen weiterhin keinen Schreibweg ueber
     * restore_activity_version (nur update_quiz_settings, das nicht Teil
     * dieses Tickets ist). Eine Rueckkehr ruehrt deshalb ausschliesslich die
     * Anordnung an, nichts sonst - eine unabhaengig geaenderte Einstellung
     * bleibt unangetastet stehen.
     */
    public function test_restore_touches_only_arrangement_not_settings(): void {
        global $DB;

        $this->resetAfterTest();
        [, $quiz, , , $baselineversion] = $this->create_quiz_with_two_questions();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $originalorder = array_column($this->slots((int) $quiz->id), 'id');

        // Unabhaengig geaenderte Einstellung (nicht ueber Coursepilot - dafuer
        // gibt es fuer quiz noch keinen Schreibweg, siehe ADR 0016).
        $DB->set_field('quiz', 'name', 'Extern geaendert', ['id' => $quiz->id]);
        \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($quiz->id))
            ->move_slot($originalorder[1], 0, 1);

        restore_activity_version::execute($cm->id, $baselineversion);

        $this->assertSame($originalorder, array_column($this->slots((int) $quiz->id), 'id'));
        $this->assertSame('Extern geaendert', $DB->get_field('quiz', 'name', ['id' => $quiz->id]));
    }
}
