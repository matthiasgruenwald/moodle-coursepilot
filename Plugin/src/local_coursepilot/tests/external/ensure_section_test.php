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
#[CoversClass(ensure_section::class)]
final class ensure_section_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass} Kurs, Lehrkraft (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string|null $name
     * @return array
     */
    private function ensure(int $courseid, int $sectionnum, ?string $name = null): array {
        return external_api::clean_returnvalue(
            ensure_section::execute_returns(),
            ensure_section::execute($courseid, $sectionnum, $name)
        );
    }

    /**
     * Abnahmekriterium: legt einen fehlenden Abschnitt an und erzeugt bei
     * erneutem Aufruf KEINEN zweiten.
     */
    public function test_creates_missing_section_and_is_idempotent(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $first = $this->ensure($course->id, 3, 'LS 3');
        $this->assertTrue($first['angelegt']);
        $this->assertSame('LS 3', $first['name']);

        $countbefore = count(get_fast_modinfo($course)->get_section_info_all());

        $second = $this->ensure($course->id, 3, 'LS 3');
        $this->assertFalse($second['angelegt']);
        $this->assertSame($first['id'], $second['id']);

        $countafter = count(get_fast_modinfo($course)->get_section_info_all());
        $this->assertSame($countbefore, $countafter, 'Ein erneuter Aufruf darf keinen zweiten Abschnitt erzeugen.');
    }

    /**
     * Abnahmekriterium: gleicht bei vorhandenem Abschnitt nur den Namen ab -
     * Zusammenfassung/Sichtbarkeit bleiben unangetastet.
     */
    public function test_reconciles_only_name_on_existing_section(): void {
        global $DB;

        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $DB->set_field('course_sections', 'summary', 'Bestehende Zusammenfassung', ['course' => $course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'visible', 0, ['course' => $course->id, 'section' => 1]);

        $result = $this->ensure($course->id, 1, 'Neuer Name');

        $this->assertFalse($result['angelegt']);
        $this->assertSame('Neuer Name', $result['name']);

        $section = get_fast_modinfo($course)->get_section_info(1);
        $this->assertSame('Bestehende Zusammenfassung', $section->summary);
        $this->assertSame(0, (int) $section->visible);
    }

    public function test_without_name_leaves_existing_name_untouched(): void {
        global $DB;

        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $DB->set_field('course_sections', 'name', 'Alter Name', ['course' => $course->id, 'section' => 1]);

        $result = $this->ensure($course->id, 1);

        $this->assertFalse($result['angelegt']);
        $this->assertSame('Alter Name', $result['name']);
    }

    public function test_requires_native_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $roleid = $this->get_role_id('editingteacher');
        assign_capability('moodle/course:update', CAP_PREVENT, $roleid, \context_course::instance($course->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\required_capability_exception::class);
        ensure_section::execute($course->id, 5, 'X');
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
