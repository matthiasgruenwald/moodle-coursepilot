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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Schreibkern 13 (Spec 0015 Phase 3, Ticket #391).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(move_module::class)]
final class move_module_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs (3 Abschnitte), Lehrkraft.
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * @param int $cmid
     * @param int $sectionnum
     * @param int|null $position
     * @return array
     */
    private function move(int $cmid, int $sectionnum, ?int $position = null): array {
        return external_api::clean_returnvalue(
            move_module::execute_returns(),
            move_module::execute($cmid, $sectionnum, $position)
        );
    }

    public function test_moves_to_end_of_other_section_without_position(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 0]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        $result = $this->move($page->cmid, 1);

        $this->assertSame(1, $result['sectionnum']);
        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($page->cmid);
        $this->assertSame(1, (int) $cm->sectionnum);
        $cmids = array_map('intval', $modinfo->sections[1]);
        $this->assertSame($page->cmid, end($cmids), 'Ohne Positionsangabe landet die Aktivitaet am Ende des Zielabschnitts.');
    }

    public function test_moves_to_specific_position_within_section(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $first = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);
        $moving = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 0]);

        $this->move($moving->cmid, 1, 1);

        $modinfo = get_fast_modinfo($course);
        $cmids = array_map('intval', $modinfo->sections[1]);
        $this->assertSame([$first->cmid, $moving->cmid, $second->cmid], $cmids);
    }

    public function test_requires_native_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 0]);
        $roleid = $this->get_role_id('editingteacher');
        assign_capability('moodle/course:manageactivities', CAP_PREVENT, $roleid, \context_course::instance($course->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\required_capability_exception::class);
        $this->move($page->cmid, 1);
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
