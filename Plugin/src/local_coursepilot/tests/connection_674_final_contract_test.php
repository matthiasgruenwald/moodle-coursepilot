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
 * Transactions of the code exchange and the refresh rotation are closed on every path (#674).
 *
 * A regression that leaves a delegated transaction open is reported as a failed assertion: the open
 * transaction is rolled back inside the test before the assertion runs.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection::class)]
final class connection_674_final_contract_test extends \advanced_testcase {
    /** @var string Redirect URI registered for the test client. */
    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * Registers a client and returns its id with a PKCE pair.
     *
     * @return array{clientid: string, verifier: string, challenge: string}
     */
    private function client_with_pkce(): array {
        $response = oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Testclient',
            'redirect_uris' => [self::REDIRECT],
        ]), '192.0.2.1');
        $verifier = bin2hex(random_bytes(32));
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return ['clientid' => $response['body']['client_id'], 'verifier' => $verifier, 'challenge' => $challenge];
    }

    /**
     * Closes a transaction the code under test left open and reports whether there was one.
     *
     * @return bool Whether a transaction was still open.
     */
    private function left_transaction_open(): bool {
        global $DB;
        $open = $DB->is_transaction_started();
        if ($open) {
            $DB->force_transaction_rollback();
        }
        return $open;
    }

    /**
     * A code whose claim is lost to another claimant is refused and no transaction stays open.
     */
    public function test_a_lost_code_claim_closes_its_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL trigger fixture required.');
        }
        $fixture = $this->client_with_pkce();
        $user = $this->getDataGenerator()->create_user();
        $code = connection::issue_code($fixture['clientid'], (int) $user->id, self::REDIRECT, $fixture['challenge']);
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
        $trigger = 'cp674f' . bin2hex(random_bytes(8));
        $ddl->query("CREATE TRIGGER $trigger BEFORE UPDATE ON " . $DB->get_prefix() . 'local_coursepilot_oauth_code
            FOR EACH ROW SET NEW.code = OLD.code, NEW.used = OLD.used');
        try {
            $tokens = connection::exchange_code($code, $fixture['clientid'], self::REDIRECT, $fixture['verifier']);
            $open = $this->left_transaction_open();
        } finally {
            $ddl->query("DROP TRIGGER IF EXISTS $trigger");
            $ddl->close();
        }

        $this->assertNull($tokens);
        $this->assertFalse($open, 'The refused exchange must close its transaction.');
    }

    /**
     * A successful refresh rotation issues a new pair and leaves no transaction open.
     */
    public function test_a_successful_rotation_closes_its_transaction(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $fixture = $this->client_with_pkce();
        $user = $this->getDataGenerator()->create_user();
        $code = connection::issue_code($fixture['clientid'], (int) $user->id, self::REDIRECT, $fixture['challenge']);
        $tokens = connection::exchange_code($code, $fixture['clientid'], self::REDIRECT, $fixture['verifier']);
        $openafterexchange = $this->left_transaction_open();
        $this->assertFalse($openafterexchange, 'The exchange must close its transaction.');

        $rotated = connection::rotate_refresh_token($tokens['refresh_token'], $fixture['clientid']);
        $open = $this->left_transaction_open();

        $this->assertNotNull($rotated);
        $this->assertNotSame($tokens['refresh_token'], $rotated['refresh_token']);
        $this->assertFalse($open, 'The rotation must close its transaction.');
    }

    /**
     * A connection created after the lock statement of a mass revocation stays active and is not logged.
     */
    public function test_mass_revocation_leaves_a_connection_created_after_the_lock_untouched(): void {
        global $DB;
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $late = $this->getDataGenerator()->create_user();
        $this->issue_connection((int) $first->id);
        $lateid = 0;
        $realdb = $DB;
        $DB = new \local_coursepilot\tests\late_grant_database(
            $realdb,
            function (\moodle_database $db) use (&$lateid, $late): void {
                $lateid = $this->insert_grant($db, (int) $late->id);
            }
        );
        $sink = $this->redirectEvents();
        try {
            connection::revoke_all_tokens();
        } finally {
            $DB = $realdb;
        }

        $this->assertSame(0, (int) $DB->get_field('local_coursepilot_oauth_grant', 'revoked', ['id' => $lateid]));
        $objects = array_map(static fn($event) => (int) $event->relateduserid, $sink->get_events());
        $this->assertSame([(int) $first->id], $objects);
    }

    /**
     * A connection another session revoked after the lock statement is not logged a second time.
     */
    public function test_mass_revocation_does_not_log_a_connection_revoked_meanwhile(): void {
        global $DB;
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->issue_connection((int) $first->id);
        $this->issue_connection((int) $second->id);
        $realdb = $DB;
        $DB = new \local_coursepilot\tests\late_grant_database(
            $realdb,
            static function (\moodle_database $db) use ($second): void {
                $db->set_field('local_coursepilot_oauth_grant', 'revoked', 1, ['userid' => (int) $second->id]);
            }
        );
        $sink = $this->redirectEvents();
        try {
            connection::revoke_all_tokens();
        } finally {
            $DB = $realdb;
        }

        $owners = array_map(static fn($event) => (int) $event->relateduserid, $sink->get_events());
        $this->assertSame([(int) $first->id], $owners);
    }

    /**
     * Issues one connection with a token pair for a user.
     *
     * @param int $userid Consenting owner.
     */
    private function issue_connection(int $userid): void {
        $code = connection::issue_code(
            'client',
            $userid,
            'https://client.example/cb',
            'ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0'
        );
        $this->assertNotNull(connection::exchange_code($code, 'client', 'https://client.example/cb', 'abc'));
    }

    /**
     * Inserts an active grant as a concurrent session would.
     *
     * @param \moodle_database $db The real database.
     * @param int $userid Owner.
     * @return int Grant id.
     */
    private function insert_grant(\moodle_database $db, int $userid): int {
        return (int) $db->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $userid, 'clientid' => 'client', 'revoked' => 0,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => time(),
        ]);
    }
}
