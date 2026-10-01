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
 * Abschnittsliste serverseitig (#342): eigenstaendige Portierung von
 * local_coursepilot\external\get_sections, Vertrag (Feldnamen) identisch
 * zum lokalen Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_sections::class)]
final class get_sections_test extends \advanced_testcase {

    /**
     * Regelfall: id, Nummer, Name, Sichtbarkeit je Abschnitt.
     */
    public function test_returns_id_number_name_and_visibility_per_section(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        global $DB;
        $DB->set_field('course_sections', 'name', 'LS 1', ['course' => $course->id, 'section' => 1]);

        $result = get_sections::execute($course->id);
        $result = external_api::clean_returnvalue(get_sections::execute_returns(), $result);

        $section1 = self::find_section($result, 1);
        $this->assertNotNull($section1);
        $this->assertSame('LS 1', $section1['name']);
        $this->assertArrayHasKey('id', $section1);
        $this->assertArrayHasKey('visible', $section1);
    }

    /**
     * Fall ohne Berechtigung: local/coursepilot:use fehlt trotz Einschreibung.
     */
    public function test_rejects_user_without_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:use',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);

        get_sections::execute($course->id);
    }

    /**
     * Fall ohne Einschreibung: eine nicht eingeschriebene Person bekommt
     * keine Daten.
     */
    public function test_rejects_user_without_enrolment(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);

        get_sections::execute($course->id);
    }

    /**
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * @param array $result
     * @param int $sectionnum
     * @return array|null
     */
    private static function find_section(array $result, int $sectionnum): ?array {
        foreach ($result as $section) {
            if ((int) $section['sectionnum'] === $sectionnum) {
                return $section;
            }
        }
        return null;
    }
}
