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

/**
 * Finite site-wide and per-source budgets for anonymous OAuth endpoints (#642).
 *
 * Shared by dynamic client registration and first-time CIMD retrieval. Each
 * scope has fixed windows; a row exists per window for the site ('*') and per
 * source that passed the site budget, so state is bounded by the site limit and
 * expires with its window. Not a general rate-limit platform.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class oauth_budget {

    /** @var string Budget counters, one per scope/source/window. */
    private const TABLE = 'local_coursepilot_oauth_budget';

    /** @var string Source key of the site-wide counter. */
    private const SITE = '*';

    /**
     * Atomically consume one unit of both budgets. All callers of a scope
     * serialize on the site row, so the last allowed and the first rejected
     * request are exact under concurrency. A source rejection gives its site
     * unit back. Expired windows of every scope are purged first.
     *
     * ponytail: one site row per scope serializes all requests of that scope;
     * fine for anonymous registration traffic, shard it if throughput matters.
     *
     * @param string $scope Short endpoint name, e.g. 'register' or 'cimd'.
     * @param string $source Trusted request source, see {@see request_source()}.
     * @param int $sitelimit Allowed requests per window for the whole site.
     * @param int $sourcelimit Allowed requests per window for one source.
     * @param int $window Window length in seconds.
     * @return int 0 when allowed, otherwise seconds until the window ends.
     */
    public static function consume(string $scope, string $source, int $sitelimit, int $sourcelimit, int $window): int {
        global $DB;

        $now = time();
        $expires = intdiv($now, $window) * $window + $window;
        self::purge_expired($now);
        $siteid = self::site_row($scope, $expires);

        $transaction = $DB->start_delegated_transaction();
        try {
            $allowed = self::consume_locked($scope, $siteid, $source, $expires, $sitelimit, $sourcelimit);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }

        return $allowed ? 0 : max(1, $expires - $now);
    }

    /**
     * Budget decision while holding the site row lock. A site row purged by a
     * concurrent request at the window boundary counts as rejection, never as
     * an empty budget.
     *
     * @param string $scope
     * @param int $siteid
     * @param string $source
     * @param int $expires
     * @param int $sitelimit
     * @param int $sourcelimit
     * @return bool
     */
    private static function consume_locked(string $scope, int $siteid, string $source, int $expires,
            int $sitelimit, int $sourcelimit): bool {
        global $DB;

        $DB->execute('UPDATE {' . self::TABLE . '} SET hits = hits + 1 WHERE id = ?', [$siteid]);
        $sitehits = $DB->get_field(self::TABLE, 'hits', ['id' => $siteid]);
        if ($sitehits === false || (int) $sitehits > $sitelimit) {
            return false;
        }
        $key = hash_hmac('sha256', $source, get_site_identifier());
        $row = $DB->get_record(self::TABLE, ['scope' => $scope, 'sourcekey' => $key, 'expires' => $expires]);
        if (!$row) {
            $DB->insert_record(self::TABLE, (object) ['scope' => $scope, 'sourcekey' => $key,
                'expires' => $expires, 'hits' => 1]);
            return true;
        }
        if ((int) $row->hits < $sourcelimit) {
            $DB->set_field(self::TABLE, 'hits', (int) $row->hits + 1, ['id' => $row->id]);
            return true;
        }
        $DB->execute('UPDATE {' . self::TABLE . '} SET hits = hits - 1 WHERE id = ?', [$siteid]);
        return false;
    }

    /**
     * Delete counters whose window has ended. Called on every consume and by
     * the scheduled task {@see task\oauth_budget_cleanup}.
     *
     * @param int|null $now
     */
    public static function purge_expired(?int $now = null): void {
        global $DB;
        $params = [$now ?? time()];
        // A plain read first: an unconditional range DELETE would wait on the
        // locked site row of a concurrent request on every call.
        if ($DB->record_exists_select(self::TABLE, 'expires <= ?', $params)) {
            $DB->delete_records_select(self::TABLE, 'expires <= ?', $params);
        }
    }

    /**
     * Source identity from Moodle's trusted remote address: forwarded headers
     * only count where the administrator configured a reverse proxy. IPv6
     * addresses are reduced to their /64 network, the usual end-site unit.
     *
     * @return string
     */
    public static function request_source(): string {
        $address = getremoteaddr();
        $packed = str_contains($address, ':') ? @inet_pton($address) : false;
        if ($packed !== false && strlen($packed) === 16) {
            return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $address;
    }

    /**
     * Positive integer plugin setting: unset uses the finite default, zero or
     * negative values mean one - there is no unlimited option.
     *
     * @param string $name
     * @param int $default
     * @return int
     */
    public static function setting(string $name, int $default): int {
        $value = get_config('local_coursepilot', $name);
        if ($value === false || $value === '') {
            return $default;
        }
        return max(1, (int) $value);
    }

    /**
     * Id of the site counter of this window, created outside the transaction
     * so a concurrent duplicate insert cannot abort it.
     *
     * @param string $scope
     * @param int $expires
     * @return int
     */
    private static function site_row(string $scope, int $expires): int {
        global $DB;
        $conditions = ['scope' => $scope, 'sourcekey' => self::SITE, 'expires' => $expires];
        $id = $DB->get_field(self::TABLE, 'id', $conditions);
        if ($id) {
            return (int) $id;
        }
        try {
            return (int) $DB->insert_record(self::TABLE, (object) ($conditions + ['hits' => 0]));
        } catch (\dml_write_exception $e) {
            return (int) $DB->get_field(self::TABLE, 'id', $conditions, MUST_EXIST);
        }
    }
}
