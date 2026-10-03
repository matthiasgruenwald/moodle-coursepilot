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

/** Synthetic regressions at the existing OAuth and download boundaries (#638). */
#[\PHPUnit\Framework\Attributes\CoversClass(oauth_lib::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(workbench_ticket::class)]
final class oauth_connection_test extends \advanced_testcase {
    private function tokens(string $clientid, int $userid): array {
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = oauth_lib::issue_code($clientid, $userid, 'https://client.example/callback', $challenge);
        return oauth_lib::exchange_code($code, $clientid, 'https://client.example/callback', $verifier);
    }

    public function test_rotation_preserves_connection_and_consumed_refresh_hash(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $original = $this->tokens('client-a', (int) $user->id);
        oauth_lib::authenticate_access_token($original['access_token']);
        $connection = oauth_lib::current_connection_id();
        $this->assertNotNull($connection);
        $other = $this->tokens('client-a', (int) $user->id);
        oauth_lib::authenticate_access_token($other['access_token']);
        $this->assertNotSame($connection, oauth_lib::current_connection_id());
        $rotated = oauth_lib::rotate_refresh_token($original['refresh_token'], 'client-a');
        $this->assertSame((int) $user->id, oauth_lib::authenticate_access_token($rotated['access_token']));
        $this->assertSame($connection, oauth_lib::current_connection_id());
        $history = $DB->get_record('local_coursepilot_oauth_token', ['refreshtokenhash' => hash('sha256', $original['refresh_token'])]);
        $this->assertNotFalse($history);
        $this->assertSame($connection, (int) $history->connectionid);
        $this->assertNull(oauth_lib::rotate_refresh_token($original['refresh_token'], 'client-a'));
    }
    public function test_tickets_survive_rotation_and_end_with_their_connection(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $original = $this->tokens('client-a', (int) $USER->id);
        oauth_lib::authenticate_access_token($original['access_token']);
        $oldid = oauth_lib::current_token_id();
        [$directory, $filename] = material_files::resolve_file('synthetic.txt');
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id, 'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA, 'itemid' => material_files::ITEMID,
            'filepath' => $directory, 'filename' => $filename,
        ], 'synthetic bytes');
        $live = $this->ticket();
        $revoked = $this->ticket();
        $other = $this->tokens('client-a', (int) $USER->id);
        oauth_lib::authenticate_access_token($other['access_token']);
        $otherticket = $this->ticket();
        $rotated = oauth_lib::rotate_refresh_token($original['refresh_token'], 'client-a');
        $this->assertSame('synthetic bytes', workbench_ticket::redeem($live)['content']);
        $this->assertTrue(oauth_lib::revoke_token($oldid, (int) $USER->id), 'A stale management id must revoke its successor.');
        $this->assertNull(oauth_lib::authenticate_access_token($rotated['access_token']));
        $this->assertNull(oauth_lib::rotate_refresh_token($rotated['refresh_token'], 'client-a'));
        $this->assertNotNull(oauth_lib::authenticate_access_token($other['access_token']));
        $this->assertSame('synthetic bytes', workbench_ticket::redeem($otherticket)['content']);
        try {
            workbench_ticket::redeem($revoked);
            $this->fail('The revoked connection must invalidate its ticket.');
        } catch (workbench_ticket_redemption_failed $e) {
            $this->assertSame('workbenchticketconnectionrevoked', $e->errorcode);
        }
    }

    private function ticket(): string {
        parse_str(parse_url(workbench_ticket::issue('synthetic.txt')['url'], PHP_URL_QUERY), $query);
        return $query['ticket'];
    }

    /** Real processes overlap inside the connection transaction, in both orders. */
    #[\PHPUnit\Framework\Attributes\DataProvider('races')]
    public function test_parallel_connection_changes(string $first, string $second): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL named-lock fixture required.');
        }
        $user = $this->getDataGenerator()->create_user();
        $original = $this->tokens('client-a', (int) $user->id);
        oauth_lib::authenticate_access_token($original['access_token']);
        $tokenid = oauth_lib::current_token_id();
        $connectionid = oauth_lib::current_connection_id();
        $key = 'cp638' . bin2hex(random_bytes(8));
        $gate = $key . 'gate';
        $ready = $key . 'ready';
        $DB->get_field_sql('SELECT GET_LOCK(?, 10)', [$gate]);
        $cfg = $DB->export_dbconfig();
        // Moodle's execute() rejects trigger-body semicolons; use the native DDL connection.
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli($cfg->dbhost, $cfg->dbuser, $cfg->dbpass, $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null);
        if ($first === 'rotate') {
            $table = $DB->get_prefix() . 'local_coursepilot_oauth_token';
            $event = 'INSERT';
            $condition = "NEW.connectionid = $connectionid";
        } else {
            $table = $DB->get_prefix() . 'local_coursepilot_oauth_grant';
            $event = 'UPDATE';
            $condition = "NEW.id = $connectionid AND NEW.revoked = 1";
        }
        $ddl->query("CREATE TRIGGER $key BEFORE $event ON $table FOR EACH ROW BEGIN
            IF $condition THEN
                SET @cp_ready = GET_LOCK('$ready', 10);
                SET @cp_gate = GET_LOCK('$gate', 30);
            END IF;
        END");
        $processes = [];
        $pipes = [];
        try {
            $processes[0] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/oauth_connection_process.php',
                $first, $original['refresh_token'], (string) $tokenid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[0]);
            $this->await_condition(fn() => $DB->get_field_sql('SELECT IS_USED_LOCK(?)', [$ready]), 'First connection did not reach barrier');
            $processes[1] = proc_open([PHP_BINARY, __DIR__ . '/fixtures/oauth_connection_process.php',
                $second, $original['refresh_token'], (string) $tokenid], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[1]);
            $this->await_condition(fn() => (int) $DB->get_field_sql(
                'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE INFO LIKE :query',
                ['query' => 'UPDATE ' . $DB->get_prefix() . 'local_coursepilot_oauth_grant%']) > 0,
                'Second connection did not wait on the shared grant row');
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
                $results[$i] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            }
            if ($second === 'rotate' && $first === 'rotate') {
                $this->assertNotNull($results[0]);
                $this->assertNull($results[1]);
                $this->assertNotNull(oauth_lib::authenticate_access_token($results[0]['access_token']));
            } else {
                $this->assertTrue($results[$first === 'revoke' ? 0 : 1]);
                $this->assertFalse(oauth_lib::grant_active($connectionid, (int) $user->id));
                $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_token', ['connectionid' => $connectionid, 'revoked' => 0]));
                $successor = $results[$first === 'rotate' ? 0 : 1];
                if ($successor !== null) {
                    $this->assertNull(oauth_lib::authenticate_access_token($successor['access_token']));
                    $this->assertNull(oauth_lib::rotate_refresh_token($successor['refresh_token'], 'client-a'));
                }
            }
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

    public static function races(): array {
        return [['rotate', 'revoke'], ['revoke', 'rotate'], ['rotate', 'rotate']];
    }
}
