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

namespace local_coursepilot\tests;

/**
 * Two real processes contend for the same anonymous OAuth site budget row
 * behind a DB barrier (#642, #643): a trigger holds the first site-row update
 * until the second process waits on that row, then both continue.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
trait oauth_budget_race {
    /**
     * Run the fixture process once per argument list, the second while the
     * first holds the site budget barrier.
     *
     * @param array $first Arguments of oauth_registration_process.php for the first process.
     * @param array $second Arguments for the second process.
     * @return int[] Sorted HTTP statuses printed by both processes.
     */
    protected function race_on_site_budget(array $first, array $second): array {
        global $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL named-lock fixture required.');
        }
        $key = 'cpbudget' . bin2hex(random_bytes(8));
        $gate = $key . 'gate';
        $ready = $key . 'ready';
        $DB->get_field_sql('SELECT GET_LOCK(?, 10)', [$gate]);
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
        $table = $DB->get_prefix() . 'local_coursepilot_oauth_budget';
        $ddl->query("CREATE TRIGGER $key BEFORE UPDATE ON $table FOR EACH ROW BEGIN
            IF NEW.sourcekey = '*' AND IS_FREE_LOCK('$ready') = 1 AND @cp_done IS NULL THEN
                SET @cp_done = 1;
                SET @cp_ready = GET_LOCK('$ready', 10);
                SET @cp_gate = GET_LOCK('$gate', 30);
            END IF;
        END");
        $fixture = __DIR__ . '/../fixtures/oauth_registration_process.php';
        $processes = [];
        $pipes = [];
        try {
            $processes[0] = proc_open(
                array_merge([PHP_BINARY, $fixture], $first),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[0]
            );
            $this->await(fn() => $DB->get_field_sql('SELECT IS_USED_LOCK(?)', [$ready]), 'First request did not reach barrier');
            $processes[1] = proc_open(
                array_merge([PHP_BINARY, $fixture], $second),
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes[1]
            );
            $this->await(
                fn() => (int) $DB->get_field_sql(
                    'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE :query AND ID <> IS_USED_LOCK(:ready)',
                    ['query' => 'UPDATE ' . $table . '%', 'ready' => $ready]
                ) > 0,
                'Second request did not wait on the shared site budget row'
            );
            $DB->get_field_sql('SELECT RELEASE_LOCK(?)', [$gate]);
            // Rows written by the child processes are invisible to this
            // process' reset tracking; mark the tables for resetAfterTest.
            foreach (['local_coursepilot_oauth_budget', 'local_coursepilot_oauth_client'] as $written) {
                \phpunit_util::set_table_modified_by_sql('UPDATE ' . $DB->get_prefix() . $written);
            }
            $statuses = [];
            foreach ($processes as $i => $process) {
                $output = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
                fclose($pipes[$i][1]);
                fclose($pipes[$i][2]);
                $this->assertSame(0, proc_close($process), $output);
                $processes[$i] = null;
                $statuses[] = (int) $output;
            }
            sort($statuses);
            return $statuses;
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
     * Poll until $condition holds or fail with the current process list.
     *
     * @param callable $condition
     * @param string $message
     */
    private function await(callable $condition, string $message): void {
        global $DB;
        $deadline = microtime(true) + 15;
        do {
            if ($condition()) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail($message . ' ' . json_encode($DB->get_records_sql(
            'SELECT ID, STATE, INFO FROM information_schema.PROCESSLIST WHERE INFO IS NOT NULL'
        )));
    }
}
