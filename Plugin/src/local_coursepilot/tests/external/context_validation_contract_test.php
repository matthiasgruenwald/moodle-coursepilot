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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The module of a question bank or quiz is validated and authorised in its own context (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(ensure_question_bank::class)]
#[CoversClass(ensure_quiz_question_categories::class)]
final class context_validation_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /**
     * Course with an editing teacher acting.
     */
    private function setup_teacher(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * Puts the page back into the course context.
     */
    private function reset_page(): void {
        global $PAGE;
        $PAGE = new \moodle_page();
        $PAGE->set_context(\context_course::instance($this->course->id));
    }

    /**
     * Role id of the editing teacher.
     *
     * @return int
     */
    private function teacher_role(): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
    }

    /**
     * Observer that withdraws the plugin right in every newly created module context.
     *
     * @param \core\event\course_module_created $event The event.
     */
    public static function withdraw_plugin_right(\core\event\course_module_created $event): void {
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/coursepilot:use', CAP_PROHIBIT, $roleid, $event->contextid, true);
    }

    public function test_a_created_and_a_reused_bank_become_the_page_context(): void {
        global $PAGE;
        $this->setup_teacher();
        $this->reset_page();
        $created = ensure_question_bank::execute((int) $this->course->id, 'Bank A');
        $this->assertSame((int) $created['contextid'], (int) $PAGE->context->id);

        $this->reset_page();
        $reused = ensure_question_bank::execute((int) $this->course->id, 'Bank A');

        $this->assertFalse($reused['created']);
        $this->assertSame((int) $reused['contextid'], (int) $PAGE->context->id);
    }

    public function test_the_right_to_use_the_plugin_is_checked_in_the_context_of_a_newly_created_bank(): void {
        $this->setup_teacher();
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => '\core\event\course_module_created',
            'callback' => [self::class, 'withdraw_plugin_right'],
            'internal' => true,
            'priority' => 0,
            'includefile' => null,
        ]]);

        $e = $this->caught(fn() => ensure_question_bank::execute((int) $this->course->id, 'Bank B'));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_the_quiz_categories_are_refused_for_a_quiz_hidden_from_the_user(): void {
        $this->setup_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id, 'visible' => 0]);
        assign_capability(
            'moodle/course:viewhiddenactivities',
            CAP_PROHIBIT,
            $this->teacher_role(),
            \context_course::instance($this->course->id)->id,
            true
        );
        accesslib_clear_all_caches_for_unit_testing();

        $e = $this->caught(fn() => ensure_quiz_question_categories::execute((int) $this->course->id, (int) $quiz->cmid));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertNotInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_a_visible_quiz_becomes_the_page_context(): void {
        global $PAGE;
        $this->setup_teacher();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $this->reset_page();

        $result = ensure_quiz_question_categories::execute((int) $this->course->id, (int) $quiz->cmid);

        $this->assertSame((int) $result['contextid'], (int) $PAGE->context->id);
    }
}
