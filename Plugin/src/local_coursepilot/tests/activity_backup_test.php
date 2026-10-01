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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Activity backup module (Spec 0026, module 1, #588): export, restore from a
 * real backup and from an activity XML, cleanup of a half-made activity.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_backup::class)]
final class activity_backup_test extends \advanced_testcase {

    private function course_as_editing_teacher(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    private function page_xml(string $name): string {
        return '<activity id="1" moduleid="900001" modulename="page" contextid="1"><page id="1">'
            . '<name>' . $name . '</name><intro></intro><introformat>1</introformat>'
            . '<content>&lt;p&gt;Hallo&lt;/p&gt;</content><contentformat>1</contentformat>'
            . '<legacyfiles>0</legacyfiles><legacyfileslast>$@NULL@$</legacyfileslast><display>5</display>'
            . '<displayoptions>a:1:{s:12:"printheading";s:1:"1";}</displayoptions><revision>1</revision>'
            . '<timemodified>0</timemodified></page></activity>';
    }

    /** @return int[] */
    private function cmids(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]));
    }

    private function tempdir_entries(): array {
        global $CFG;
        // Moodle's own controller debug logs (*.log) stay by design; only directories count.
        return glob($CFG->tempdir . '/backup/*', GLOB_ONLYDIR) ?: [];
    }

    public function test_export_returns_activity_xml_and_leaves_no_tempdir(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Quelle']);
        $before = $this->tempdir_entries();

        $xml = activity_backup::export(get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST));

        $dom = new \DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertSame('page', $dom->documentElement->getAttribute('modulename'));
        $this->assertStringContainsString('Quelle', $xml);
        $this->assertSame($before, $this->tempdir_entries());
    }

    public function test_restore_from_activity_xml_creates_activity_in_section(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $before = $this->cmids((int) $course->id);

        $newcmid = activity_backup::restore((int) $course->id, 2, $this->page_xml('Aus XML'));

        $this->assertNotContains($newcmid, $before);
        $cm = get_coursemodule_from_id('page', $newcmid, (int) $course->id, false, MUST_EXIST);
        $this->assertSame('Aus XML', $cm->name);
        $this->assertEquals(2, get_fast_modinfo($course)->get_cm($newcmid)->sectionnum);
        $this->assertStringContainsString('Hallo', $DB->get_field('page', 'content', ['id' => $cm->instance]));
    }

    public function test_restore_from_real_backup_clones_activity(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Original']);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $tempbefore = $this->tempdir_entries();

        $newcmid = activity_backup::restore((int) $course->id, null, activity_backup::backup($cm));

        $this->assertNotSame((int) $page->cmid, $newcmid);
        $this->assertSame('Original', get_coursemodule_from_id('page', $newcmid, 0, false, MUST_EXIST)->name);
        $this->assertSame($tempbefore, $this->tempdir_entries());
    }

    public function test_failed_restore_removes_half_made_activity_and_tempdir(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $before = $this->cmids((int) $course->id);
        $tempbefore = $this->tempdir_entries();

        // A non-numeric value in an integer column makes the restore fail after the cm row exists.
        $broken = str_replace('<display>5</display>', '<display>abc</display>', $this->page_xml('Kaputt'));
        try {
            activity_backup::restore((int) $course->id, 1, $broken);
            $this->fail('Restore of broken XML must throw.');
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
        }

        $this->assertSame($before, $this->cmids((int) $course->id));
        $this->assertSame($tempbefore, $this->tempdir_entries());
    }

    public function test_restore_rejects_xml_that_is_not_an_activity(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();

        $this->expectException(\invalid_parameter_exception::class);
        activity_backup::restore((int) $course->id, 1, '<foo/>');
    }
}
