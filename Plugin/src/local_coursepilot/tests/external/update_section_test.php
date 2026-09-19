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
#[CoversClass(update_section::class)]
final class update_section_test extends \advanced_testcase {

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
     * @param array $felder
     * @return array
     */
    private function update(int $courseid, int $sectionnum, array $felder): array {
        return external_api::clean_returnvalue(
            update_section::execute_returns(),
            update_section::execute($courseid, $sectionnum, json_encode($felder))
        );
    }

    public function test_sets_name_summary_and_visible(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->update($course->id, 1, [
            'name' => 'LS 1: Einführung',
            'summary' => 'Kurzbeschreibung',
            'visible' => 0,
        ]);

        $this->assertCount(3, $result['aenderungen']);
        $this->assertStringContainsString('Geändert', $result['meldung']);

        $section = get_fast_modinfo($course)->get_section_info(1);
        $this->assertSame('LS 1: Einführung', $section->name);
        $this->assertSame('Kurzbeschreibung', $section->summary);
        $this->assertSame(0, (int) $section->visible);
    }

    /**
     * Abnahmekriterium: die Nebenwirkung auf enthaltene Aktivitaeten wird in
     * der Antwort ausgesprochen UND tritt nativ tatsaechlich ein.
     */
    public function test_hiding_section_hides_activities_and_says_so(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'section' => 1]);

        $result = $this->update($course->id, 1, ['visible' => 0]);

        $this->assertStringContainsString('unsichtbar', $result['meldung']);

        $cm = get_fast_modinfo($course)->get_cm($page->cmid);
        $this->assertSame(0, (int) $cm->visible, 'Ein unsichtbarer Abschnitt macht seine Aktivitaeten unsichtbar.');
    }

    public function test_no_side_effect_note_when_visibility_unchanged(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $result = $this->update($course->id, 1, ['name' => 'Nur Name']);

        $this->assertStringNotContainsString('unsichtbar', $result['meldung']);
    }

    public function test_unknown_field_throws(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $this->expectException(\moodle_exception::class);
        $this->update($course->id, 1, ['unknownfield' => 'x']);
    }

    public function test_invalid_visible_value_throws(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $this->expectException(\moodle_exception::class);
        $this->update($course->id, 1, ['visible' => 2]);
    }

    public function test_unknown_section_throws(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $this->expectException(\moodle_exception::class);
        $this->update($course->id, 99, ['name' => 'x']);
    }

    public function test_requires_native_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $roleid = $this->get_role_id('editingteacher');
        assign_capability('moodle/course:update', CAP_PREVENT, $roleid, \context_course::instance($course->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->expectException(\required_capability_exception::class);
        $this->update($course->id, 1, ['name' => 'x']);
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
