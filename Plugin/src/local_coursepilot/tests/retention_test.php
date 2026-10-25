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

use local_coursepilot\history\retention;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * History retention (#387, Spec 0015 §10.7): course and activity cascades
 * and opportunistic expiry cleanup.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(retention::class)]
#[CoversClass(observer::class)]
#[CoversClass(\local_coursepilot\task\purge_history::class)]
final class retention_test extends \advanced_testcase {
    /**
     * Create a course, page activity and editing teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function create_page(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $cm];
    }

    /**
     * Acceptance: default retention is one year (365 days).
     */
    public function test_default_retention_is_365_days(): void {
        $this->resetAfterTest();
        $this->assertSame(365, retention::days());
        $this->assertSame(365, retention::DEFAULT_DAYS);
    }

    /**
     * Acceptance: retention can be shortened.
     */
    public function test_retention_can_be_shortened_via_setting(): void {
        $this->resetAfterTest();
        set_config('historyretentiondays', 7, 'local_coursepilot');
        $this->assertSame(7, retention::days());
    }

    /**
     * Acceptance: unlimited retention is unavailable; invalid raw values
     * (0 or negative) are clamped to at least one day.
     */
    public function test_zero_or_negative_raw_value_is_clamped_to_one_day(): void {
        $this->resetAfterTest();

        set_config('historyretentiondays', 0, 'local_coursepilot');
        $this->assertSame(1, retention::days());

        set_config('historyretentiondays', -5, 'local_coursepilot');
        $this->assertSame(1, retention::days());
    }

    /**
     * Acceptance: course deletion removes history through stored courseid,
     * since course_modules is already gone by the course_deleted event.
     */
    public function test_course_deletion_removes_its_history(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();

        $this->assertGreaterThan(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));

        delete_course($course, false);

        $this->assertSame(0, (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertSame(0, (int) $DB->count_records('local_coursepilot_cm_version', ['courseid' => $course->id]));
    }

    /**
     * Acceptance: activity deletion removes only its own history; other
     * activities, including those in the same course, remain unchanged.
     */
    public function test_activity_deletion_removes_only_its_own_history(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/lib.php');
        [$course, $cm] = $this->create_page();

        $otherpage = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
        ]);
        $othercm = get_coursemodule_from_instance('page', $otherpage->id, $course->id, false, MUST_EXIST);

