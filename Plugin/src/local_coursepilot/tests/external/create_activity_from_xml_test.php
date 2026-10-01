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
 * create_activity_from_xml adapter (Spec 0026, #590).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(create_activity_from_xml::class)]
final class create_activity_from_xml_test extends \advanced_testcase {

    public function test_creates_and_returns_cmid_with_presets_hint(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];
        // Drop one field so Moodle has to fill it in again.
        $xml = preg_replace('#<numbering>.*?</numbering>#s', '', $xml, 1);

        $result = create_activity_from_xml::execute($course->id, 'book', 1, $xml);
        $result = external_api::clean_returnvalue(create_activity_from_xml::execute_returns(), $result);

        $this->assertNotEmpty(get_fast_modinfo($course->id)->get_cm($result['cmid']));
        $this->assertContains('activity/book/numbering', $result['presets']);
        $this->assertStringContainsString('numbering', $result['message']);
    }

    public function test_requires_restore_capability(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        create_activity_from_xml::execute($course->id, 'book', 1, '<activity/>');
    }

    public function test_is_registered_as_write_tool(): void {
        $this->assertTrue(tool_registry::is_write('coursepilot_create_activity_from_xml'));
    }
}
