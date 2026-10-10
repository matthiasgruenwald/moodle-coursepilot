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
    /**
     * Sets up teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass} course and teacher (logged in)
     */
    private function setup_teacher(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * Everything a leftover activity could touch.
     *
     * @param int $courseid The courseid.
     */
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
        $this->assertEmpty(get_fast_modinfo($course->id)->get_instances_of('book'));
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

    public function test_hidden_export_preserves_concurrent_native_creation_and_recyclebin(): void {
        global $DB, $CFG;
        [$course] = $this->setup_teacher();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL named-lock fixture required.');
        }
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $token = 'cp' . bin2hex(random_bytes(8));
        $gate = $token . 'gate';
        $ready = $token . 'ready';
        $trigger = $token . 'trigger';
        $DB->get_field_sql('SELECT GET_LOCK(?, 10)', [$gate]);
        $table = $DB->get_prefix() . 'backup_controllers';
        $cmtable = $DB->get_prefix() . 'course_modules';
        $bookmodule = $DB->get_field('modules', 'id', ['name' => 'book'], MUST_EXIST);
        $ddl = new \mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
        $ddl->query("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW BEGIN
            IF NEW.type = 'activity' AND EXISTS (SELECT 1 FROM $cmtable WHERE id = NEW.itemid
                    AND course = $course->id AND module = $bookmodule) THEN
                SET @cp_ready = GET_LOCK('$ready', 10);
                SET @cp_gate = GET_LOCK('$gate', 30);
            END IF;
        END");
        $command = [PHP_BINARY, __DIR__ . '/../fixtures/default_export_process.php',
            (string) $course->id, (string) $GLOBALS['USER']->id];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            $deadline = microtime(true) + 20;
            do {
                $waiting = $DB->get_field_sql('SELECT IS_USED_LOCK(?)', [$ready]);
                if ($waiting) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if (!$waiting) {
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $this->fail('Export barrier: ' . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            }
            $own = $DB->get_record('course_modules', ['course' => $course->id, 'module' => $DB->get_field('modules', 'id', ['name' => 'book'])], '*', MUST_EXIST);
            $this->assertSame(0, (int) $own->visible);
            $this->assertSame(0, (int) $own->visibleoncoursepage);
            // Spec 0028 story 33 protects learners; teachers retain native hidden-activity access.
            $learnercm = get_fast_modinfo($course->id, $student->id)->get_cm($own->id);
            $this->assertFalse($learnercm->uservisible);
            $this->assertFalse($learnercm->is_visible_on_course_page());
            $teachercm = get_fast_modinfo($course->id, $GLOBALS['USER']->id)->get_cm($own->id);
            $this->assertTrue($teachercm->is_visible_on_course_page());
            $foreign = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Foreign']);
            $deleted = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Foreign deleted']);
            course_get_format($course)->delete_module(get_fast_modinfo($course)->get_cm($deleted->cmid), false);
            $bin = $DB->get_records('tool_recyclebin_course', ['courseid' => $course->id]);
            $this->assertNotEmpty($bin);
            $DB->get_field_sql('SELECT RELEASE_LOCK(?)', [$gate]);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output . $errors);
            $process = null;
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('<book id=', $result['xml']);
            $this->assertFalse($DB->record_exists('course_modules', ['id' => $own->id]));
            $this->assertFalse($DB->record_exists('local_coursepilot_cm_version', ['cmid' => $own->id]));
            $this->assertTrue($DB->record_exists('course_modules', ['id' => $foreign->cmid]));
            $this->assertEquals($bin, $DB->get_records('tool_recyclebin_course', ['courseid' => $course->id]));
        } finally {
            $DB->get_field_sql('SELECT RELEASE_LOCK(?)', [$gate]);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            $ddl->query("DROP TRIGGER IF EXISTS $trigger");
            $ddl->close();
        }
    }

    public function test_cleanup_failure_returns_an_explicit_error_instead_of_xml(): void {
        global $DB, $CFG;
        [$course] = $this->setup_teacher();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL trigger fixture required.');
        }
        $trigger = 'cp' . bin2hex(random_bytes(8));
        $table = $DB->get_prefix() . 'course_modules';
        $ddl = new \mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
        $ddl->query("CREATE TRIGGER $trigger BEFORE DELETE ON $table FOR EACH ROW BEGIN
            IF OLD.course = $course->id THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic cleanup failure';
            END IF;
        END");
        try {
            try {
                export_default_activity::execute($course->id, 'book');
                $this->fail('Cleanup failure must not return XML success');
            } catch (\moodle_exception $e) {
                $this->assertSame('defaultactivitycleanupfailed', $e->errorcode);
            }
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $trigger");
            $ddl->close();
        }
    }

    public function test_native_creation_and_deletion_events_remain_observable(): void {
        [$course] = $this->setup_teacher();
        $sink = $this->redirectEvents();
        $result = export_default_activity::execute($course->id, 'book');
        $events = $sink->get_events();
        $created = array_values(array_filter(
            $events,
            fn($event) => $event instanceof \core\event\course_module_created
        ));
        $deleted = array_values(array_filter(
            $events,
            fn($event) => $event instanceof \core\event\course_module_deleted
        ));
        $this->assertStringContainsString('<book id=', $result['xml']);
        $this->assertCount(1, $created);
        $this->assertCount(1, $deleted);
        $this->assertSame((int) $created[0]->objectid, (int) $deleted[0]->objectid);
        $sink->close();
    }

    public function test_requires_backup_capability(): void {
        [$course] = $this->setup_teacher();
        $roleid = $GLOBALS['DB']->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/backup:backupactivity', CAP_PROHIBIT, $roleid, \context_course::instance($course->id));
        $this->expectException(\required_capability_exception::class);
        export_default_activity::execute($course->id, 'book');
    }

    public function test_is_registered_as_write_tool(): void {
        $this->assertTrue(tool_registry::is_write('coursepilot_export_default_activity'));
    }
}
