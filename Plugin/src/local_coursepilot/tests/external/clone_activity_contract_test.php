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
 * Exact response, messages, visibility, cleanup output and capability checks of clone_activity.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(clone_activity::class)]
final class clone_activity_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Source course. */
    private \stdClass $source;

    /** @var \stdClass Target course. */
    private \stdClass $target;

    /** @var \stdClass Page that gets cloned. */
    private \stdClass $page;

    /**
     * Source and target course with the same editing teacher; the source holds one page.
     */
    private function setup_courses(): void {
        $this->resetAfterTest();
        $this->source = $this->getDataGenerator()->create_course();
        $this->target = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        foreach ([$this->source, $this->target] as $course) {
            $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        }
        $this->setUser($teacher);
        $this->page = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'name' => 'Original']);
    }

    public function test_same_course_clone_response_is_exact_and_visible_by_default(): void {
        global $DB;
        $this->setup_courses();
        $DB->set_field('course_modules', 'visible', 0, ['id' => $this->page->cmid]);
        rebuild_course_cache($this->source->id, true);

        $result = clone_activity::execute((int) $this->page->cmid, '  Clone  ');

        $this->assert_declared_types($result, clone_activity::execute_returns());
        $this->assertGreaterThan($this->page->cmid, $result['cmid']);
        $this->assertSame([
            'cmid' => $result['cmid'],
            'courseid' => (int) $this->source->id,
            'message' => 'Activity cloned as "Clone" in the same course.',
        ], $result);
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $result['cmid']]));
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $this->page->cmid]));
        $this->assertSame('Clone', $DB->get_field('page', 'name', [
            'id' => $DB->get_field('course_modules', 'instance', ['id' => $result['cmid']]),
        ]));
    }

    public function test_the_visible_flag_is_applied_explicitly(): void {
        global $DB;
        $this->setup_courses();

        $hidden = clone_activity::execute((int) $this->page->cmid, 'Hidden clone', 0, false);

        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $hidden['cmid']]));
    }

    public function test_cross_course_clone_lands_in_the_target_course_and_its_cache_is_fresh(): void {
        global $DB;
        $this->setup_courses();

        $result = clone_activity::execute((int) $this->page->cmid, 'Over there', (int) $this->target->id);

        $this->assertSame([
            'cmid' => $result['cmid'],
            'courseid' => (int) $this->target->id,
            'message' => 'Activity cloned as "Over there" into the target course.',
        ], $result);
        $this->assertEquals($this->target->id, $DB->get_field('course_modules', 'course', ['id' => $result['cmid']]));
        $this->assertSame('Over there', get_fast_modinfo($this->target->id)->get_cm($result['cmid'])->name);
    }

    /**
     * Gives the source page a restriction with a completion condition on another source page.
     *
     * @param mixed[] $availability Native availability tree.
     */
    private function restrict(array $availability): void {
        global $DB;
        $DB->set_field('course_modules', 'availability', json_encode($availability), ['id' => $this->page->cmid]);
        rebuild_course_cache($this->source->id, true);
    }

    public function test_one_broken_prerequisite_is_named_exactly_and_the_cache_forgets_it(): void {
        global $DB;
        $this->setup_courses();
        $prerequisite = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id, 'name' => 'Prerequisite']);
        $this->restrict(
            ['op' => '&', 'c' => [['type' => 'completion', 'cm' => (int) $prerequisite->cmid, 'e' => 1]], 'showc' => [true]]
        );
        $unrelated = $DB->get_field('course_modules', 'availability', ['id' => $this->page->cmid]);

        $result = clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id);

        $this->assertSame(
            'Activity cloned as "Clone" into the target course. 1 kaputte Voraussetzung entfernt: '
                . 'Completion condition on "Prerequisite" (abgeschlossen) - the referenced activity was not copied '
                . 'along when cloning across courses.',
            $result['message']
        );
        $this->assertEquals('', (string) $DB->get_field('course_modules', 'availability', ['id' => $result['cmid']]));
        $this->assertSame($unrelated, $DB->get_field('course_modules', 'availability', ['id' => $this->page->cmid]));
        $this->assertEmpty(get_fast_modinfo($this->target->id)->get_cm($result['cmid'])->availability);
    }

    public function test_a_broken_prerequisite_without_a_source_name_is_described_generically(): void {
        global $DB;
        $this->setup_courses();
        $prerequisite = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id]);
        $this->restrict(
            ['op' => '&', 'c' => [['type' => 'completion', 'cm' => (int) $prerequisite->cmid, 'e' => 2]], 'showc' => [true]]
        );
        $DB->delete_records('course_modules', ['id' => $prerequisite->cmid]);

        $result = clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id);

        $this->assertStringEndsWith(
            '1 kaputte Voraussetzung entfernt: Completion condition on an activity that was not copied along (bestanden).',
            $result['message']
        );
    }

    public function test_remaining_conditions_keep_their_own_visibility_flags_and_unicode_unescaped(): void {
        global $DB;
        $this->setup_courses();
        $prerequisite = $this->getDataGenerator()->create_module('page', ['course' => $this->source->id]);
        $this->restrict([
            'op' => '&',
            'c' => [
                ['type' => 'completion', 'cm' => (int) $prerequisite->cmid, 'e' => 1],
                ['type' => 'profile', 'cf' => 'größe/x', 'op' => 'isequalto', 'v' => 'ä/b'],
                ['type' => 'date', 'd' => '>=', 't' => 1767225600],
            ],
            'showc' => [false, true, false],
        ]);

        $result = clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id);

        $stored = $DB->get_field('course_modules', 'availability', ['id' => $result['cmid']]);
        $this->assertEquals([
            'op' => '&',
            'c' => [
                ['type' => 'profile', 'op' => 'isequalto', 'cf' => 'größe/x', 'v' => 'ä/b'],
                ['type' => 'date', 'd' => '>=', 't' => 1767225600],
            ],
            'showc' => [true, false],
        ], json_decode($stored, true));
        $this->assertStringContainsString('"cf":"größe/x"', $stored);
        $this->assertStringContainsString('"v":"ä/b"', $stored);
    }

    public function test_an_empty_title_is_refused_before_anything_is_checked(): void {
        $this->setup_courses();

        $e = $this->caught(fn() => clone_activity::execute((int) $this->page->cmid, "  \t ", 987654));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('title must not be empty.', $e->debuginfo);
    }

    /**
     * Provides the course-level capabilities of a cross-course clone.
     *
     * @return array<string, array{string, bool}>
     */
    public static function course_level_capabilities(): array {
        return [
            'use in source' => ['local/coursepilot:use', false], 'manage in source' => ['moodle/course:manageactivities', false],
            'use in target' => ['local/coursepilot:use', true], 'manage in target' => ['moodle/course:manageactivities', true],
            'backup in source' => ['moodle/backup:backuptargetimport', false],
            'restore in target' => ['moodle/restore:restoretargetimport', true],
        ];
    }

    #[DataProvider('course_level_capabilities')]
    public function test_each_capability_is_required_in_its_own_course_for_a_cross_course_clone(
        string $capability,
        bool $intarget
    ): void {
        global $DB;
        $this->setup_courses();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $context = \context_course::instance($intarget ? $this->target->id : $this->source->id);
        assign_capability($capability, CAP_PROHIBIT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $before = $DB->count_records('course_modules');

        $e = $this->caught(fn() => clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
        $this->assertSame($before, $DB->count_records('course_modules'));
    }

    public function test_the_context_restriction_covers_source_and_target_course(): void {
        $this->setup_courses();
        $third = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($third->id));
        try {
            $source = $this->caught(fn() => clone_activity::execute((int) $this->page->cmid, 'Clone'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $source);

        external_api::set_context_restriction(\context_course::instance($this->source->id));
        try {
            $target = $this->caught(fn() => clone_activity::execute((int) $this->page->cmid, 'Clone', (int) $this->target->id));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $target);
    }

    public function test_the_clone_gets_one_history_version_from_the_source(): void {
        global $DB;
        $this->setup_courses();

        $result = clone_activity::execute((int) $this->page->cmid, 'Clone');

        $versions = $DB->get_records('local_coursepilot_cm_version', ['cmid' => $result['cmid']]);
        $this->assertCount(1, $versions);
        $this->assertEquals($this->page->cmid, reset($versions)->sourcecmid);
        $this->assertSame('cloned', reset($versions)->source);
    }
}
