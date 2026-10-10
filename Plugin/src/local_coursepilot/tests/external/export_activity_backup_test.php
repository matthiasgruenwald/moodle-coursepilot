<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\external;

use core_external\external_api;
use local_coursepilot\tool_registry;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * export_activity_backup (Spec 0026, #588): thin adapter, read-only.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(export_activity_backup::class)]
final class export_activity_backup_test extends \advanced_testcase {
    public function test_returns_activity_xml(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Quelle']);

        $result = export_activity_backup::execute((int) $page->cmid);
        $result = external_api::clean_returnvalue(export_activity_backup::execute_returns(), $result);

        $this->assertSame('page', $result['modname']);
        $this->assertStringContainsString('<name>Quelle</name>', $result['xml']);
    }

    public function test_requires_backup_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $this->expectException(\required_capability_exception::class);
        export_activity_backup::execute((int) $page->cmid);
    }

    public function test_is_registered_as_read_tool(): void {
        $this->assertFalse(tool_registry::is_write('coursepilot_export_activity_backup'));
        $this->assertTrue(tool_registry::is_write('coursepilot_export_questions_xml'));
    }
}
