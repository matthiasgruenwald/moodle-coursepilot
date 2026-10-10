<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
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
 * Quiz arrangement state in the change history (#396, Spec 0015 §10): like
 * {@see restore_activity_version_test} for the field catalog, here for the
 * question arrangement - slots, question references, sections, feedback.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(restore_activity_version::class)]
#[CoversClass(arrangement::class)]
final class restore_activity_version_quiz_test extends \advanced_testcase {
    /**
     * Creates course, quiz and two questions and then captures a clean
     * baseline (the two slot_created events when adding the
     * questions deliberately do not count among the 16 observed structure events,
     * see db/events.php - version 1 from the creation itself therefore
     * contains no slots yet). Tests restore against this baseline, not
     * against version 1.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass, 4: int} Course, quiz, question 1,
     *         question 2, version number of the baseline.
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
     * Returns slot rows, ascending by "slot".
     *
     * @param int $quizid
     * @return \stdClass[] Slot rows, ascending by "slot".
     */
    private function slots(int $quizid): array {
        global $DB;
        return array_values($DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot'));
    }

    /**
     * Seeds attempt.
     *
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
     * Acceptance criterion: a restore to a state with a differing
     * arrangement writes the question arrangement back via restore_activity_version
     * - without an MCP endpoint of its own, shared with this one.
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
        $this->assertStringContainsString('question arrangement', $result['message']);
        $this->assertStringContainsString('latest version', $result['message']);
    }

    /**
     * Acceptance criterion: if the quiz already has attempts, the
     * restore refuses the arrangement beforehand - as its own moodle_exception, not as a
     * caught exception of the core API - and the message says that the
     * arrangement is only history now.
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
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('arrangementrestoreblocked', $e->errorcode);
            $this->assertStringContainsString('history only', $e->getMessage());
        }

        // The changed arrangement stayed in place - nothing was written.
        $this->assertSame(
            $slots[1]->id,
            $this->slots((int) $quiz->id)[0]->id
        );
    }

    /**
     * Acceptance criterion: question references are restored exactly as
     * stored - version=null stays null, and a question edited in the
     * meantime appears in its current version instead of being pinned to the
     * old version.
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
        $this->assertNull($referenceafter->version, 'version=null must not be pinned retroactively.');

        $currentname = $DB->get_field_sql(
            'SELECT q.name
               FROM {question_versions} qv
               JOIN {question} q ON q.id = qv.questionid
              WHERE qv.questionbankentryid = ?
           ORDER BY qv.version DESC',
            [$reference->questionbankentryid],
            IGNORE_MULTIPLE
        );
        $this->assertSame('Neue Fassung', $currentname, 'Without pinning, version=null shows the current version.');
    }

    /**
     * Acceptance criterion: slot manipulation is not registered as an MCP
     * tool - the question arrangement as a standalone tool is
     * Spec 0017.
     */
    public function test_arrangement_is_not_registered_as_mcp_tool(): void {
        $this->assertFalse(
            is_subclass_of(arrangement::class, \core_external\external_api::class),
            'arrangement must not be a web service endpoint.'
        );

        foreach (tool_registry::service_functions() as $function) {
            $this->assertNotSame(arrangement::class, $function['classname']);
        }
    }

    /**
     * Acceptance criterion 7: the remaining parts of a quiz state
     * (settings) stay unchanged as in ticket 07 - per
     * ADR 0016 quiz still has no write path for settings via
     * restore_activity_version (only update_quiz_settings, which is not part of
     * this ticket). A restore therefore touches only the
     * arrangement, nothing else - an independently changed setting
     * stays untouched.
     */
    public function test_restore_touches_only_arrangement_not_settings(): void {
        global $DB;

        $this->resetAfterTest();
        [, $quiz, , , $baselineversion] = $this->create_quiz_with_two_questions();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $quiz->course, false, MUST_EXIST);
        $originalorder = array_column($this->slots((int) $quiz->id), 'id');

        // Independently changed setting (not via Coursepilot - there
        // is no write path for quiz yet, see ADR 0016).
        $DB->set_field('quiz', 'name', 'Extern geaendert', ['id' => $quiz->id]);
        \mod_quiz\structure::create_for_quiz(\mod_quiz\quiz_settings::create($quiz->id))
            ->move_slot($originalorder[1], 0, 1);

        restore_activity_version::execute($cm->id, $baselineversion);

        $this->assertSame($originalorder, array_column($this->slots((int) $quiz->id), 'id'));
        $this->assertSame('Extern geaendert', $DB->get_field('quiz', 'name', ['id' => $quiz->id]));
    }
}
