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

namespace local_coursepilot;

use local_coursepilot\external\export_default_activity;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Flow of xml_activity_creator through its module interface (Spec 0026, #590).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(xml_activity_creator::class)]
final class xml_activity_creator_test extends \advanced_testcase {

    /** @return array{0: \stdClass, 1: string} course and a book activity XML named "Created" */
    private function setup_course(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];
        $xml = preg_replace('#<name>.*?</name>#s', '<name>Created</name>', $xml, 1);
        return [$course, $xml];
    }

    private function footprint(int $courseid): array {
        global $DB;
        return [
            'cm' => $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]),
            'history' => $DB->count_records('local_coursepilot_cm_version'),
            'recyclebin' => $DB->count_records('tool_recyclebin_course'),
            'books' => $DB->count_records('book'),
        ];
    }

    public function test_creates_visible_with_exactly_one_history_state(): void {
        global $DB;
        [$course, $xml] = $this->setup_course();
        $result = xml_activity_creator::create($course->id, 'book', 2, $xml);

        $cm = get_fast_modinfo($course->id)->get_cm($result['cmid']);
        $this->assertSame('Created', $cm->name);
        $this->assertEquals(2, $cm->sectionnum);
        $this->assertTrue((bool) $cm->visible);
        $versions = $DB->get_records('local_coursepilot_cm_version', ['cmid' => $result['cmid']]);
        $this->assertCount(1, $versions);
        $this->assertSame('from_xml', reset($versions)->source);
    }

    public function test_hidden_stays_hidden(): void {
        [$course, $xml] = $this->setup_course();
        $result = xml_activity_creator::create($course->id, 'book', 1, $xml, true);
        $this->assertFalse((bool) get_fast_modinfo($course->id)->get_cm($result['cmid'])->visible);
    }

    public function test_deviation_discards_without_trace_even_with_recyclebin(): void {
        [$course, $xml] = $this->setup_course();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $before = $this->footprint($course->id);
        $broken = str_replace('</book>', '<bogusfield>x</bogusfield></book>', $xml);
        try {
            xml_activity_creator::create($course->id, 'book', 1, $broken);
            $this->fail('deviation must be reported');
        } catch (\moodle_exception $e) {
            $this->assertSame('xmlroundtripmismatch', $e->errorcode);
            $this->assertStringContainsString('bogusfield', $e->getMessage());
        }
        $this->assertSame($before, $this->footprint($course->id));
    }

    public function test_invalid_xml_writes_nothing(): void {
        [$course] = $this->setup_course();
        $before = $this->footprint($course->id);
        foreach (['<activity', '<foo/>', '<activity modulename="glossary"><glossary/></activity>'] as $bad) {
            try {
                xml_activity_creator::create($course->id, 'book', 1, $bad);
                $this->fail('invalid XML must be rejected');
            } catch (\invalid_parameter_exception $e) {
                $this->assertSame($before, $this->footprint($course->id));
            }
        }
    }

    public function test_kind_gate_rejects_catalogued_and_excluded(): void {
        [$course, $xml] = $this->setup_course();
        foreach (['page' => 'createfromxmlcatalogued', 'lesson' => 'kindexcludedquestions'] as $modname => $code) {
            try {
                xml_activity_creator::create($course->id, $modname, 1, $xml);
                $this->fail("$modname must be rejected");
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode);
            }
        }
    }
}
