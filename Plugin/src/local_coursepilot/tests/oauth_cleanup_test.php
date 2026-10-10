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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Bounded background cleanup of expired OAuth state with synthetic, aged data (#644).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(oauth_cleanup::class)]
#[CoversClass(task\oauth_cleanup::class)]
final class oauth_cleanup_test extends \advanced_testcase {
    /**
     * Code.
     */
    private const CODE = 'local_coursepilot_oauth_code';
    /**
     * Token.
     */
    private const TOKEN = 'local_coursepilot_oauth_token';
    /**
     * Grant.
     */
    private const GRANT = 'local_coursepilot_oauth_grant';
    /**
     * Client.
     */
    private const CLIENT = 'local_coursepilot_oauth_client';
    /**
     * Callback.
     */
    private const CALLBACK = 'https://client.example/callback';

    /**
     * Provides tokens.
     *
     * @param string $clientid The clientid.
     * @param int $userid The userid.
     * @return array
     */
    private function tokens(string $clientid, int $userid): array {
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = oauth_lib::issue_code($clientid, $userid, self::CALLBACK, $challenge);
        return oauth_lib::exchange_code($code, $clientid, self::CALLBACK, $verifier);
    }

    /**
     * Provides connection.
     *
     * @param array $pair The pair.
     * @return int
     */
    private function connection(array $pair): int {
        oauth_lib::authenticate_access_token($pair['access_token']);
        return oauth_lib::current_connection_id();
    }

    /**
     * Provides code.
     *
     * @param string $clientid The clientid.
     * @param int $expires The expires.
     * @param int $used The used.
     * @return int
     */
    private function code(string $clientid, int $expires, int $used = 0): int {
        global $DB;
        return $DB->insert_record(self::CODE, (object) ['code' => oauth_lib::random_token(16),
            'clientid' => $clientid, 'userid' => 2, 'redirecturi' => self::CALLBACK,
            'codechallenge' => 'c', 'expires' => $expires, 'used' => $used]);
    }

    /**
     * Provides client.
     *
     * @param string $clientid The clientid.
     * @param int $timecreated The timecreated.
     * @param string $source The source.
     */
    private function client(string $clientid, int $timecreated, string $source = 'dcr'): void {
        global $DB;
        $DB->insert_record(self::CLIENT, (object) ['clientid' => $clientid, 'redirecturis' => json_encode([self::CALLBACK]),
            'tokenendpointauthmethod' => 'none', 'source' => $source, 'timecreated' => $timecreated]);
    }

    public function test_expired_codes_and_tickets_go_at_the_boundary_in_resumable_batches(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $expired = [$this->code('c', $now - 1), $this->code('c', $now - 500, 1), $this->code('c', $now - 2)];
        $boundary = $this->code('c', $now);
        $usedlive = $this->code('c', $now + 60, 1);
        foreach ([$now - 1, $now] as $i => $expires) {
            $DB->insert_record(workbench_ticket::TABLE, (object) ['userid' => 2, 'path' => "t$i.txt",
                'contenthash' => sha1("t$i"), 'tickethash' => hash('sha256', "t$i"), 'expires' => $expires,
                'timecreated' => $now - 900]);
        }

        $this->assertSame(3, oauth_cleanup::run($now, 2), 'Two codes and one ticket in the first bounded pass.');
        $this->assertSame(1, $DB->count_records_select(self::CODE, 'expires < ?', [$now]), 'Batch leaves the rest.');
        $this->assertSame(1, oauth_cleanup::run($now, 2), 'The next pass continues where the last stopped.');
        $this->assertSame(0, oauth_cleanup::run($now, 2), 'Repetition is a no-op.');

        foreach ($expired as $id) {
            $this->assertFalse($DB->record_exists(self::CODE, ['id' => $id]));
        }
        $this->assertTrue($DB->record_exists(self::CODE, ['id' => $boundary]), 'A code expiring now is still valid.');
        $this->assertTrue($DB->record_exists(self::CODE, ['id' => $usedlive]));
        $this->assertSame([$now], array_map('intval', $DB->get_fieldset_select(workbench_ticket::TABLE, 'expires', '1 = 1')));
    }

    public function test_dead_connections_go_while_active_replay_evidence_and_tickets_stay(): void {
        global $DB, $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $original = $this->tokens('client-a', (int) $USER->id);
        $active = $this->connection($original);
        $rotated = oauth_lib::rotate_refresh_token($original['refresh_token'], 'client-a');
        [$directory, $filename] = material_files::resolve_file('synthetic.txt');
        get_file_storage()->create_file_from_string(['contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT, 'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID, 'filepath' => $directory, 'filename' => $filename], 'synthetic bytes');
        $this->connection($rotated);
        parse_str(parse_url(workbench_ticket::issue('synthetic.txt')['url'], PHP_URL_QUERY), $query);

        $revoked = $this->connection($this->tokens('client-a', (int) $USER->id));
        oauth_lib::revoke_token(oauth_lib::current_token_id());
        $stale = $this->tokens('client-a', (int) $USER->id);
        $expired = $this->connection($stale);
        $staletokens = $DB->get_records(self::TOKEN, ['connectionid' => $expired]);
        $DB->set_field(self::TOKEN, 'expires', time() - 2, ['connectionid' => $expired]);
        $DB->set_field(self::TOKEN, 'refreshexpires', time() - 1, ['connectionid' => $expired]);
        $legacy = $DB->insert_record(self::TOKEN, (object) ['accesstokenhash' => hash('sha256', 'legacy-a'),
            'refreshtokenhash' => hash('sha256', 'legacy-r'), 'clientid' => 'client-a', 'userid' => $USER->id,
            'expires' => time() + 60, 'refreshexpires' => time() + 60, 'revoked' => 1, 'timecreated' => time() - 99]);
        $activebefore = $DB->get_records(self::TOKEN, ['connectionid' => $active], 'id');

        oauth_cleanup::run(time());

        $this->assertEquals(
            $activebefore,
            $DB->get_records(self::TOKEN, ['connectionid' => $active], 'id'),
            'Every generation of an active connection, including the consumed hash, stays unchanged.'
        );
        $this->assertCount(2, $activebefore);
        $this->assertTrue($DB->record_exists(self::GRANT, ['id' => $active, 'revoked' => 0]));
        foreach ([$revoked, $expired] as $dead) {
            $this->assertFalse($DB->record_exists(self::GRANT, ['id' => $dead]));
            $this->assertFalse($DB->record_exists(self::TOKEN, ['connectionid' => $dead]));
        }
        $this->assertCount(1, $staletokens);
        $this->assertFalse($DB->record_exists(self::TOKEN, ['id' => $legacy]));

        // The cleaned system keeps working: ticket, renewal, then replay revokes the whole connection.
        $this->assertSame('synthetic bytes', workbench_ticket::redeem($query['ticket'])['content']);
        $renewed = oauth_lib::rotate_refresh_token($rotated['refresh_token'], 'client-a');
        $this->assertNotNull($renewed);
        oauth_cleanup::run(time());
        $this->assertNull(oauth_lib::rotate_refresh_token($original['refresh_token'], 'client-a'));
        $this->assertNull(oauth_lib::authenticate_access_token($renewed['access_token']));
        $this->assertTrue($DB->record_exists(self::GRANT, ['id' => $active, 'revoked' => 1]));
    }

    public function test_only_stale_unused_clients_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        $old = $now - oauth_cleanup::CLIENT_UNUSED_TTL - 1;
        $this->client('unused', $old);
        $this->client('https://cimd.example/unused', $old, 'cimd');
        $this->client('boundary', $now - oauth_cleanup::CLIENT_UNUSED_TTL);
        $this->client('recent', $now - 60);
        $this->client('foreign-active', $old);
        $this->client('pending-code', $old);
        $this->client('revoked-only', $old);
        $other = $this->getDataGenerator()->create_user();
        $this->tokens('foreign-active', (int) $other->id);
        $this->code('pending-code', $now + 60);
        $this->connection($this->tokens('revoked-only', (int) $other->id));
        oauth_lib::revoke_token(oauth_lib::current_token_id());
        // Redeemed codes of the connections must not be what protects a client.
        $DB->set_field_select(self::CODE, 'expires', $now - 1, "clientid <> 'pending-code'");

        oauth_cleanup::run($now);

        $kept = $DB->get_fieldset_select(self::CLIENT, 'clientid', '1 = 1');
        sort($kept);
        $this->assertSame(['boundary', 'foreign-active', 'pending-code', 'recent'], $kept);
    }

    public function test_task_is_registered_and_purges_budget_and_codes(): void {
        global $DB;
        $this->resetAfterTest();
        $DB->insert_record('local_coursepilot_oauth_budget', (object) ['scope' => 'cimdfail', 'sourcekey' => 'stale',
            'expires' => time(), 'hits' => 1]);
        $this->code('c', time() - 1);

        (new task\oauth_cleanup())->execute();

        $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_budget'));
        $this->assertSame(0, $DB->count_records(self::CODE));
        $this->assertTrue((bool) \core\task\manager::get_scheduled_task(task\oauth_cleanup::class));
        $this->assertFalse(\core\task\manager::get_scheduled_task('local_coursepilot\task\oauth_budget_cleanup'));
    }

    public function test_cleanup_indexes_are_installed_and_added_idempotently_on_upgrade(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/coursepilot/db/upgradelib.php');
        $this->resetAfterTest();
        $dbman = $DB->get_manager();
        $indexes = local_coursepilot_oauth_cleanup_indexes();
        foreach ($indexes as $table => $index) {
            $this->assertTrue($dbman->index_exists(new \xmldb_table($table), $index), "$table fresh install");
            $dbman->drop_index(new \xmldb_table($table), $index);
        }

        local_coursepilot_add_oauth_cleanup_indexes($dbman);
        local_coursepilot_add_oauth_cleanup_indexes($dbman);

        foreach ($indexes as $table => $index) {
            $this->assertTrue($dbman->index_exists(new \xmldb_table($table), $index), "$table upgrade");
        }
    }
}
