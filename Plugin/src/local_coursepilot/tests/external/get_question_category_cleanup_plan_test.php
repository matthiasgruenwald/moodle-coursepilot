<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/

namespace local_coursepilot\external;

use core_external\external_api;

/**
 * Non-destructive question category cleanup plan (#443).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_question_category_cleanup_plan::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\question_bank_context::class)]
final class get_question_category_cleanup_plan_test extends \advanced_testcase {
    /** An empty leaf is listed with a manual instruction; nothing is deleted. */
    public function test_lists_empty_leaf_without_deleting_it(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$course, $bank, $context] = $this->create_populated_bank();
        $category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category([
            'contextid' => $context->id,
            'name' => 'Empty leaf',
        ]);

        $result = get_question_category_cleanup_plan::execute($course->id, $bank->cmid);
        $result = external_api::clean_returnvalue(get_question_category_cleanup_plan::execute_returns(), $result);

        $this->assertSame('Cleanup bank', $result['questionbankname']);
        $this->assertSame([[
            'id' => (int) $category->id,
            'name' => 'Empty leaf',
            'parent' => (int) $category->parent,
            'editurl' => $CFG->wwwroot . '/question/edit.php?cmid=' . $bank->cmid
                . '&cat=' . $category->id . ',' . $context->id,
            'reason' => 'Empty leaf category with no questions or subcategories. Coursepilot does not delete it; review it manually and, if appropriate, delete it in Moodle using the link.',
        ]], $result['removals']);
        $this->assertTrue($DB->record_exists('question_categories', ['id' => $category->id]));
    }

    /** The tool is registered as read-only with the same schema and service allowlist. */
    public function test_registered_as_read_only_tool(): void {
        $this->resetAfterTest();
        $tool = 'coursepilot_plan_question_category_cleanup';
        $function = 'local_coursepilot_get_question_category_cleanup_plan';
        $this->assertSame($function, \local_coursepilot\privacy_surface::function_for_tool($tool));
        $this->assertFalse(\local_coursepilot\tool_registry::is_write($tool));
        $this->assertSame('read', \local_coursepilot\tool_registry::service_functions()[$function]['type']);
        $this->assertContains($function, \local_coursepilot\privacy_surface::registered_functions());
        $this->assertSame(['courseid', 'questionbankid'],
            \local_coursepilot\tool_registry::schemas()[$tool]['required']);
        $this->assertSame('Builds a non-destructive cleanup plan for empty leaf categories in a named question bank.',
            \local_coursepilot\tool_registry::descriptions()[$tool]);
    }

    /** A parent with an empty child needs two manual cleanup passes (#315). */
    public function test_parent_is_only_listed_after_its_empty_child_is_removed(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $bank, $context] = $this->create_populated_bank();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $parent = $generator->create_question_category(['contextid' => $context->id, 'name' => 'Parent']);
        $child = $generator->create_question_category([
            'contextid' => $context->id, 'parent' => $parent->id, 'name' => 'Empty child',
        ]);

        $first = get_question_category_cleanup_plan::execute($course->id, $bank->cmid);
        $this->assertSame([(int) $child->id], array_column($first['removals'], 'id'));
        $this->assertSame($first, get_question_category_cleanup_plan::execute($course->id, $bank->cmid));

        // Simulate the teacher's manual removal outside the tool, in the test database only.
        $DB->delete_records('question_categories', ['id' => $child->id]);
        $second = get_question_category_cleanup_plan::execute($course->id, $bank->cmid);
        $this->assertSame([(int) $parent->id], array_column($second['removals'], 'id'));
    }

    /** A bank with questions only has no suggestions; even a childless top is never listed. */
    public function test_clean_bank_and_childless_top_return_empty_lists(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $bank] = $this->create_populated_bank();
        $result = get_question_category_cleanup_plan::execute($course->id, $bank->cmid);
        $this->assertSame([], $result['removals']);

        // A separate bank containing only its top category proves exclusion independent of children.
        $toponly = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $topcontext = \context_module::instance($toponly->cmid);
        $top = question_get_top_category($topcontext->id);
        $DB->delete_records_select('question_categories', 'contextid = :contextid AND id <> :topid', [
            'contextid' => $topcontext->id, 'topid' => $top->id,
        ]);
        $this->assertSame([], get_question_category_cleanup_plan::execute($course->id, $toponly->cmid)['removals']);
    }

    /** Each capability is enforced in the specified context. */
    #[\PHPUnit\Framework\Attributes\DataProvider('denied_capability_provider')]
    public function test_rejects_missing_capability(string $capability, string $level): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $bank, $context] = $this->create_populated_bank();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $deniedcontext = $level === 'course' ? \context_course::instance($course->id) : $context;
        assign_capability($capability, CAP_PROHIBIT, $roleid, $deniedcontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->expectException(\required_capability_exception::class);
        $this->expectExceptionMessage(get_capability_string($capability));
        get_question_category_cleanup_plan::execute($course->id, $bank->cmid);
    }

    /** @return array */
    public static function denied_capability_provider(): array {
        return [
            'course use' => ['local/coursepilot:use', 'course'],
            'bank use' => ['local/coursepilot:use', 'module'],
            'bank manage categories' => ['moodle/question:managecategory', 'module'],
        ];
    }

    /** A question bank CMID from another course cannot be used. */
    public function test_rejects_bank_from_another_course(): void {
        $this->resetAfterTest();
        [$course] = $this->create_populated_bank();
        $foreigncourse = $this->getDataGenerator()->create_course();
        $foreignbank = $this->getDataGenerator()->create_module('qbank', ['course' => $foreigncourse->id]);
        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('Selected question bank was not found in this course.');
        get_question_category_cleanup_plan::execute($course->id, $foreignbank->cmid);
    }

    /** A CMID of another module type is rejected even in the same course. */
    public function test_rejects_non_bank_module(): void {
        $this->resetAfterTest();
        [$course] = $this->create_populated_bank();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('Selected question bank was not found in this course.');
        get_question_category_cleanup_plan::execute($course->id, $page->cmid);
    }

    /** @return array Course, bank and bank context; teacher is the current user. */
    private function create_populated_bank(): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id, 'name' => 'Cleanup bank']);
        $context = \context_module::instance($bank->cmid);
        $top = question_get_top_category($context->id);
        // Core creates an empty default category too; populate it so only intentional leaves qualify.
        $default = $DB->get_record('question_categories', ['contextid' => $context->id, 'parent' => $top->id], '*', MUST_EXIST);
        $this->getDataGenerator()->get_plugin_generator('core_question')->create_question('truefalse', null, [
            'category' => $default->id,
        ]);
        return [$course, $bank, $context];
    }
}
