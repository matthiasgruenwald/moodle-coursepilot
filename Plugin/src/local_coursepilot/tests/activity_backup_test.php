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
    protected function setUp(): void {
        parent::setUp();
        // Backup temporary-table bookkeeping must survive each operation's transaction boundary.
        $this->preventResetByRollback();
    }

    /**
     * Provides course as editing teacher.
     *
     * @return \stdClass
     */
    private function course_as_editing_teacher(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Provides page xml.
     *
     * @param string $name The name.
     * @return string
     */
    private function page_xml(string $name): string {
        return '<activity id="1" moduleid="900001" modulename="page" contextid="1"><page id="1">'
            . '<name>' . $name . '</name><intro></intro><introformat>1</introformat>'
            . '<content>&lt;p&gt;Hallo&lt;/p&gt;</content><contentformat>1</contentformat>'
            . '<legacyfiles>0</legacyfiles><legacyfileslast>$@NULL@$</legacyfileslast><display>5</display>'
            . '<displayoptions>a:1:{s:12:"printheading";s:1:"1";}</displayoptions><revision>1</revision>'
            . '<timemodified>0</timemodified></page></activity>';
    }

    /**
     * Provides cmids.
     *
     * @param int $courseid The courseid.
     * @return int[]
     */
    private function cmids(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]));
    }

    /**
     * Provides tempdir entries.
     *
     * @return array
     */
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

    public function test_failed_restore_preserves_concurrent_native_creation_and_recyclebin(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL named-lock fixture required.');
        }
        $course = $this->course_as_editing_teacher();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $token = 'cp' . bin2hex(random_bytes(8));
        $gate = $token . 'gate';
        $ready = $token . 'ready';
        $trigger = $token . 'trigger';
        $DB->get_field_sql('SELECT GET_LOCK(?, 10)', [$gate]);
        $table = $DB->get_prefix() . 'page';
        // The restore reaches native instance creation only after its cm/task identity exists.
        $ddl = new \mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
        $ddl->query("CREATE TRIGGER $trigger BEFORE INSERT ON $table FOR EACH ROW BEGIN
            IF NEW.name = 'Owned restore' THEN
                SET @cp_ready = GET_LOCK('$ready', 10);
                SET @cp_gate = GET_LOCK('$gate', 30);
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic restore failure';
            END IF;
        END");
        $xmlfile = tempnam($CFG->tempdir, 'cp-restore');
        file_put_contents($xmlfile, $this->page_xml('Owned restore'));
        $command = [PHP_BINARY, __DIR__ . '/fixtures/failed_restore_process.php',
            (string) $course->id, (string) $GLOBALS['USER']->id, $xmlfile];
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
                $this->fail('Restore barrier: ' . stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
            }
            $own = $DB->get_record('course_modules', ['course' => $course->id, 'instance' => 0], '*', MUST_EXIST);
            $foreign = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Foreign']);
            $deleted = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Foreign deleted']);
            course_get_format($course)->delete_module(get_fast_modinfo($course)->get_cm($deleted->cmid), false);
            $bin = $DB->get_records('tool_recyclebin_course', ['courseid' => $course->id]);
            $this->assertNotEmpty($bin);
            $DB->get_field_sql('SELECT RELEASE_LOCK(?)', [$gate]);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
            $process = null;
            $this->assertFalse($DB->record_exists('course_modules', ['id' => $own->id]));
            $this->assertFalse($DB->record_exists('context', ['contextlevel' => CONTEXT_MODULE, 'instanceid' => $own->id]));
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
            unlink($xmlfile);
        }
    }

    public function test_partial_restore_removes_its_task_owned_instance_before_cm_linking(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL trigger fixture required.');
        }
        $course = $this->course_as_editing_teacher();
        $trigger = 'cp' . bin2hex(random_bytes(8));
        $ddl = new \mysqli($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname);
        $table = $DB->get_prefix() . 'course_modules';
        $DB->execute('SET @cp_link_failed = NULL');
        $ddl->query("CREATE TRIGGER $trigger BEFORE UPDATE ON $table FOR EACH ROW BEGIN
            IF NEW.instance > 0 AND OLD.instance = 0 AND @cp_link_failed IS NULL THEN
                SET @cp_link_failed = 1;
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic instance-link failure';
            END IF;
        END");
        try {
            try {
                activity_backup::restore((int) $course->id, 1, $this->page_xml('Unlinked owned instance'));
                $this->fail('Restore must fail before linking its native instance.');
            } catch (\dml_write_exception $e) {
                $this->assertStringContainsString('Synthetic instance-link failure', $e->debuginfo);
            }
            $this->assertFalse($DB->record_exists('page', ['course' => $course->id]));
            $this->assertSame([], $this->cmids((int) $course->id));
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $trigger");
            $ddl->close();
            $DB->execute('SET @cp_link_failed = NULL');
        }
    }

    public function test_missing_restore_identity_reports_incomplete_cleanup_without_deletion(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $backupid = activity_backup::backup(get_coursemodule_from_id('page', $page->cmid));
        $path = $CFG->tempdir . '/backup/' . $backupid . '/moodle_backup.xml';
        $xml = file_get_contents($path);
        $xml = preg_replace('#<moodle_version>.*?</moodle_version>#s', '<moodle_version>9999999999</moodle_version>', $xml);
        file_put_contents($path, $xml);
        $before = $this->cmids((int) $course->id);
        try {
            activity_backup::restore((int) $course->id, null, $backupid);
            $this->fail('Missing ownership must fail explicitly.');
        } catch (\moodle_exception $e) {
            $this->assertSame('activitycleanupincomplete', $e->errorcode);
        }
        $this->assertSame($before, $this->cmids((int) $course->id));
    }

    public function test_restore_rejects_xml_that_is_not_an_activity(): void {
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();

        $this->expectException(\invalid_parameter_exception::class);
        activity_backup::restore((int) $course->id, 1, '<foo/>');
    }
}
