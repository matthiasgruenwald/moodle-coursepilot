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
 * Quiz-Anschluss (Spec 0017 §7.4, Ticket #420): Anhaengen in Reihenfolge,
 * Dublettenpruefung, Slot-Stand mit aktuellster Version, Versuchs-Gate.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(add_questions_to_quiz::class)]
final class add_questions_to_quiz_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Kurs, Test, Fragenkategorie.
     */
    private function create_course_with_quiz(): array {
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $qbankcontext->id]);
        return [$course, $quiz, $category];
    }

    /**
     * @param \stdClass $course
     * @return \stdClass
     */
    private function login_editing_teacher(\stdClass $course): \stdClass {
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $teacher;
    }

    /**
     * Abnahmekriterium: Fragen werden in der genannten Reihenfolge angehaengt.
     */
    public function test_appends_questions_in_given_order(): void {
        $this->resetAfterTest();
        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $this->login_editing_teacher($course);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question1 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage A']);
        $question2 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage B']);

        $result = add_questions_to_quiz::execute((int) $quiz->cmid, [$question2->id, $question1->id]);
        $result = external_api::clean_returnvalue(add_questions_to_quiz::execute_returns(), $result);

        $this->assertCount(2, $result['slots']);
        $this->assertSame('Frage B', $result['slots'][0]['name']);
        $this->assertSame('Frage A', $result['slots'][1]['name']);
        $this->assertSame(1, $result['slots'][0]['slot']);
        $this->assertSame(2, $result['slots'][1]['slot']);
        $this->assertTrue($result['appended'][0]['added']);
        $this->assertTrue($result['appended'][1]['added']);
    }

    /**
     * Abnahmekriterium: eine bereits vorhandene Frage wird uebersprungen und
     * im Ergebnis als solche ausgewiesen ("added": false), kein zweiter Slot.
     */
    public function test_skips_already_present_question_and_marks_it(): void {
        $this->resetAfterTest();
        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $this->login_editing_teacher($course);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage A']);
        quiz_add_quiz_question($question->id, $quiz);

        $result = add_questions_to_quiz::execute((int) $quiz->cmid, [$question->id]);
        $result = external_api::clean_returnvalue(add_questions_to_quiz::execute_returns(), $result);

        $this->assertCount(1, $result['slots'], 'Kein zweiter Slot fuer dieselbe Frage.');
        $this->assertFalse($result['appended'][0]['added']);
        $this->assertStringContainsString('übersprungen', $result['meldung']);
    }

    /**
     * Abnahmekriterium: der Slot-Stand zeigt die aktuellste Version je Frage,
     * nicht die urspruenglich angehaengte.
     */
    public function test_slots_show_latest_version(): void {
        $this->resetAfterTest();
        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $this->login_editing_teacher($course);

        /** @var \core_question_generator $questiongenerator */
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage A']);

        $result = add_questions_to_quiz::execute((int) $quiz->cmid, [$question->id]);
        $result = external_api::clean_returnvalue(add_questions_to_quiz::execute_returns(), $result);
        $this->assertSame(1, $result['slots'][0]['version']);

        $questiongenerator->update_question($question, null, ['name' => 'Frage A (bearbeitet)']);

        $result2 = add_questions_to_quiz::execute((int) $quiz->cmid, [$question->id]);
        $result2 = external_api::clean_returnvalue(add_questions_to_quiz::execute_returns(), $result2);

        $this->assertCount(1, $result2['slots'], 'Bearbeitete Frage bleibt derselbe Bank-Eintrag, kein zweiter Slot.');
        $this->assertSame(2, $result2['slots'][0]['version']);
        $this->assertSame('Frage A (bearbeitet)', $result2['slots'][0]['name']);
    }

    /**
     * Abnahmekriterium: bei vorhandenen Versuchen kommt eine klare Absage,
     * keine Slot-Aenderung - kein Teilerfolg.
     */
    public function test_refuses_with_no_change_when_quiz_has_attempts(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $teacher = $this->login_editing_teacher($course);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $existing = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage A']);
        quiz_add_quiz_question($existing->id, $quiz);
        $newquestion = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage B']);

        $this->seed_attempt((int) $quiz->id, (int) $teacher->id);

        try {
            add_questions_to_quiz::execute((int) $quiz->cmid, [$newquestion->id]);
            $this->fail('Erwartete moodle_exception blieb aus.');
        } catch (\moodle_exception $e) {
            $this->assertSame('addquestionstoquizblocked', $e->errorcode);
        }

        $this->assertCount(1, $DB->get_records('quiz_slots', ['quizid' => $quiz->id]), 'Kein Slot wurde hinzugefuegt.');
    }

    /**
     * Abnahmekriterium: der Vorgang erzeugt einen Aenderungsverlauf-Stand
     * ueber die Quiz-Struktur (arrangement_json mit dem neuen Slot).
     */
    public function test_creates_change_history_entry_with_new_slot(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $this->login_editing_teacher($course);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $before = (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage A']);
        add_questions_to_quiz::execute((int) $quiz->cmid, [$question->id]);

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertGreaterThan($before, count($versions));

        $newest = end($versions);
        $arrangement = json_decode($newest->arrangement_json, true);
        $this->assertCount(1, $arrangement['slots']);
    }

    /**
     * Ein Aufruf, der ausschliesslich bereits vorhandene Fragen nennt,
     * aendert nichts an der Anordnung - dann entsteht auch kein neuer
     * Aenderungsverlauf-Stand (kein Rauschen ohne echte Aenderung).
     */
    public function test_pure_duplicate_call_creates_no_new_change_history_stand(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $this->login_editing_teacher($course);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Frage A']);
        quiz_add_quiz_question($question->id, $quiz);

        $before = (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        add_questions_to_quiz::execute((int) $quiz->cmid, [$question->id]);

        $this->assertSame($before, (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
    }

    /**
     * Fall ohne Berechtigung: mod/quiz:manage fehlt trotz Einschreibung.
     */
    public function test_rejects_user_without_capability(): void {
        $this->resetAfterTest();
        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $teacher = $this->login_editing_teacher($course);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);

        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'mod/quiz:manage',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);

        add_questions_to_quiz::execute((int) $quiz->cmid, [$question->id]);
    }

    /**
     * Der Endpunkt haengt am Coursepilot-Dienst und steht in der Allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'coursepilot_add_questions_to_quiz',
            \local_coursepilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_coursepilot_add_questions_to_quiz',
            \local_coursepilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_add_questions_to_quiz'));
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
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
