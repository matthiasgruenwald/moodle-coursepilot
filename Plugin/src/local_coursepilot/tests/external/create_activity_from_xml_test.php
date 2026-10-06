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

    public function test_lightboxgallery_missing_timemodified_is_rejected_without_mutation(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = file_get_contents(__DIR__ . '/../fixtures/lightboxgallery.xml');
        $xml = preg_replace('#<timemodified>.*?</timemodified>#s', '', $xml);
        $cms = get_fast_modinfo($course->id)->get_cms();
        $versions = $DB->count_records('local_coursepilot_cm_version');
        $galleries = $DB->count_records('lightboxgallery');
        try {
            create_activity_from_xml::execute($course->id, 'lightboxgallery', 1, $xml);
            $this->fail('Missing timemodified must be rejected before restore.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('activity_xml requires <timemodified> in <lightboxgallery>', $e->getMessage());
        }
        $this->assertEquals($cms, get_fast_modinfo($course->id)->get_cms());
        $this->assertSame($versions, $DB->count_records('local_coursepilot_cm_version'));
        $this->assertSame($galleries, $DB->count_records('lightboxgallery'));
    }

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

    public function test_replaces_cmid_supersedes_and_lists_references(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];
        $old = create_activity_from_xml::execute($course->id, 'book', 1, $xml)['cmid'];
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'availability',
            json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => $old, 'e' => 1]], 'showc' => [true]]),
            ['id' => $other->cmid]);

        $result = create_activity_from_xml::execute($course->id, 'book', 1, $xml, false, $old);
        $result = external_api::clean_returnvalue(create_activity_from_xml::execute_returns(), $result);

        $this->assertFalse((bool) get_fast_modinfo($course->id)->get_cm($old)->visible);
        $this->assertSame($other->cmid, (int) $result['references'][0]['location_id']);
        $this->assertStringContainsString('activity_availability', $result['message']);
    }

    public function test_dry_run_returns_references_and_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];
        $old = create_activity_from_xml::execute($course->id, 'book', 1, $xml)['cmid'];
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('course_modules', 'availability',
            json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => $old, 'e' => 1]], 'showc' => [true]]),
            ['id' => $other->cmid]);
        $cms = $DB->count_records('course_modules');
        $versions = $DB->count_records('local_coursepilot_cm_version');

        $result = create_activity_from_xml::execute($course->id, 'book', 1, $xml, false, $old, true);

        $this->assertSame(0, $result['cmid']);
        $this->assertSame($other->cmid, (int) $result['references'][0]['location_id']);
        $this->assertSame($cms, $DB->count_records('course_modules'));
        $this->assertSame($versions, $DB->count_records('local_coursepilot_cm_version'));
        $this->assertTrue((bool) get_fast_modinfo($course->id)->get_cm($old)->visible);
    }

    public function test_dry_run_on_superseded_names_successor_and_chain_count(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];
        $a = create_activity_from_xml::execute($course->id, 'book', 1, $xml)['cmid'];
        $b = create_activity_from_xml::execute($course->id, 'book', 1, $xml, false, $a)['cmid'];

        $result = create_activity_from_xml::execute($course->id, 'book', 1, $xml, false, $a, true);
        $result = external_api::clean_returnvalue(create_activity_from_xml::execute_returns(), $result);

        $this->assertSame($b, $result['successor_cmid']);
        $this->assertSame(1, $result['hidden_predecessors']);
        $this->assertStringContainsString("cmid $b", $result['message']);
        $this->assertStringContainsString('1 hidden', $result['message']);
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
