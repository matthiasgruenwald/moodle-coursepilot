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

/**
 * Je externer Funktion ein Test (Abnahmekriterium 3 aus #309) plus der
 * Capability-Test (Abnahmekriterium 4).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(list_courses::class)]
final class list_courses_test extends \advanced_testcase {

    /**
     * Eine Lehrkraft sieht ihre Kurse.
     */
    public function test_teacher_sees_own_courses(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['fullname' => 'Biologie 7a', 'shortname' => 'bio7a']);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = list_courses::execute();
        $result = external_api::clean_returnvalue(list_courses::execute_returns(), $result);

        $this->assertCount(1, $result['courses']);
        $this->assertSame((int) $course->id, $result['courses'][0]['id']);
        $this->assertSame('Biologie 7a', $result['courses'][0]['fullname']);
        $this->assertSame('bio7a', $result['courses'][0]['shortname']);
        $this->assertTrue($result['courses'][0]['visible']);
    }

    /**
     * Kurse ohne 'local/coursepilot:use' tauchen nicht auf, auch wenn die
     * Lehrkraft dort eingeschrieben ist (#295, Punkt 3).
     */
    public function test_courses_without_capability_are_omitted(): void {
        $this->resetAfterTest();

        $withcap = $this->getDataGenerator()->create_course();
        $withoutcap = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $withcap->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $withoutcap->id, 'editingteacher');

        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:use',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($withoutcap->id)->id,
            true
        );

        $this->setUser($teacher);

        $result = list_courses::execute();

        $this->assertCount(1, $result['courses']);
        $this->assertSame((int) $withcap->id, $result['courses'][0]['id']);
    }

    /**
     * Ohne 'local/coursepilot:use' gibt es CAPABILITY_MISSING und keine Daten
     * (Abnahmekriterium 4 aus #309, Fehlerform aus #295, Punkt 4).
     */
    public function test_user_without_capability_gets_capability_missing(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('CAPABILITY_MISSING:local/coursepilot:use');

        list_courses::execute();
    }

    /**
     * Auch eine Lehrkraft ohne jede Einschreibung bekommt keine Kursdaten.
     */
    public function test_user_without_enrolment_gets_no_data(): void {
        $this->resetAfterTest();

        $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);

        list_courses::execute();
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
