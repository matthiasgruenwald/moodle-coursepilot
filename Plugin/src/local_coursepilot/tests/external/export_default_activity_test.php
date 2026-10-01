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
use local_coursepilot\tool_registry;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * export_default_activity (Spec 0026, #589): default XML of a developed activity type,
 * nothing left behind.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(export_default_activity::class)]
final class export_default_activity_test extends \advanced_testcase {

    /** @return array{0: \stdClass, 1: \stdClass} course and teacher (logged in) */
    private function setup_teacher(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /** Everything a leftover activity could touch. */
    private function footprint(int $courseid): array {
        global $DB;
        return [
            'cm' => $DB->count_records('course_modules', ['course' => $courseid]),
            'sequence' => $DB->get_field('course_sections', 'sequence', ['course' => $courseid, 'section' => 0]),
            'history' => $DB->count_records('local_coursepilot_cm_version'),
            'recyclebin' => $DB->count_records('tool_recyclebin_course'),
            'books' => $DB->count_records('book'),
        ];
    }

    public function test_returns_default_xml_and_leaves_nothing(): void {
        [$course] = $this->setup_teacher();
        $before = $this->footprint($course->id);

        $result = export_default_activity::execute($course->id, 'book');
        $result = external_api::clean_returnvalue(export_default_activity::execute_returns(), $result);

        $this->assertSame('book', $result['modname']);
        $this->assertStringContainsString('<book id=', $result['xml']);
        $this->assertSame($before, $this->footprint($course->id));
        $this->assertSame(0, get_fast_modinfo($course->id)->get_instances_of('book') ? 1 : 0);
    }

    public function test_works_for_glossary(): void {
        [$course] = $this->setup_teacher();
        $result = export_default_activity::execute($course->id, 'glossary');
        $this->assertStringContainsString('<glossary id=', $result['xml']);
    }

    public function test_leaves_nothing_when_recyclebin_enabled(): void {
        [$course] = $this->setup_teacher();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $before = $this->footprint($course->id);
        export_default_activity::execute($course->id, 'book');
        $this->assertSame($before, $this->footprint($course->id));
    }

    public function test_rejects_catalogued_kind_with_create_module_hint(): void {
        [$course] = $this->setup_teacher();
        try {
            export_default_activity::execute($course->id, 'page');
            $this->fail('catalogued kind must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('defaultactivitycatalogued', $e->errorcode);
            $this->assertStringContainsString('create_module', $e->getMessage());
        }
    }

    public function test_rejects_excluded_kind_with_reason(): void {
        [$course] = $this->setup_teacher();
        try {
            export_default_activity::execute($course->id, 'lesson');
            $this->fail('excluded kind must be rejected');
        } catch (\moodle_exception $e) {
            $this->assertSame('kindexcludedquestions', $e->errorcode);
        }
    }

    public function test_requires_edit_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        export_default_activity::execute($course->id, 'book');
    }

    public function test_is_registered_as_read_tool(): void {
        $this->assertFalse(tool_registry::is_write('coursepilot_export_default_activity'));
    }
}
