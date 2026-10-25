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
 * Exact positions, messages and check order of move_module.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(move_module::class)]
final class move_module_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /** @var int[] Page cmids A, B, C in section 1. */
    private array $pages = [];

    /**
     * Course with an editing teacher and three named pages in section 1; section 2 is empty.
     */
    private function setup_course(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        foreach (['A', 'B', 'C'] as $name) {
            $this->pages[$name] = (int) $this->getDataGenerator()
                ->create_module('page', ['course' => $this->course->id, 'name' => $name], ['section' => 1])->cmid;
        }
        global $DB;
        $DB->set_field('course_sections', 'name', 'Algebra', ['course' => $this->course->id, 'section' => 2]);
        rebuild_course_cache($this->course->id, true);
    }

    /**
     * Names of the activities of a section in order.
     *
     * @param int $sectionnum
     * @return string[]
     */
    private function order(int $sectionnum): array {
        rebuild_course_cache($this->course->id, true);
        $modinfo = get_fast_modinfo($this->course);
        return array_map(static fn($id) => $modinfo->get_cm($id)->name, $modinfo->sections[$sectionnum] ?? []);
    }

    public function test_response_and_message_for_appending_to_another_section(): void {
        $this->setup_course();

        $result = move_module::execute($this->pages['A'], 2);

        $this->assert_declared_types($result, move_module::execute_returns());
        $this->assertSame([
            'cmid' => $this->pages['A'],
            'sectionnum' => 2,
            'message' => 'Activity "A" moved to section "Algebra".',
        ], $result);
        $this->assertSame(['A'], $this->order(2));
        $this->assertSame(['B', 'C'], $this->order(1));
    }

    public function test_positions_count_the_other_activities_of_the_target_section(): void {
        $this->setup_course();
        $a = move_module::execute($this->pages['A'], 2);
        $b = move_module::execute($this->pages['B'], 2);

        $first = move_module::execute($this->pages['C'], 2, 0);
        $this->assertSame('Activity "C" moved to section "Algebra" at position 0.', $first['message']);
        $this->assertSame(['C', 'A', 'B'], $this->order(2));

        $middle = move_module::execute($this->pages['C'], 2, 1);
        $this->assertSame('Activity "C" moved to section "Algebra" at position 1.', $middle['message']);
        $this->assertSame(['A', 'C', 'B'], $this->order(2));

        $beyond = move_module::execute($this->pages['C'], 2, 2);
        $this->assertSame('Activity "C" moved to section "Algebra".', $beyond['message']);
        $this->assertSame(['A', 'B', 'C'], $this->order(2));

        $toofar = move_module::execute($this->pages['A'], 2, 9);
        $this->assertSame('Activity "A" moved to section "Algebra".', $toofar['message']);
        $this->assertSame(['B', 'C', 'A'], $this->order(2));
    }

    public function test_moving_inside_the_own_section_ignores_the_moved_activity_when_counting(): void {
        $this->setup_course();

        $e = $this->caught(fn() => move_module::execute($this->pages['A'], 1, 1));
        $this->assertNull($e);
        $this->assertSame(['B', 'A', 'C'], $this->order(1));

        $e = $this->caught(fn() => move_module::execute($this->pages['B'], 1, 1));
        $this->assertNull($e);
        $this->assertSame(['A', 'B', 'C'], $this->order(1));
    }

    public function test_an_unknown_section_names_its_number(): void {
        $this->setup_course();

        $e = $this->caught(fn() => move_module::execute($this->pages['A'], 9));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('sectionnotfound', $e->errorcode);
        $this->assertSame(['sectionnum' => 9], $e->a);
    }

    public function test_the_context_restriction_and_each_capability_come_before_the_section_check(): void {
        global $DB;
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => move_module::execute($this->pages['A'], 9));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'moodle/course:manageactivities'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => move_module::execute($this->pages['A'], 9));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_INHERIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }
}
