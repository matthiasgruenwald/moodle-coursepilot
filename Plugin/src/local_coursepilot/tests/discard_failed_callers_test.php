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

defined('MOODLE_INTERNAL') || die();

/**
 * Caller duty of {@see course_module_placement::discard_failed()} (#601): every caller hands
 * over only cmids created in the same call. Each failure path runs next to activities that
 * already exist in the course (same type, same section); they must stay untouched.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_backup::class)]
#[CoversClass(export_default_activity::class)]
#[CoversClass(xml_activity_creator::class)]
final class discard_failed_callers_test extends \advanced_testcase {
    /**
     * Sets up course.
     *
     * @return array{0: \stdClass, 1: string} course with existing activities, book XML "Created"
     */
    private function setup_course(): array {
        $this->resetAfterTest();
        // Failed restores must finish their transaction before the next backup operation.
        $this->preventResetByRollback();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];
        $xml = preg_replace('#<name>.*?</name>#s', '<name>Created</name>', $xml, 1);
        foreach ([0, 1] as $section) {
            foreach (['book' => 'Old', 'page' => 'Page'] as $modname => $prefix) {
                $this->getDataGenerator()->create_module(
                    $modname,
                    ['course' => $course->id, 'name' => "$prefix $section"],
                    ['section' => $section]
                );
            }
        }
        set_config('coursebinenable', 1, 'tool_recyclebin');
        return [$course, $xml];
    }

    /**
     * Everything a discard of an existing activity would change.
     *
     * @param int $courseid The courseid.
     */
    private function course_state(int $courseid): array {
        global $DB;
        $state = [];
        foreach ($DB->get_records('course_modules', ['course' => $courseid], 'id') as $cm) {
            $modname = $DB->get_field('modules', 'name', ['id' => $cm->module]);
            $state[$cm->id] = [(array) $cm, (array) $DB->get_record($modname, ['id' => $cm->instance])];
        }
        $state['sequence'] = $DB->get_records_menu('course_sections', ['course' => $courseid], 'section', 'section, sequence');
        $state['recyclebin'] = $DB->count_records('tool_recyclebin_course');
        return $state;
    }

    /**
     * Asserts fails.
     *
     * @param callable $call The call.
     */
    private function assert_fails(callable $call): void {
        try {
            $call();
        } catch (\Throwable $e) {
            $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $e);
            return;
        }
        $this->fail('The failure path must throw.');
    }

    public function test_activity_backup_restore_failure_keeps_existing(): void {
        [$course, $xml] = $this->setup_course();
        $before = $this->course_state($course->id);
        // A non-numeric value in an integer column fails the restore after the cm row exists.
        $broken = preg_replace('#<numbering>.*?</numbering>#s', '<numbering>abc</numbering>', $xml, 1);
        $this->assert_fails(fn() => activity_backup::restore((int) $course->id, 1, $broken));
        $this->assertSame($before, $this->course_state($course->id));
    }

    public function test_export_default_activity_failure_keeps_existing(): void {
        global $CFG;
        [$course] = $this->setup_course();
        $before = $this->course_state($course->id);
        // Temp dir is a file: the backup fails after the throwaway activity exists.
        $tempdir = $CFG->tempdir;
        $CFG->tempdir = tempnam(sys_get_temp_dir(), 'cp');
        set_error_handler(fn() => true, E_WARNING); // The expected mkdir() warning.
        try {
            $this->assert_fails(fn() => export_default_activity::execute($course->id, 'book'));
        } finally {
            restore_error_handler();
            unlink($CFG->tempdir);
            $CFG->tempdir = $tempdir;
        }
        $this->assertSame($before, $this->course_state($course->id));
    }

    public function test_export_default_activity_success_keeps_existing(): void {
        [$course] = $this->setup_course();
        $before = $this->course_state($course->id);
        export_default_activity::execute($course->id, 'book');
        $this->assertSame($before, $this->course_state($course->id));
    }

    public function test_xml_activity_creator_failure_paths_keep_existing(): void {
        global $DB;
        [$course, $xml] = $this->setup_course();
        $before = $this->course_state($course->id);
        $old = (int) $DB->get_field('course_modules', 'id', ['course' => $course->id,
            'instance' => $DB->get_field('book', 'id', ['course' => $course->id, 'name' => 'Old 1'])]);
        $mismatch = str_replace('</book>', '<bogusfield>x</bogusfield></book>', $xml);
        // Without and with a predecessor: the catch block of create() must discard only the new cm.
        foreach ([null, $old] as $replaces) {
            $this->assert_fails(fn() => xml_activity_creator::create($course->id, 'book', 1, $mismatch, false, $replaces));
            $this->assertSame($before, $this->course_state($course->id));
        }
    }
}
