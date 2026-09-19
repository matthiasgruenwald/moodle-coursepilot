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
#[CoversClass(move_section::class)]
final class move_section_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs (4 Abschnitte), Lehrkraft.
     */
    private function course_with_editing_teacher(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 4]);
        foreach ([1, 2, 3, 4] as $sectionnum) {
            $DB->set_field('course_sections', 'name', "LS {$sectionnum}", ['course' => $course->id, 'section' => $sectionnum]);
        }
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * @param \stdClass $course
     * @return array<int, string> sectionnum => name, sortiert
     */
    private function names_by_sectionnum(\stdClass $course): array {
        $sections = get_fast_modinfo($course)->get_section_info_all();
        $out = [];
        foreach ($sections as $sectionnum => $section) {
            $out[$sectionnum] = (string) $section->name;
        }
        ksort($out);
        return $out;
    }

    /**
     * @param int $courseid
     * @param int $von
     * @param int $nach
     * @return array
     */
    private function move(int $courseid, int $von, int $nach): array {
        return external_api::clean_returnvalue(
            move_section::execute_returns(),
            move_section::execute($courseid, $von, $nach)
        );
    }

    public function test_moves_section_forward(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->move($course->id, 1, 3);

        $this->assertSame(3, $result['sectionnum']);
        $names = $this->names_by_sectionnum($course);
        $this->assertSame(['LS 2', 'LS 3', 'LS 1', 'LS 4'], [$names[1], $names[2], $names[3], $names[4]]);
    }

    public function test_moves_section_backward(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->move($course->id, 3, 1);

        $this->assertSame(1, $result['sectionnum']);
        $names = $this->names_by_sectionnum($course);
        $this->assertSame(['LS 3', 'LS 1', 'LS 2', 'LS 4'], [$names[1], $names[2], $names[3], $names[4]]);
    }

    public function test_moving_general_section_throws(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $this->expectException(\moodle_exception::class);
        $this->move($course->id, 0, 2);
    }

    public function test_target_out_of_range_throws(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $this->expectException(\moodle_exception::class);
        $this->move($course->id, 1, 99);
    }

    public function test_requires_native_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $roleid = $this->get_role_id('editingteacher');
        assign_capability('moodle/course:movesections', CAP_PREVENT, $roleid, \context_course::instance($course->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\required_capability_exception::class);
        $this->move($course->id, 1, 2);
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
