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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Bereinigungsplan fuer Tests serverseitig (#342): eigenstaendige
 * Portierung von local_coursepilot\external\get_quiz_cleanup_plan, Vertrag
 * (Feldnamen, nicht-destruktive Handlungsanweisung) identisch zum lokalen
 * Werkzeug - loescht nichts, nennt nur Fundstelle und Link.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_quiz_cleanup_plan::class)]
final class get_quiz_cleanup_plan_test extends \advanced_testcase {

    /**
     * Regelfall: ein Slot, dessen Frage nicht in keep_questionbankentryids
     * steht, erscheint als manuelle, nicht-destruktive Handlungsanweisung
     * mit Fundstelle (Slot, Frage, Kategorie) und Moodle-Link - Coursepilot
     * loescht selbst nichts.
     */
    public function test_lists_removable_slot_as_manual_non_destructive_instruction(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('multichoice', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        $entryid = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id], MUST_EXIST);

        $result = get_quiz_cleanup_plan::execute((int) $quiz->cmid, []);
        $result = external_api::clean_returnvalue(get_quiz_cleanup_plan::execute_returns(), $result);

        $this->assertCount(1, $result['removals']);
        $removal = $result['removals'][0];
        $this->assertSame($entryid, $removal['questionbankentryid']);
        $this->assertSame($question->name, $removal['questionname']);
        $this->assertStringContainsString('nicht aus der Fragensammlung gelöscht', $removal['reason']);
        $this->assertStringContainsString('/mod/quiz/edit.php?cmid=' . $quiz->cmid, $result['editurl']);

        // Immer noch vorhanden: Coursepilot hat den Slot nicht geloescht.
        $this->assertTrue($DB->record_exists('quiz_slots', ['quizid' => $quiz->id]));
    }

    /**
     * keep_questionbankentryids nimmt einen Slot aus dem Plan heraus.
     */
    public function test_kept_entry_is_not_listed_for_removal(): void {
        global $DB;

        $this->resetAfterTest();

        [$course, $quiz, $category] = $this->create_course_with_quiz();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $questiongenerator->create_question('multichoice', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        $entryid = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id], MUST_EXIST);

        $result = get_quiz_cleanup_plan::execute((int) $quiz->cmid, [$entryid]);
        $result = external_api::clean_returnvalue(get_quiz_cleanup_plan::execute_returns(), $result);

        $this->assertCount(0, $result['removals']);
    }

    /**
     * Fall ohne Berechtigung: local/coursepilot:use fehlt trotz Einschreibung.
     */
    public function test_rejects_user_without_capability(): void {
        $this->resetAfterTest();

        [$course, $quiz] = $this->create_course_with_quiz();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:use',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);

        get_quiz_cleanup_plan::execute((int) $quiz->cmid, []);
    }

    /**
     * Fall ohne Einschreibung: eine nicht eingeschriebene Person bekommt
     * keine Daten.
     */
    public function test_rejects_user_without_enrolment(): void {
        $this->resetAfterTest();

        [, $quiz] = $this->create_course_with_quiz();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);

        get_quiz_cleanup_plan::execute((int) $quiz->cmid, []);
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass}
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
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