        $versionid = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cm->id], MUST_EXIST);

        course_get_format($course)->delete_module(get_fast_modinfo($course)->get_cm($cm->id), false);

        $this->assertSame(0, (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertSame(0, (int) $DB->count_records('local_coursepilot_cm_version_file', ['versionid' => $versionid]));
        $this->assertGreaterThan(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $othercm->id]));
    }

    /**
     * Acceptance: the next write to the same cmid removes expired states
     * while preserving the newly created state.
     */
    public function test_write_purges_expired_versions_of_same_cm_but_keeps_fresh_one(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/modlib.php');
        [$course, $cm] = $this->create_page();

        set_config('historyretentiondays', 1, 'local_coursepilot');

        // Simulate an expired state beyond the one-day retention period.
        $DB->set_field('local_coursepilot_cm_version', 'timecreated', time() - (2 * DAYSECS), [
            'cmid' => $cm->id,
        ]);

        [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
        $moduleinfo->name = 'Neuer Titel';
        $moduleinfo->page = ['text' => $moduleinfo->content, 'format' => $moduleinfo->contentformat, 'itemid' => 0];
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 1;
        update_moduleinfo($cm, $moduleinfo, $course, null);

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount(1, $versions, 'Expired state must be removed; only the fresh state remains.');
        $this->assertSame('Neuer Titel', json_decode($versions[0]->moduleinfo_json, true)['name']);
    }

    /**
     * A state within retention survives another write.
     */
    public function test_write_keeps_versions_within_retention_period(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/modlib.php');
        [$course, $cm] = $this->create_page();

        set_config('historyretentiondays', 365, 'local_coursepilot');

        [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
        $moduleinfo->name = 'Zweiter Stand';
        $moduleinfo->page = ['text' => $moduleinfo->content, 'format' => $moduleinfo->contentformat, 'itemid' => 0];
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 1;
        update_moduleinfo($cm, $moduleinfo, $course, null);

        $this->assertCount(2, $DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
    }

    /**
     * Ages every state of a cm by $seconds.
     *
     * @param int $cmid The cmid.
     * @param int $seconds The seconds.
     */
    private function age(int $cmid, int $seconds): void {
        global $DB;
        $DB->set_field('local_coursepilot_cm_version', 'timecreated', time() - $seconds, ['cmid' => $cmid]);
    }

    /**
     * Inserts synthetic file metadata and links it to a version.
     *
     * @param int $versionid The versionid.
     * @param string $component The component.
     * @param string $filearea The filearea.
     * @param string $name The name.
     */
    private function link_file(int $versionid, string $component, string $filearea, string $name): int {
        global $DB;
        $fileid = $DB->get_field('local_coursepilot_cm_file', 'id', ['pathnamehash' => sha1($name)]);
        if (!$fileid) {
            $fileid = $DB->insert_record('local_coursepilot_cm_file', (object) [
                'pathnamehash' => sha1($name), 'contenthash' => sha1('synthetic'),
                'component' => $component, 'filearea' => $filearea, 'itemid' => 0,
                'filepath' => '/', 'filename' => $name, 'filesize' => 9, 'mimetype' => 'image/png',
                'timemodified' => time(),
            ]);
        }
        $DB->insert_record('local_coursepilot_cm_version_file', (object) [
            'versionid' => $versionid, 'fileid' => $fileid, 'gap' => 0,
        ]);
        return (int) $fileid;
    }

    /**
     * Runs the registered scheduled task like cron does.
     */
    private function run_task(): void {
        $task = \core\task\manager::get_scheduled_task(\local_coursepilot\task\purge_history::class);
        $this->assertNotNull($task);
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    /**
     * An unchanged activity loses expired states in the task run, without any further write.
     */
    public function test_task_purges_expired_states_of_unchanged_activity(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $fresh = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $this->age($cm->id, 366 * DAYSECS);
        $freshcount = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $fresh->cmid]);
        $current = $DB->get_record('page', ['id' => $cm->instance]);

        $this->run_task();

        $this->assertSame(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertSame($freshcount, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $fresh->cmid]));
        $this->assertEquals($current, $DB->get_record('page', ['id' => $cm->instance]), 'Current content untouched.');
    }

    /**
     * States just inside the period stay, states just outside go.
     */
    public function test_task_respects_retention_boundary(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $other = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        set_config('historyretentiondays', 1, 'local_coursepilot');
        $this->age($cm->id, DAYSECS + 60);
        $this->age($other->cmid, DAYSECS - 60);

        $this->run_task();

        $this->assertSame(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertGreaterThan(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $other->cmid]));
    }

    /**
     * Bounded batches continue on the next run; a repeated run changes nothing more.
     */
    public function test_expiry_continues_across_runs_in_bounded_batches(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->create_page();
        for ($i = 0; $i < 2; $i++) {
            $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        }
        $DB->set_field('local_coursepilot_cm_version', 'timecreated', time() - 400 * DAYSECS);
        $total = $DB->count_records('local_coursepilot_cm_version');
        $this->assertGreaterThanOrEqual(3, $total);

        retention::enforce(1, 2);
        $this->assertSame($total - 2, $DB->count_records('local_coursepilot_cm_version'));

        retention::enforce(1, $total);
        $this->assertSame(0, $DB->count_records('local_coursepilot_cm_version'));

        retention::enforce(1, $total);
        $this->assertSame(0, $DB->count_records('local_coursepilot_cm_version'));
    }

    /**
     * Shared metadata survives until its last reference is gone; no orphans remain.
     */
    public function test_shared_file_metadata_kept_until_last_reference_goes(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $other = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $oldversion = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cm->id], MUST_EXIST);
        $keptversion = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $other->cmid], MUST_EXIST);
        $shared = $this->link_file($oldversion, 'mod_page', 'intro', 'shared.png');
        $this->link_file($keptversion, 'mod_page', 'intro', 'shared.png');
        $own = $this->link_file($oldversion, 'mod_page', 'intro', 'own.png');
        $this->age($cm->id, 400 * DAYSECS);

        $this->run_task();

        $this->assertFalse($DB->record_exists('local_coursepilot_cm_version_file', ['versionid' => $oldversion]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['id' => $own]));
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_file', ['id' => $shared]));
        $this->assertTrue($DB->record_exists(
            'local_coursepilot_cm_version_file',
            ['versionid' => $keptversion, 'fileid' => $shared]
        ));

        retention::purge_cm((int) $other->cmid);
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['id' => $shared]));
    }

    /**
     * Historical disallowed metadata and old orphans are cleaned in resumable batches.
     */
    public function test_historical_cleanup_removes_disallowed_metadata_and_orphans(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        $versionid = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cm->id], MUST_EXIST);
        $allowed = $this->link_file($versionid, 'mod_page', 'intro', 'design.png');
        $shareddisallowed = $this->link_file($versionid, 'assignsubmission_file', 'submission_files', 'student.pdf');
        $unknown = $this->link_file($versionid, 'mod_page', 'unknown', 'unknown.pdf');
        $orphan = $this->link_file($versionid, 'mod_page', 'intro', 'orphan.png');
        $DB->delete_records('local_coursepilot_cm_version_file', ['fileid' => $orphan]);
        $versions = $DB->get_records('local_coursepilot_cm_version', null, 'id');

        retention::enforce(1, 2);
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_file', ['id' => $orphan]), 'Third row not reached yet.');
        retention::enforce(1, 10);
        retention::enforce(1, 10);

        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['id' => $shareddisallowed]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['id' => $unknown]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['id' => $orphan]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_version_file', ['fileid' => $shareddisallowed]));
        $this->assertTrue($DB->record_exists(
            'local_coursepilot_cm_version_file',
            ['versionid' => $versionid, 'fileid' => $allowed]
        ));
        $this->assertEquals(
            $versions,
            $DB->get_records('local_coursepilot_cm_version', null, 'id'),
            'Unexpired states stay unchanged.'
        );
    }

    /**
     * Fresh install registers the task and the indexes it relies on.
     */
    public function test_install_registers_task_and_indexes(): void {
        global $DB;
        $this->resetAfterTest();
        $this->assertNotNull(\core\task\manager::get_scheduled_task(\local_coursepilot\task\purge_history::class));
        $dbman = $DB->get_manager();
        $this->assertTrue($dbman->index_exists(
            new \xmldb_table('local_coursepilot_cm_version'),
            new \xmldb_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated'])
        ));
        $this->assertTrue($dbman->index_exists(
            new \xmldb_table('local_coursepilot_cm_version_file'),
            new \xmldb_index('fileid', XMLDB_INDEX_NOTUNIQUE, ['fileid'])
        ));
    }

    public function test_scheduled_purge_is_named_for_the_admin_overview(): void {
        $task = new \local_coursepilot\task\purge_history();
        $this->assertSame(get_string('taskpurgehistory', 'local_coursepilot'), $task->get_name());
    }

    /**
     * A stored period that is not a number counts as invalid and is clamped to one day like zero.
     */
    public function test_a_non_numeric_stored_period_is_clamped_to_one_day(): void {
        $this->resetAfterTest();

        set_config('historyretentiondays', 'abc', 'local_coursepilot');
        $e = null;
        try {
            $days = retention::days();
        } catch (\Throwable $e) {
            $days = null;
        }

        $this->assertNull($e);
        $this->assertSame(1, $days);
        set_config('historyretentiondays', '12', 'local_coursepilot');
        $this->assertSame(12, retention::days());
    }

    /**
     * The next write of an activity removes only the states older than the whole period, counted in days.
     */
    public function test_expiry_on_write_counts_whole_days(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        set_config('historyretentiondays', 3, 'local_coursepilot');
        $template = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id], '*', MUST_EXIST);
        $ids = [];
        foreach ([2 * DAYSECS, 4 * DAYSECS] as $number => $age) {
            $copy = clone $template;
            unset($copy->id);
            $copy->version = (int) $template->version + 1 + $number;
            $copy->timecreated = time() - $age;
            $ids[$age] = $DB->insert_record('local_coursepilot_cm_version', $copy);
        }

        retention::purge_expired_for_cm((int) $cm->id);

        $this->assertTrue($DB->record_exists('local_coursepilot_cm_version', ['id' => $ids[2 * DAYSECS]]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_version', ['id' => $ids[4 * DAYSECS]]));
    }

    /**
     * The file sweep visits at most the allowed number of batches per run and remembers where it stopped.
     */
    public function test_the_file_sweep_stops_after_the_allowed_batches_and_remembers_its_position(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        $versionid = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cm->id], MUST_EXIST);
        $fileids = [];
        foreach (['one.png', 'two.png', 'three.png', 'four.png'] as $name) {
            $fileids[] = $this->link_file($versionid, 'mod_page', 'intro', $name);
        }

        retention::enforce(1, 2);
        $this->assertSame((string) $fileids[1], get_config('local_coursepilot', 'historyfilecleanupcursor'));

        retention::enforce(1, 2);
        $this->assertSame((string) $fileids[3], get_config('local_coursepilot', 'historyfilecleanupcursor'));

        retention::enforce(1, 1);
        $this->assertSame('0', get_config('local_coursepilot', 'historyfilecleanupcursor'), 'The sweep wraps at the end.');
        $this->assertFalse($DB->is_transaction_started());
    }

    /**
     * Deleting states removes their links and unreferenced metadata and leaves no open transaction behind.
     */
    public function test_delete_versions_commits_what_it_deleted(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        $versionid = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cm->id], MUST_EXIST);
        $fileid = $this->link_file($versionid, 'mod_page', 'intro', 'gone.png');

        retention::delete_versions([$versionid]);

        $this->assertFalse($DB->is_transaction_started());
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_version', ['id' => $versionid]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['id' => $fileid]));
    }

    public function test_purging_the_states_of_given_users_touches_only_their_states_on_that_activity(): void {
        global $DB;
        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        $other = $this->getDataGenerator()->create_module('page', ['course' => $cm->course]);
        $template = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id], '*', MUST_EXIST);
        $add = function (int $cmid, int $userid, int $version) use ($DB, $template): int {
            $copy = clone $template;
            unset($copy->id);
            $copy->cmid = $cmid;
            $copy->userid = $userid;
            $copy->version = $version;
            return (int) $DB->insert_record('local_coursepilot_cm_version', $copy);
        };
        $a1 = $add((int) $cm->id, 901, 11);
        $a2 = $add((int) $cm->id, 902, 12);
        $a3 = $add((int) $cm->id, 903, 13);
        $elsewhere = $add((int) $other->cmid, 901, 11);

        retention::purge_cm_for_users((int) $cm->id, []);
        $this->assertCount(4, $DB->get_records_list('local_coursepilot_cm_version', 'id', [$a1, $a2, $a3, $elsewhere]));

        retention::purge_cm_for_users((int) $cm->id, ['x' => 901, 'y' => 903]);

        $this->assertFalse($DB->record_exists('local_coursepilot_cm_version', ['id' => $a1]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_version', ['id' => $a3]));
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_version', ['id' => $a2]));
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_version', ['id' => $elsewhere]));
        $this->assertFalse($DB->is_transaction_started());
    }

    public function test_deleting_states_waits_for_the_row_lock_that_a_concurrent_capture_holds(): void {
        global $DB;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL second connection fixture required.');
        }
        [$course, $cm] = $this->create_page();
        /** @var \mod_page_generator $modgen */
        $modgen = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $other = $modgen->create_instance(['course' => $course->id]);
        $old = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $cm->id], MUST_EXIST);
        $kept = (int) $DB->get_field('local_coursepilot_cm_version', 'id', ['cmid' => $other->cmid], MUST_EXIST);
        $fileid = $this->link_file($old, 'mod_page', 'intro', 'shared.png');
        $this->link_file($kept, 'mod_page', 'intro', 'shared.png');
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $capture = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $capture->begin_transaction();
        $capture->query('SELECT id FROM ' . $DB->get_prefix() . 'local_coursepilot_cm_file WHERE id = ' . $fileid . ' FOR UPDATE');
        $DB->execute('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $e = null;
            try {
                retention::delete_versions([$old]);
            } catch (\Throwable $caught) {
                $e = $caught;
            }
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback(); // The refused deletion leaves its transaction open.
            }
            $capture->rollback();
            $capture->close();
            $DB->execute('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertInstanceOf(\dml_exception::class, $e, 'The deletion has to wait for the concurrent capture.');
        $this->assertStringContainsString('Lock wait timeout', $e->debuginfo);
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_version', ['id' => $old]));
    }
}
