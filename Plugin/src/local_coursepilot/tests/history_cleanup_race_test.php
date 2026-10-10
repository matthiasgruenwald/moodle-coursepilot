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

namespace local_coursepilot;

use local_coursepilot\history\retention;
use local_coursepilot\history\version_history;
use local_coursepilot\history\version_writer;

/** Native history capture retains design files while the scheduled cleanup overlaps. */
#[\PHPUnit\Framework\Attributes\CoversClass(retention::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(version_writer::class)]
final class history_cleanup_race_test extends \advanced_testcase {
    #[\PHPUnit\Framework\Attributes\DataProvider('capture_modes')]
    public function test_failed_capture_leaves_no_partial_state_or_open_transaction(bool $update): void {
        global $DB, $USER;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL failure trigger fixture required.');
        }
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id,
            'component' => 'mod_page', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'design.png',
        ], 'synthetic design bytes');
        $before = $DB->get_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]);
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $key = 'cp648' . bin2hex(random_bytes(8));
        $table = $DB->get_prefix() . 'local_coursepilot_cm_version_file';
        $ddl->query("CREATE TRIGGER $key BEFORE INSERT ON $table FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic history link failure'");
        try {
            try {
                if ($update) {
                    version_writer::capture_on_update((int) $page->cmid, (int) $USER->id);
                } else {
                    version_writer::capture((int) $page->cmid, (int) $USER->id);
                }
                $this->fail('The injected history failure must propagate.');
            } catch (\dml_exception $e) {
                $this->assertStringContainsString('Synthetic history link failure', $e->debuginfo);
            }
            $this->assertFalse($DB->is_transaction_started(), 'An observer failure must not leave a transaction open.');
            $this->assertEquals($before, $DB->get_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]));
            $this->assertSame(0, $DB->count_records('local_coursepilot_cm_file'));
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback(); // Clean up the deliberately broken red fixture.
            }
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }
    }

    /**
     * Captures modes.
     *
     * @return array
     */
    public static function capture_modes(): array {
        return [[false], [true]];
    }

    public function test_cleanup_does_not_remove_metadata_being_reused_by_capture(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL named-lock barrier fixture required.');
        }
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($page->cmid)->id,
            'component' => 'mod_page', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'design.png',
        ], 'synthetic design bytes');
        $old = version_writer::capture((int) $page->cmid, (int) $USER->id);
        $this->assertCount(1, version_history::allowed_files($old, 'page'));
        // A resumable cleanup may encounter orphan metadata from an interrupted capture.
        $DB->delete_records('local_coursepilot_cm_version_file', ['versionid' => $old]);
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $key = 'cp648' . bin2hex(random_bytes(8));
        $gate = $key . 'gate';
        $ready = $key . 'ready';
        $DB->get_field_sql('SELECT GET_LOCK(?, 10)', [$gate]);
        $table = $DB->get_prefix() . 'local_coursepilot_cm_version_file';
        $ddl->query("CREATE TRIGGER $key BEFORE INSERT ON $table FOR EACH ROW BEGIN
            SET @cp_ready = GET_LOCK('$ready', 10);
            SET @cp_gate = GET_LOCK('$gate', 30);
        END");
        $processes = [];
        $pipes = [];
        try {
            $processes[0] = proc_open(
                [PHP_BINARY, __DIR__ . '/fixtures/history_cleanup_process.php',
                'capture', (string) $page->cmid, (string) $USER->id],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[0]
            );
            $this->await_condition(
                fn() => $DB->get_field_sql('SELECT IS_USED_LOCK(?)', [$ready]),
                'Capture did not reach the file-link barrier.'
            );
            $processes[1] = proc_open(
                [PHP_BINARY, __DIR__ . '/fixtures/history_cleanup_process.php', 'cleanup'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[1]
            );
            // The old implementation finishes cleanup; the fixed one waits on the metadata row.
            $this->await_condition(
                fn() => !proc_get_status($processes[1])['running'] ||
                (int) $DB->get_field_sql(
                    'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE ?',
                    ['UPDATE ' . $DB->get_prefix() . 'local_coursepilot_cm_file%']
                ) > 0,
                'Cleanup neither finished nor reached the shared file-row lock.'
            );
            $DB->get_field_sql('SELECT RELEASE_LOCK(?)', [$gate]);
            $results = [];
            foreach ($processes as $i => $process) {
                $output = stream_get_contents($pipes[$i][1]);
                $errors = stream_get_contents($pipes[$i][2]);
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                $exit = proc_close($process);
                $processes[$i] = null;
                $this->assertSame(0, $exit, $output . $errors);
                $results[$i] = $output;
            }
            $files = version_history::allowed_files((int) $results[0], 'page');
            $this->assertCount(1, $files, 'The new unexpired state must retain its design file after concurrent cleanup.');
            $this->assertSame('design.png', $files[0]->filename);
            $this->assertSame(sha1('synthetic design bytes'), $files[0]->contenthash);
        } finally {
            $DB->get_field_sql('SELECT RELEASE_LOCK(?)', [$gate]);
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }
    }

    /**
     * Provides await condition.
     *
     * @param callable $condition The condition.
     * @param string $message The message.
     */
    private function await_condition(callable $condition, string $message): void {
        $deadline = microtime(true) + 15;
        do {
            if ($condition()) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail($message);
    }
}
