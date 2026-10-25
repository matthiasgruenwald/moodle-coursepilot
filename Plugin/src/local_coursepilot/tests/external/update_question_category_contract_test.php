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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exact response, messages, ordering of checks and side effects of update_question_category.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_question_category::class)]
final class update_question_category_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var int[] Category ids by name. */
    private array $categories = [];

    /** @var int Context id of the first bank. */
    private int $firstcontext;

    /** @var int Context id of the second bank. */
    private int $secondcontext;

    /**
     * Teacher with two banks: "Parent" with the children "Child" and "Other" in the first, "Remote" in the second.
     */
    private function setup_banks(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $first = ensure_question_bank::execute($course->id, 'First bank');
        $second = ensure_question_bank::execute($course->id, 'Second bank');
        $this->categories['top1'] = (int) $first['topcategoryid'];
        $this->categories['top2'] = (int) $second['topcategoryid'];
        $this->categories['Parent'] = (int) ensure_question_category::execute('Parent', $this->categories['top1'])['id'];
        $this->categories['Child'] = (int) ensure_question_category::execute('Child', $this->categories['Parent'])['id'];
        $this->categories['Other'] = (int) ensure_question_category::execute('Other', $this->categories['top1'])['id'];
        $this->categories['Remote'] = (int) ensure_question_category::execute('Remote', $this->categories['top2'])['id'];
        $this->firstcontext = (int) $DB->get_field('question_categories', 'contextid', ['id' => $this->categories['Parent']]);
        $this->secondcontext = (int) $DB->get_field('question_categories', 'contextid', ['id' => $this->categories['Remote']]);
    }

    public function test_rename_only_response_is_exact(): void {
        $this->setup_banks();

        $result = update_question_category::execute($this->categories['Other'], 'Renamed');

        $this->assert_declared_types($result, update_question_category::execute_returns());
        $this->assertSame([
            'id' => $this->categories['Other'],
            'name' => 'Renamed',
            'parent' => $this->categories['top1'],
            'contextid' => $this->firstcontext,
            'moved' => false,
            'renamed' => true,
            'message' => 'Category renamed to "Renamed".',
        ], $result);
    }

    public function test_move_only_within_one_bank_keeps_name_and_context(): void {
        $this->setup_banks();

        $result = update_question_category::execute($this->categories['Other'], '', $this->categories['Parent']);

        $this->assert_declared_types($result, update_question_category::execute_returns());
        $this->assertSame([
            'id' => $this->categories['Other'],
            'name' => 'Other',
            'parent' => $this->categories['Parent'],
            'contextid' => $this->firstcontext,
            'moved' => true,
            'renamed' => false,
            'message' => 'Category "Other" moved.',
        ], $result);
    }

    public function test_without_a_parent_the_current_parent_stays(): void {
        $this->setup_banks();

        $result = update_question_category::execute($this->categories['Child'], 'Renamed child');

        $this->assertSame($this->categories['Parent'], $result['parent']);
        $this->assertFalse($result['moved']);
    }

    public function test_rename_and_move_to_another_bank_moves_files_and_references(): void {
        global $DB;
        $this->setup_banks();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $question = $generator->create_question('truefalse', null, ['category' => $this->categories['Other']]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->firstcontext, 'component' => 'question', 'filearea' => 'questiontext',
            'itemid' => $question->id, 'filepath' => '/', 'filename' => 'img.png',
        ], 'bytes');
        $referenceid = $DB->insert_record('question_set_references', (object) [
            'usingcontextid' => $this->firstcontext, 'component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => 1,
            'questionscontextid' => $this->firstcontext,
            'filtercondition' => json_encode(['filter' => ['category' => ['values' => [$this->categories['Other']]]]]),
        ]);

        $result = update_question_category::execute($this->categories['Other'], 'Moved over', $this->categories['Remote']);

        $this->assertSame([
            'id' => $this->categories['Other'],
            'name' => 'Moved over',
            'parent' => $this->categories['Remote'],
            'contextid' => $this->secondcontext,
            'moved' => true,
            'renamed' => true,
            'message' => 'Category renamed to "Moved over" and moved.',
        ], $result);
        $this->assertEquals(
            $this->secondcontext,
            $DB->get_field('question_categories', 'contextid', ['id' => $this->categories['Other']])
        );
        $this->assertEquals($this->secondcontext, $DB->get_field('files', 'contextid', [
            'component' => 'question', 'filearea' => 'questiontext', 'itemid' => $question->id, 'filename' => 'img.png',
        ]));
        $reference = $DB->get_record('question_set_references', ['id' => $referenceid], '*', MUST_EXIST);
        $this->assertEquals($this->secondcontext, $reference->questionscontextid);
        $this->assertSame(
            $this->categories['Other'] . ',' . $this->secondcontext,
            json_decode($reference->filtercondition, true)['cat']
        );
        $this->assertFalse($DB->is_transaction_started());
    }

    public function test_the_subtree_follows_a_category_into_another_bank(): void {
        global $DB;
        $this->setup_banks();

        update_question_category::execute($this->categories['Parent'], '', $this->categories['Remote']);

        $this->assertEquals(
            $this->secondcontext,
            $DB->get_field('question_categories', 'contextid', ['id' => $this->categories['Child']])
        );
        $this->assertEquals(
            $this->categories['Parent'],
            $DB->get_field('question_categories', 'parent', ['id' => $this->categories['Child']])
        );
    }

    public function test_unchanged_name_and_parent_report_no_change(): void {
        $this->setup_banks();

        $result = update_question_category::execute($this->categories['Child'], 'Child', $this->categories['Parent']);

        $this->assertSame('No change: name and parent category are unchanged.', $result['message']);
        $this->assertFalse($result['moved']);
        $this->assertFalse($result['renamed']);
    }

    public function test_a_name_of_only_blanks_keeps_the_current_name(): void {
        $this->setup_banks();

        $result = update_question_category::execute($this->categories['Other'], '   ');

        $this->assertSame('Other', $result['name']);
        $this->assertFalse($result['renamed']);
    }

    public function test_a_name_collision_is_only_checked_below_the_same_parent_and_bank(): void {
        $this->setup_banks();
        ensure_question_category::execute('Twin', $this->categories['Parent']);

        $e = $this->caught(
            fn() => update_question_category::execute($this->categories['Other'], 'Twin', $this->categories['Parent'])
        );
        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('The target category already has a category with this name.', $e->debuginfo);

        // The same name below another parent, and in another bank, is no collision.
        $this->assertSame('Twin', update_question_category::execute($this->categories['Other'], 'Twin')['name']);
        $remote = update_question_category::execute($this->categories['Other'], 'Twin', $this->categories['Remote']);
        $this->assertSame($this->categories['Remote'], $remote['parent']);
    }

    public function test_a_name_in_another_bank_does_not_collide(): void {
        $this->setup_banks();
        ensure_question_category::execute('Alike', $this->categories['top2']);

        $result = update_question_category::execute($this->categories['Other'], 'Alike');

        $this->assertSame('Alike', $result['name']);
    }

    public function test_a_category_cannot_be_its_own_parent_or_below_itself(): void {
        $this->setup_banks();

        $own = $this->caught(fn() => update_question_category::execute($this->categories['Other'], '', $this->categories['Other']));
        $this->assertInstanceOf(\invalid_parameter_exception::class, $own);
        $this->assertSame('A category cannot be its own parent category.', $own->debuginfo);

        $below = $this->caught(
            fn() => update_question_category::execute($this->categories['Parent'], '', $this->categories['Child'])
        );
        $this->assertInstanceOf(\invalid_parameter_exception::class, $below);
        $this->assertSame('A category cannot be moved into one of its own subcategories.', $below->debuginfo);
    }

    public function test_the_top_category_cannot_be_changed(): void {
        $this->setup_banks();

        $e = $this->caught(fn() => update_question_category::execute($this->categories['top1'], 'x'));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('The top category of a question set cannot be renamed or moved.', $e->debuginfo);
    }

    public function test_the_context_restriction_covers_the_source_and_a_different_target_bank(): void {
        $this->setup_banks();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $source = $this->caught(fn() => update_question_category::execute($this->categories['Other'], 'x'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $source);

        external_api::set_context_restriction(\context::instance_by_id($this->firstcontext));
        try {
            $target = $this->caught(
                fn() => update_question_category::execute($this->categories['Other'], '', $this->categories['Remote'])
            );
            $same = $this->caught(
                fn() => update_question_category::execute($this->categories['Other'], '', $this->categories['Parent'])
            );
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $target);
        $this->assertNull($same);
    }

    /**
     * Provides the capabilities the tool requires.
     *
     * @return array<string, array{string, bool}>
     */
    public static function required_capabilities(): array {
        return [
            'plugin use in source' => ['local/coursepilot:use', false],
            'manage categories in source' => ['moodle/question:managecategory', false],
            'plugin use in target' => ['local/coursepilot:use', true],
            'manage categories in target' => ['moodle/question:managecategory', true],
        ];
    }

    #[DataProvider('required_capabilities')]
    public function test_each_capability_is_checked_in_source_and_target_bank_before_anything_is_written(
        string $capability,
        bool $intarget
    ): void {
        global $DB;
        $this->setup_banks();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $target = $intarget ? $this->categories['Remote'] : $this->categories['Parent'];
        // A move into a taken name would fail later for another reason, so only the capability can explain this.
        ensure_question_category::execute('Taken', $target);
        assign_capability($capability, CAP_PROHIBIT, $roleid, $intarget ? $this->secondcontext : $this->firstcontext, true);
        accesslib_clear_all_caches_for_unit_testing();
        $e = $this->caught(fn() => update_question_category::execute($this->categories['Other'], 'Taken', $target));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
        $this->assertSame('Other', $DB->get_field('question_categories', 'name', ['id' => $this->categories['Other']]));
    }
}
