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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Counters, retry hints, source keys and settings of the OAuth registration budget.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(oauth_budget::class)]
final class oauth_budget_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var string Counter table. */
    private const TABLE = 'local_coursepilot_oauth_budget';

    /**
     * Hit count of the counter of a source in a scope, or null without counter.
     *
     * @param string $scope
     * @param string $source
     * @return int|null
     */
    private function hits(string $scope, string $source): ?int {
        global $DB;
        $key = hash_hmac('sha256', $source, get_site_identifier());
        $hits = $DB->get_field(self::TABLE, 'hits', ['scope' => $scope, 'sourcekey' => $key]);
        return $hits === false ? null : (int) $hits;
    }

    /**
     * Hit count of the site counter of a scope, or null without counter.
     *
     * @param string $scope
     * @return int|null
     */
    private function sitehits(string $scope): ?int {
        global $DB;
        $hits = $DB->get_field(self::TABLE, 'hits', ['scope' => $scope, 'sourcekey' => '*']);
        return $hits === false ? null : (int) $hits;
    }

    public function test_every_allowed_request_adds_exactly_one_hit_to_both_counters(): void {
        global $DB;
        $this->resetAfterTest();

        $waits = [];
        for ($i = 0; $i < 3; $i++) {
            $waits[] = oauth_budget::consume('register', '192.0.2.1', 10, 5, 3600);
        }
        $this->assertSame([0, 0, 0], $waits);

        $this->assertSame(3, $this->hits('register', '192.0.2.1'));
        $this->assertSame(3, $this->sitehits('register'));
        $this->assertFalse($DB->is_transaction_started());
    }

    public function test_a_rejected_request_returns_its_site_unit_and_names_the_wait_in_whole_seconds(): void {
        $this->resetAfterTest();

        $this->assertSame(0, oauth_budget::consume('register', '192.0.2.1', 10, 1, 1));
        $wait = oauth_budget::consume('register', '192.0.2.1', 10, 1, 1);

        // A window of one second always has between zero and one second left; at least one second is reported.
        $this->assertSame(1, $wait);
        $this->assertSame(1, $this->hits('register', '192.0.2.1'));
        $this->assertSame(1, $this->sitehits('register'));
    }

    public function test_the_wait_never_exceeds_the_window_and_is_never_negative(): void {
        $this->resetAfterTest();
        oauth_budget::consume('register', '192.0.2.1', 1, 1, 3600);

        $before = time();
        $wait = oauth_budget::consume('register', '192.0.2.2', 1, 1, 3600);
        $after = time();

        $expires = intdiv($before, 3600) * 3600 + 3600;
        $this->assertGreaterThanOrEqual(max(1, $expires - $after), $wait);
        $this->assertLessThanOrEqual(max(1, $expires - $before), $wait);
    }

    public function test_scopes_have_separate_site_and_source_counters(): void {
        $this->resetAfterTest();
        $this->assertSame(0, oauth_budget::consume('alpha', '192.0.2.1', 1, 1, 3600));
        $this->assertGreaterThan(0, oauth_budget::consume('alpha', '192.0.2.1', 1, 1, 3600));

        $this->assertSame(0, oauth_budget::consume('beta', '192.0.2.1', 1, 1, 3600), 'Another scope starts empty.');
        $this->assertSame(2, $this->sitehits('alpha'), 'A site rejection keeps its counted attempt.');
        $this->assertSame(1, $this->sitehits('beta'));
        $this->assertSame(1, $this->hits('alpha', '192.0.2.1'));
        $this->assertSame(1, $this->hits('beta', '192.0.2.1'));
    }

    public function test_the_site_limit_rejects_other_sources_and_the_source_limit_only_its_own(): void {
        $this->resetAfterTest();

        $this->assertSame(0, oauth_budget::consume('register', 'a', 2, 1, 3600));
        $this->assertGreaterThan(0, oauth_budget::consume('register', 'a', 2, 1, 3600), 'Source limit.');
        $this->assertSame(0, oauth_budget::consume('register', 'b', 2, 1, 3600));
        $this->assertGreaterThan(0, oauth_budget::consume('register', 'c', 2, 1, 3600), 'Site limit.');

        $this->assertSame(3, $this->sitehits('register'));
        $this->assertNull($this->hits('register', 'c'));
    }

    public function test_purging_with_a_reference_time_removes_only_windows_that_ended_by_then(): void {
        global $DB;
        $this->resetAfterTest();
        $now = time();
        foreach ([100, $now + 5000] as $expires) {
            $DB->insert_record(
                self::TABLE,
                (object) ['scope' => 's', 'sourcekey' => "k$expires", 'expires' => $expires, 'hits' => 1]
            );
        }

        oauth_budget::purge_expired(50);
        $this->assertSame(2, $DB->count_records(self::TABLE), 'Nothing has ended at time 50.');

        oauth_budget::purge_expired(100);
        $this->assertSame(['k' . ($now + 5000)], array_values($DB->get_fieldset_select(self::TABLE, 'sourcekey', '1 = 1')));
    }

    /**
     * Provides remote addresses and their expected source keys.
     *
     * @return array<string, array{string, string}>
     */
    public static function remote_addresses(): array {
        return [
            'ipv4' => ['192.0.2.15', '192.0.2.15'],
            'ipv6 reduced to its /64' => ['2001:db8:aaaa:bbbb:cccc:dddd:eeee:ffff', '2001:db8:aaaa:bbbb::/64'],
            'ipv6 shortened' => ['2001:db8::1', '2001:db8::/64'],
            'ipv6 loopback' => ['::1', '::/64'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('remote_addresses')]
    public function test_request_source_identifies_ipv4_hosts_and_ipv6_networks(string $remote, string $expected): void {
        $this->resetAfterTest();
        $_SERVER['REMOTE_ADDR'] = $remote;

        $this->assertSame($expected, oauth_budget::request_source());
    }

    public function test_setting_uses_the_default_only_when_unset_and_never_goes_below_one(): void {
        $this->resetAfterTest();

        $this->assertEquals(7, oauth_budget::setting('budgetprobe', 7));
        $this->assertSame('integer', gettype(oauth_budget::setting('budgetprobe', 7)));
        set_config('budgetprobe', '', 'local_coursepilot');
        $this->assertSame(7, oauth_budget::setting('budgetprobe', 7));
        set_config('budgetprobe', '12', 'local_coursepilot');
        $this->assertSame(12, oauth_budget::setting('budgetprobe', 7));
        foreach (['0', '-4', 'abc'] as $stored) {
            set_config('budgetprobe', $stored, 'local_coursepilot');
            $value = null;
            $e = $this->caught(function () use (&$value) {
                $value = oauth_budget::setting('budgetprobe', 7);
            });
            $this->assertNull($e, $stored);
            $this->assertSame(1, $value, $stored);
        }
    }

    public function test_a_source_counter_changes_only_its_own_row(): void {
        $this->resetAfterTest();

        oauth_budget::consume('register', 'a', 10, 5, 3600);
        oauth_budget::consume('register', 'b', 10, 5, 3600);
        oauth_budget::consume('register', 'a', 10, 5, 3600);

        $this->assertSame(2, $this->hits('register', 'a'));
        $this->assertSame(1, $this->hits('register', 'b'));
        $this->assertSame(3, $this->sitehits('register'));
    }

    public function test_a_vanished_site_row_counts_as_rejection_and_adds_no_source_counter(): void {
        $this->resetAfterTest();
        $method = new \ReflectionMethod(oauth_budget::class, 'consume_locked');

        $allowed = $method->invoke(null, 'register', 987654, 'a', time() + 3600, 10, 10);

        $this->assertFalse($allowed);
        $this->assertNull($this->hits('register', 'a'));
    }

    public function test_a_failure_while_counting_is_passed_on_and_leaves_no_open_transaction(): void {
        global $DB;
        $this->resetAfterTest();
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL failure trigger fixture required.');
        }
        oauth_budget::consume('register', 'a', 10, 5, 3600);
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
        $key = 'cp674' . bin2hex(random_bytes(8));
        $ddl->query("CREATE TRIGGER $key BEFORE UPDATE ON " . $DB->get_prefix() . self::TABLE
            . ' FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Synthetic counting failure\'');
        try {
            $e = $this->caught(fn() => oauth_budget::consume('register', 'a', 10, 5, 3600));
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback(); // Clean up the deliberately broken red fixture.
            }
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }

        $this->assertInstanceOf(\dml_exception::class, $e);
    }
}
