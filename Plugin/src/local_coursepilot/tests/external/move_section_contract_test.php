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
 * Exact order, messages and error parameters of move_section.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(move_section::class)]
final class move_section_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course with sections 0 to 4. */
    private \stdClass $course;

    /**
     * Course with an editing teacher and the sections named S0 to S4.
     */
    private function setup_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 4]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        for ($i = 0; $i <= 4; $i++) {
            $DB->set_field('course_sections', 'name', "S{$i}", ['course' => $this->course->id, 'section' => $i]);
        }
        rebuild_course_cache($this->course->id, true);
    }

    /**
     * Section names in order.
     *
     * @return string[]
     */
    private function names(): array {
        rebuild_course_cache($this->course->id, true);
        return array_values(array_map(static fn($s) => $s->name, get_fast_modinfo($this->course)->get_section_info_all()));
    }

    public function test_moving_forward_and_backward_gives_the_expected_order_and_messages(): void {
        global $DB;
        $this->setup_course();
        $id = (int) $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]);

        $forward = move_section::execute((int) $this->course->id, 1, 3);
        $this->assert_declared_types($forward, move_section::execute_returns());
        $this->assertSame([
            'id' => $id,
            'sectionnum' => 3,
            'message' => 'Section "S1" moved from position 1 to position 3.',
        ], $forward);
        $this->assertSame(['S0', 'S2', 'S3', 'S1', 'S4'], $this->names());

        $backward = move_section::execute((int) $this->course->id, 3, 1);
        $this->assertSame('Section "S1" moved from position 3 to position 1.', $backward['message']);
        $this->assertSame(['S0', 'S1', 'S2', 'S3', 'S4'], $this->names());
    }

    public function test_the_last_position_is_a_valid_target_and_the_first_section_is_not(): void {
        $this->setup_course();

        $last = move_section::execute((int) $this->course->id, 1, 4);
        $this->assertSame(['S0', 'S2', 'S3', 'S4', 'S1'], $this->names());
        $this->assertSame(4, $last['sectionnum']);

        $zero = $this->caught(fn() => move_section::execute((int) $this->course->id, 2, 0));
        $this->assertInstanceOf(\moodle_exception::class, $zero);
        $this->assertSame('sectiontargetoutofrange', $zero->errorcode);
        $this->assertSame(['target' => 0, 'max' => 4], $zero->a);
        $beyond = $this->caught(fn() => move_section::execute((int) $this->course->id, 2, 5));
        $this->assertInstanceOf(\moodle_exception::class, $beyond);
        $this->assertSame(['target' => 5, 'max' => 4], $beyond->a);
    }

    public function test_moving_to_its_own_position_changes_nothing_and_says_so(): void {
        $this->setup_course();

        $result = move_section::execute((int) $this->course->id, 2, 2);

        $this->assertSame('Section "S2" is already at position 2.', $result['message']);
        $this->assertSame(2, $result['sectionnum']);
        $this->assertSame(['S0', 'S1', 'S2', 'S3', 'S4'], $this->names());
    }

    public function test_the_introductory_and_unknown_sections_cannot_be_moved(): void {
        $this->setup_course();

        foreach ([0, 9, -1] as $from) {
            $e = $this->caught(fn() => move_section::execute((int) $this->course->id, $from, 1));
            $this->assertInstanceOf(\moodle_exception::class, $e, (string) $from);
            $this->assertSame('sectionnotmovable', $e->errorcode);
            $this->assertSame(['sectionnum' => $from], $e->a);
        }
    }

    public function test_the_context_restriction_and_each_capability_come_before_the_section_checks(): void {
        global $DB;
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => move_section::execute((int) $this->course->id, 0, 1));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'moodle/course:movesections'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => move_section::execute((int) $this->course->id, 0, 1));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_INHERIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }
}
