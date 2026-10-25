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
use local_coursepilot\cm_references;
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\declared_return_types;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact reports, guards and their order for the quiz cleanup plan, default export and XML creation tools.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_quiz_cleanup_plan::class)]
#[CoversClass(export_default_activity::class)]
#[CoversClass(create_activity_from_xml::class)]
final class activity_tools_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /**
     * Course with an editing teacher.
     */
    private function setup_course(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * Prohibits a capability for the editing teacher role.
     *
     * @param string $capability
     * @param int|null $contextid System context when null.
     */
    private function prohibit(string $capability, ?int $contextid = null): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, $contextid ?? \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Allows a capability again for the editing teacher role at system level.
     *
     * @param string $capability
     */
    private function allow(string $capability): void {
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Quiz with a decoy quiz first, two questions in the target and one in the decoy.
     *
     * @return array{0: \stdClass, 1: \stdClass[], 2: \stdClass}
     */
    private function quizzes(): array {
        /** @var \mod_quiz_generator $quizgenerator */
        $quizgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quiz');
        $decoy = $quizgenerator->create_instance(['course' => $this->course->id, 'name' => 'Decoy']);
        $target = $quizgenerator->create_instance(['course' => $this->course->id, 'name' => 'Target <b>quiz</b>']);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
        /** @var \core_question_generator $questiongen */
        $questiongen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongen->create_question_category([
            'contextid' => \context_module::instance($qbank->cmid)->id,
            'name' => 'Bank category',
        ]);
        $questions = [];
        foreach (['First', 'Second', 'Third'] as $name) {
            $questions[$name] = $questiongen->create_question(
                'truefalse',
                null,
                ['category' => $category->id, 'name' => $name]
            );
        }
        quiz_add_quiz_question($questions['First']->id, $decoy);
        quiz_add_quiz_question($questions['First']->id, $target);
        quiz_add_quiz_question($questions['Second']->id, $target);
        quiz_add_quiz_question($questions['Third']->id, $target);
        return [$target, $questions, $category];
    }

    public function test_the_cleanup_plan_lists_every_slot_that_is_not_kept_with_exact_typed_fields(): void {
        global $CFG, $DB;
        $this->setup_course();
        [$quiz, $questions, $category] = $this->quizzes();
        $keep = (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questions['Second']->id]);

        $plan = get_quiz_cleanup_plan::execute((int) $quiz->cmid, [$keep]);

        $this->assert_declared_types($plan, get_quiz_cleanup_plan::execute_returns());
        $this->assertSame('Target <b>quiz</b>', $plan['quizname']);
        $this->assertSame($CFG->wwwroot . '/mod/quiz/edit.php?cmid=' . $quiz->cmid, $plan['editurl']);
        $this->assertSame(['First', 'Third'], array_column($plan['removals'], 'questionname'));
        $this->assertSame([1, 3], array_column($plan['removals'], 'slot'));
        $first = $plan['removals'][0];
        $this->assertSame(
            ['slot', 'questionbankentryid', 'questionid', 'version', 'questionname', 'categoryid', 'categoryname', 'reason'],
            array_keys($first)
        );
        $this->assertSame((int) $questions['First']->id, $first['questionid']);
        $this->assertSame(1, $first['version']);
        $this->assertSame((int) $category->id, $first['categoryid']);
        $this->assertSame('Bank category', $first['categoryname']);
        $this->assertStringStartsWith('Not part of the new quiz version.', $first['reason']);
    }

    public function test_the_cleanup_plan_checks_context_and_both_rights_in_order(): void {
        $this->setup_course();
        [$quiz] = $this->quizzes();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => get_quiz_cleanup_plan::execute((int) $quiz->cmid, []));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        foreach (['local/coursepilot:use', 'moodle/course:manageactivities'] as $capability) {
            $this->prohibit($capability);
            $e = $this->caught(fn() => get_quiz_cleanup_plan::execute((int) $quiz->cmid, []));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            $this->allow($capability);
        }
    }

    public function test_the_default_export_checks_context_and_rights_before_the_activity_type_is_judged(): void {
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => export_default_activity::execute((int) $this->course->id, 'page'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        // A catalogued type is refused by the kind gate; a missing right must win over that refusal.
        foreach (['local/coursepilot:use', 'moodle/course:manageactivities', 'moodle/backup:backupactivity'] as $capability) {
            $this->prohibit($capability);
            $e = $this->caught(fn() => export_default_activity::execute((int) $this->course->id, 'page'));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            $this->allow($capability);
        }
        $gate = $this->caught(fn() => export_default_activity::execute((int) $this->course->id, 'page'));
        $this->assertInstanceOf(\moodle_exception::class, $gate);
        $this->assertSame('defaultactivitycatalogued', $gate->errorcode);
    }

    public function test_the_default_export_returns_the_course_type_and_xml_with_exact_types(): void {
        $this->setup_course();

        $result = export_default_activity::execute((int) $this->course->id, 'book');

        $this->assert_declared_types($result, export_default_activity::execute_returns());
        $this->assertSame(['courseid', 'modname', 'xml'], array_keys($result));
        $this->assertSame((int) $this->course->id, $result['courseid']);
        $this->assertSame('book', $result['modname']);
        $this->assertStringContainsString('modulename="book"', $result['xml']);
        $this->assertSame(
            0,
            \local_coursepilot\xml_activity_creator::compare($result['xml'], $result['xml'])['mismatches'] === [] ? 0 : 1
        );
    }

    /**
     * Provides course-level rights that must be checked before the dry run is looked at.
     *
     * @return array<string, array{string}>
     */
    public static function course_rights(): array {
        return [
            'use' => ['local/coursepilot:use'],
            'manage activities' => ['moodle/course:manageactivities'],
            'backup' => ['moodle/backup:backupactivity'],
            'restore' => ['moodle/restore:restoreactivity'],
            'add instance' => ['mod/book:addinstance'],
        ];
    }

    /**
     * Each course-level right is checked before the request is judged.
     *
     * @param string $capability Right to prohibit.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('course_rights')]
    public function test_every_course_level_right_is_checked_before_the_dry_run_rules(string $capability): void {
        $this->setup_course();
        $this->prohibit($capability);

        // Without the missing right this request would end with "dry_run needs replaces_cmid".
        $e = $this->caught(fn() => create_activity_from_xml::execute(
            (int) $this->course->id,
            'book',
            0,
            '<activity/>',
            false,
            0,
            true
        ));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    /**
     * Provides rights that only apply to the activity that is replaced.
     *
     * @return array<string, array{string}>
     */
    public static function replaced_activity_rights(): array {
        return [
            'manage activities' => ['moodle/course:manageactivities'],
            'visibility' => ['moodle/course:activityvisibility'],
        ];
    }

    /**
     * The replaced activity needs its own rights.
     *
     * @param string $capability Right to prohibit.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('replaced_activity_rights')]
    public function test_the_replaced_activity_needs_its_own_rights(string $capability): void {
        $this->setup_course();
        $old = $this->getDataGenerator()->create_module('book', ['course' => $this->course->id]);
        $this->prohibit($capability, \context_module::instance($old->cmid)->id);

        $e = $this->caught(fn() => create_activity_from_xml::execute(
            (int) $this->course->id,
            'book',
            0,
            '<foo/>',
            false,
            (int) $old->cmid,
            true
        ));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_the_section_must_not_be_negative_but_zero_is_fine(): void {
        $this->setup_course();

        $negative = $this->caught(fn() => create_activity_from_xml::execute((int) $this->course->id, 'book', -1, '<activity/>'));
        $zero = $this->caught(fn() => create_activity_from_xml::execute(
            (int) $this->course->id,
            'book',
            0,
            '<activity/>',
            false,
            0,
            true
        ));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $negative);
        $this->assertStringContainsString('section must not be negative.', $negative->debuginfo);
        $this->assertInstanceOf(\invalid_parameter_exception::class, $zero);
        $this->assertStringContainsString('dry_run needs replaces_cmid.', $zero->debuginfo);
    }

    public function test_the_dry_run_report_names_references_and_hidden_predecessors_in_one_message(): void {
        global $DB;
        $this->setup_course();
        $xml = export_default_activity::execute((int) $this->course->id, 'book')['xml'];
        $old = $this->getDataGenerator()->create_module('book', ['course' => $this->course->id]);
        $next = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $availability = json_encode([
            'op' => '&',
            'c' => [['type' => 'completion', 'cm' => (int) $old->cmid, 'e' => 1]],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $next->cmid]);
        rebuild_course_cache($this->course->id, true);

        $result = create_activity_from_xml::execute((int) $this->course->id, 'book', 0, $xml, false, (int) $old->cmid, true);

        $this->assert_declared_types($result, create_activity_from_xml::execute_returns());
        $places = cm_references::KIND_ACTIVITY_AVAILABILITY . ' (cmid ' . $next->cmid . ')';
        $this->assertSame(
            get_string('createfromxmlreferences', 'local_coursepilot', $places) . ' '
                . get_string('createfromxmlhiddenpredecessors', 'local_coursepilot', 1),
            $result['message']
        );
        $this->assertSame(0, $result['cmid']);
        $this->assertSame([], $result['presets']);
        $this->assertSame(0, $result['successor_cmid']);
        $this->assertSame(1, $result['hidden_predecessors']);
    }
}
