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
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\declared_return_types;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact responses, messages and check order of the "ensure" tools for question banks, categories, sections and quiz categories.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(ensure_question_bank::class)]
#[CoversClass(ensure_question_category::class)]
#[CoversClass(ensure_section::class)]
#[CoversClass(ensure_quiz_question_categories::class)]
final class ensure_tools_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /**
     * Course with an editing teacher.
     */
    private function setup_course(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * Prohibits a capability for the editing teacher everywhere.
     *
     * @param string $capability
     */
    private function prohibit(string $capability): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Runs a call under a context restriction to an unrelated course.
     *
     * @param callable $call
     * @return \Throwable|null
     */
    private function restricted(callable $call): ?\Throwable {
        $other = $this->getDataGenerator()->create_course();
        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            return $this->caught($call);
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
    }

    public function test_question_bank_is_created_once_and_reused_with_exact_responses(): void {
        global $DB;
        $this->setup_course();

        $created = ensure_question_bank::execute((int) $this->course->id, 'My bank');
        $reused = ensure_question_bank::execute((int) $this->course->id, 'My bank');

        $this->assert_declared_types($created, ensure_question_bank::execute_returns());
        $this->assert_declared_types($reused, ensure_question_bank::execute_returns());
        $context = \context_module::instance($created['questionbankid']);
        $top = question_get_top_category($context->id, true);
        $this->assertSame([
            'questionbankid' => $created['questionbankid'],
            'name' => 'My bank',
            'contextid' => (int) $context->id,
            'topcategoryid' => (int) $top->id,
            'created' => true,
            'message' => 'Question bank "My bank" created.',
        ], $created);
        $this->assertSame(
            array_replace($created, ['created' => false, 'message' => 'Question bank "My bank" already existed, reusing it.']),
            $reused
        );
        $this->assertSame(1, $DB->count_records('qbank', ['name' => 'My bank']));
    }

    public function test_question_bank_checks_context_and_capabilities_before_creating_anything(): void {
        global $DB;
        $this->setup_course();

        $restricted = $this->restricted(fn() => ensure_question_bank::execute((int) $this->course->id, 'Never'));
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);
        foreach (['local/coursepilot:use', 'moodle/course:manageactivities'] as $capability) {
            $this->prohibit($capability);
            $e = $this->caught(fn() => ensure_question_bank::execute((int) $this->course->id, 'Never'));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability(
                $capability,
                CAP_ALLOW,
                $DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
                \context_system::instance()->id,
                true
            );
            accesslib_clear_all_caches_for_unit_testing();
            accesslib_clear_all_caches_for_unit_testing();
        }
        $this->assertSame(0, $DB->count_records('qbank', ['name' => 'Never']));
    }

    public function test_reusing_an_existing_question_bank_still_needs_the_right_to_manage_activities(): void {
        $this->setup_course();
        ensure_question_bank::execute((int) $this->course->id, 'My bank');
        $this->prohibit('moodle/course:manageactivities');

        $e = $this->caught(fn() => ensure_question_bank::execute((int) $this->course->id, 'My bank'));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_an_existing_question_bank_needs_the_plugin_capability_in_its_own_context(): void {
        global $DB;
        $this->setup_course();
        $bank = ensure_question_bank::execute((int) $this->course->id, 'My bank');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability(
            'local/coursepilot:use',
            CAP_PROHIBIT,
            $roleid,
            \context_module::instance($bank['questionbankid'])->id,
            true
        );

        $e = $this->caught(fn() => ensure_question_bank::execute((int) $this->course->id, 'My bank'));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_category_response_is_exact_for_creation_and_reuse(): void {
        $this->setup_course();
        $bank = ensure_question_bank::execute((int) $this->course->id, 'Bank');

        $created = ensure_question_category::execute('Chapter 1', $bank['topcategoryid']);
        $reused = ensure_question_category::execute('Chapter 1', $bank['topcategoryid']);
        $nested = ensure_question_category::execute('Chapter 1', $created['id']);

        $this->assert_declared_types($created, ensure_question_category::execute_returns());
        $this->assert_declared_types($reused, ensure_question_category::execute_returns());
        $this->assertSame([
            'id' => $created['id'],
            'name' => 'Chapter 1',
            'parent' => $bank['topcategoryid'],
            'contextid' => $bank['contextid'],
            'created' => true,
            'message' => 'Category "Chapter 1" created.',
        ], $created);
        $this->assertSame(
            array_replace($created, ['created' => false, 'message' => 'Category "Chapter 1" already existed, reusing it.']),
            $reused
        );
        $this->assertNotSame($created['id'], $nested['id'], 'The same name below another parent is a new category.');
        $this->assertSame($created['id'], $nested['parent']);
    }

    public function test_category_checks_context_and_capabilities_first(): void {
        global $DB;
        $this->setup_course();
        $bank = ensure_question_bank::execute((int) $this->course->id, 'Bank');

        $restricted = $this->restricted(fn() => ensure_question_category::execute('Never', $bank['topcategoryid']));
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);
        foreach (['local/coursepilot:use', 'moodle/question:managecategory'] as $capability) {
            $this->prohibit($capability);
            $e = $this->caught(fn() => ensure_question_category::execute('Never', $bank['topcategoryid']));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability(
                $capability,
                CAP_ALLOW,
                $DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
                \context_system::instance()->id,
                true
            );
            accesslib_clear_all_caches_for_unit_testing();
            accesslib_clear_all_caches_for_unit_testing();
        }
        $this->assertFalse($DB->record_exists('question_categories', ['name' => 'Never']));
    }

    public function test_section_responses_and_messages_for_creation_reuse_and_rename(): void {
        $this->setup_course();
        $id = (int) \get_fast_modinfo($this->course)->get_section_info(1)->id;

        $created = ensure_section::execute((int) $this->course->id, 3);
        $createdwithname = ensure_section::execute((int) $this->course->id, 4, 'Fresh');
        $reused = ensure_section::execute((int) $this->course->id, 1);
        $renamed = ensure_section::execute((int) $this->course->id, 1, 'Renamed');
        $again = ensure_section::execute((int) $this->course->id, 1, 'Renamed');
        $zero = ensure_section::execute((int) $this->course->id, 0);

        foreach ([$created, $reused, $renamed] as $result) {
            $this->assert_declared_types($result, ensure_section::execute_returns());
        }
        $this->assertSame(
            ['id' => $created['id'], 'sectionnum' => 3, 'name' => '', 'created' => true, 'message' => 'Section 3 created.'],
            $created
        );
        $this->assertSame('Section 4 created, name set to "Fresh".', $createdwithname['message']);
        $this->assertSame('Fresh', $createdwithname['name']);
        $this->assertSame(
            [
                'id' => $id,
                'sectionnum' => 1,
                'name' => '',
                'created' => false,
                'message' => 'Section 1 already existed, name unchanged.',
            ],
            $reused
        );
        $this->assertSame('Section 1 already existed, name changed from "" to "Renamed".', $renamed['message']);
        $this->assertSame('Renamed', $renamed['name']);
        $this->assertSame('Renamed', \get_fast_modinfo($this->course)->get_section_info(1)->name);
        $this->assertSame('Section 1 already existed, name unchanged.', $again['message']);
        $this->assertSame(0, $zero['sectionnum']);
        $this->assertFalse($zero['created']);
    }

    public function test_a_negative_section_number_is_refused_after_the_checks(): void {
        $this->setup_course();

        $e = $this->caught(fn() => ensure_section::execute((int) $this->course->id, -1));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidsectionnum', $e->errorcode);
        $this->assertSame(['sectionnum' => -1], $e->a);

        $restricted = $this->restricted(fn() => ensure_section::execute((int) $this->course->id, -1));
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);
        global $DB;
        foreach (['local/coursepilot:use', 'moodle/course:update'] as $capability) {
            $this->prohibit($capability);
            $denied = $this->caught(fn() => ensure_section::execute((int) $this->course->id, -1));
            $this->assertInstanceOf(\required_capability_exception::class, $denied, $capability);
            assign_capability(
                $capability,
                CAP_ALLOW,
                $DB->get_field('role', 'id', ['shortname' => 'editingteacher']),
                \context_system::instance()->id,
                true
            );
            accesslib_clear_all_caches_for_unit_testing();
            accesslib_clear_all_caches_for_unit_testing();
        }
    }

    public function test_quiz_categories_list_only_the_quiz_and_report_types_and_events(): void {
        global $DB;
        $this->setup_course();
        /** @var \mod_quiz_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $generator->create_instance(['course' => $this->course->id]);
        $other = $generator->create_instance(['course' => $this->course->id]);
        $context = \context_module::instance($quiz->cmid);
        $DB->delete_records('question_categories', ['contextid' => $context->id]);
        $sink = $this->redirectEvents();

        $result = ensure_quiz_question_categories::execute((int) $this->course->id, (int) $quiz->cmid);
        $created = array_filter($sink->get_events(), static fn($e) => $e instanceof \core\event\question_category_created);
        $second = ensure_quiz_question_categories::execute((int) $this->course->id, (int) $quiz->cmid);
        $createdafter = array_filter($sink->get_events(), static fn($e) => $e instanceof \core\event\question_category_created);
        $otherresult = ensure_quiz_question_categories::execute((int) $this->course->id, (int) $other->cmid);

        $this->assert_declared_types($result, ensure_quiz_question_categories::execute_returns());
        $this->assertSame((int) $context->id, $result['contextid']);
        $rows = $DB->get_records('question_categories', ['contextid' => $context->id], 'parent ASC, sortorder ASC, name ASC');
        $expected = array_values(array_map(
            static fn($c) => ['id' => (int) $c->id, 'name' => $c->name, 'parent' => (int) $c->parent],
            $rows
        ));
        $this->assertSame($expected, $result['categories']);
        $this->assertGreaterThanOrEqual(1, count($result['categories']));
        $this->assertCount(count($result['categories']), $created, 'One event per category that did not exist before.');
        $this->assertCount(count($created), $createdafter, 'A repeated call raises no further events.');
        $this->assertSame($result, $second);
        $this->assertContains($result['defaultcategoryid'], array_column($result['categories'], 'id'));
        $this->assertNotSame($result['contextid'], $otherresult['contextid']);
        $this->assertNotContains($result['defaultcategoryid'], array_column($otherresult['categories'], 'id'));
    }

    public function test_quiz_categories_check_the_course_context_before_looking_for_the_quiz(): void {
        $this->setup_course();

        $restricted = $this->restricted(fn() => ensure_quiz_question_categories::execute((int) $this->course->id, 987654));
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $this->prohibit('local/coursepilot:use');
        $denied = $this->caught(fn() => ensure_quiz_question_categories::execute((int) $this->course->id, 987654));
        $this->assertInstanceOf(\required_capability_exception::class, $denied);
    }

    public function test_quiz_categories_refuse_a_quiz_of_another_course_with_a_clear_message(): void {
        $this->setup_course();
        $elsewhere = $this->getDataGenerator()->create_course();
        /** @var \mod_quiz_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $quiz = $modgen->create_instance(['course' => $elsewhere->id]);

        $e = $this->caught(fn() => ensure_quiz_question_categories::execute((int) $this->course->id, (int) $quiz->cmid));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('Selected quiz was not found in this course.', $e->debuginfo);
    }
}
