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
 * Bounded background cleanup of OAuth state that no request can use any more (#644).
 *
 * Each step deletes at most one batch per run over an indexed selection; the
 * hourly task {@see task\oauth_cleanup} continues with the rest. Deletion
 * boundaries match the validity checks in {@see oauth_lib} and
 * {@see workbench_ticket} exactly, so no lifetime is shortened or extended.
 * Every token generation of a non-revoked connection with a live refresh
 * token stays, including consumed refresh hashes: they are the replay
 * evidence of #639.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class oauth_cleanup {
    /** @var int Rows per step and run; the next run continues. */
    public const BATCH = 500;

    /** @var int A client without active connection or pending code is removed this long after registration. */
    public const CLIENT_UNUSED_TTL = 30 * 24 * 3600;

    /**
     * Run every cleanup step once.
     *
     * @param int|null $now Reference time, default time().
     * @param int $batch Maximum rows per step.
     * @return int Number of deleted codes, tickets, connections, token rows and clients.
     */
    public static function run(?int $now = null, int $batch = self::BATCH): int {
        $now ??= time();
        oauth_budget::purge_expired($now);

        // Same boundaries as exchange_code() and workbench_ticket: valid while expires >= now.
        $deleted = self::delete_batch('local_coursepilot_oauth_code', 'expires < :now', ['now' => $now], $batch);
        $deleted += self::delete_batch(workbench_ticket::TABLE, 'expires < :now', ['now' => $now], $batch);
        $deleted += self::delete_dead_connections($now, $batch);
        // Unattributed historical rows: never usable, only revoked or expired ones exist after the #638 backfill.
        $deleted += self::delete_batch(
            'local_coursepilot_oauth_token',
            'connectionid IS NULL AND (revoked = 1 OR refreshexpires < :now)',
            ['now' => $now],
            $batch
        );
        return $deleted + self::delete_unused_clients($now, $batch);
    }

    /**
     * Delete revoked connections and connections whose newest refresh token
     * expired, together with all their generations. Neither can authenticate,
     * rotate or carry a ticket any more; tickets fail closed without the grant.
     *
     * ponytail: all generations of one connection go in one statement; their
     * number grows with the refreshes of that connection's lifetime.
     *
     * @param int $now
     * @param int $batch
     * @return int
     */
    private static function delete_dead_connections(int $now, int $batch): int {
        global $DB;

        // A refresh token is valid while refreshexpires >= now (rotate_refresh_token()).
        $ids = array_keys($DB->get_records_sql(
            'SELECT g.id FROM {local_coursepilot_oauth_grant} g
              WHERE g.revoked = 1
                 OR NOT EXISTS (SELECT 1 FROM {local_coursepilot_oauth_token} t
                                 WHERE t.connectionid = g.id AND t.refreshexpires >= :now)
           ORDER BY g.id',
            ['now' => $now],
            0,
            $batch
        ));
        if (!$ids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($ids);
        $tokens = $DB->count_records_select('local_coursepilot_oauth_token', "connectionid $insql", $params);
        // Tokens first: an interrupted run leaves a token-less grant, which the next run removes.
        $DB->delete_records_select('local_coursepilot_oauth_token', "connectionid $insql", $params);
        $DB->delete_records_select('local_coursepilot_oauth_grant', "id $insql", $params);
        return count($ids) + $tokens;
    }

    /**
     * Delete DCR and cached CIMD clients registered longer than
     * {@see CLIENT_UNUSED_TTL} ago that have no non-revoked connection of any
     * user and no unexpired authorization code. A removed CIMD client is
     * fetched again on its next use; a removed DCR client registers again.
     *
     * @param int $now
     * @param int $batch
     * @return int
     */
    private static function delete_unused_clients(int $now, int $batch): int {
        global $DB;

        // Deliberate shortcut - ponytail: the code check scans the code table, which only holds
        // codes of the last minutes after the code step; index clientid if that changes.
        $ids = array_keys($DB->get_records_sql(
            'SELECT c.id FROM {local_coursepilot_oauth_client} c
              WHERE c.timecreated < :horizon
                AND NOT EXISTS (SELECT 1 FROM {local_coursepilot_oauth_grant} g
                                 WHERE g.revoked = 0 AND g.clientid = c.clientid)
                AND NOT EXISTS (SELECT 1 FROM {local_coursepilot_oauth_code} o
                                 WHERE o.clientid = c.clientid AND o.expires >= :now)
           ORDER BY c.id',
            ['horizon' => $now - self::CLIENT_UNUSED_TTL, 'now' => $now],
            0,
            $batch
        ));
        if ($ids) {
            $DB->delete_records_list('local_coursepilot_oauth_client', 'id', $ids);
        }
        return count($ids);
    }

    /**
     * Delete at most $batch rows matching an indexed condition.
     *
     * @param string $table
     * @param string $where Fixed SQL from this class, never user input.
     * @param array $params
     * @param int $batch
     * @return int
     */
    private static function delete_batch(string $table, string $where, array $params, int $batch): int {
        global $DB;
        $ids = array_keys($DB->get_records_select($table, $where, $params, 'id', 'id', 0, $batch));
        if ($ids) {
            $DB->delete_records_list($table, 'id', $ids);
        }
        return count($ids);
    }
}
