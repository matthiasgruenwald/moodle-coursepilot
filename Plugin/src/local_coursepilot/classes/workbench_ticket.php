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
 * One-time download ticket for a workbench file (issue #501, spec #486 §13):
 * a client with a shell (curl) fetches a workbench file as original bytes via
 * a dedicated, unauthenticated endpoint ({@see \local_coursepilot\workbench_ticket}
 * is the decision logic behind it, `workbench/download.php` the thin
 * shell) - the ticket itself is the proof of authorization, no OAuth bearer
 * header needed.
 *
 * Bound to person, path and `contenthash`, opens only the workbench (every
 * path goes through {@see material_files::resolve_file()}, which always
 * resolves the workbench and already rejects a breakout via "."/".."). Valid
 * once from the first redemption - the row is claimed atomically via
 * compare-and-swap on lookup ({@see claim()}, #512), regardless of whether
 * the subsequent checks pass: two simultaneous redemptions of the same
 * ticket deliver the file at most once, even if both requests arrive at
 * exactly the same moment. Valid for a fixed 15 minutes, without range
 * support (the endpoint implements this and never honors a Range header).
 *
 * Only the hash of the ticket is stored ({@see issue()}/{@see redeem()}),
 * never the secret itself. Expired rows are removed opportunistically on the
 * next issue ({@see purge_expired()}) - no dedicated scheduled task for a
 * single, small table.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class workbench_ticket {

    /** @var string DB table of the issued tickets. */
    public const TABLE = 'local_coursepilot_workbench_ticket';

    /** @var int Validity period in seconds - spec #486 §13: "fixed 15 minutes". */
    public const TTL_SECONDS = 900;

    /**
     * Issues a ticket for a workbench file of the signed-in person.
     *
     * @param string $path Path relative to the workbench root, e.g. "blatt.pdf".
     * @return array{path: string, name: string, size: int, sha1: string, url: string}
     * @throws \moodle_exception invalidmaterialpath (path outside the
     *         workbench), materialfilenotfound (file missing)
     */
    public static function issue(string $path): array {
        global $USER, $CFG, $DB;

        [$directory, $filename] = material_files::resolve_file($path);
        $info = material_files::read_content($directory, $filename);
        if ($info === null) {
            throw new \moodle_exception(
                'materialfilenotfound',
                'local_coursepilot',
                '',
                material_files::relative_file($directory, $filename)
            );
        }

        self::purge_expired();

        $secret = oauth_lib::random_token(32);
        $relativepath = material_files::relative_file($directory, $filename);

        $record = new \stdClass();
        $record->userid = (int) $USER->id;
        $record->path = $relativepath;
        $record->contenthash = $info['contenthash'];
        $record->tickethash = hash('sha256', $secret);
        $record->oauthconnectionid = oauth_lib::current_connection_id();
        $record->expires = time() + self::TTL_SECONDS;
        $record->timecreated = time();
        $DB->insert_record(self::TABLE, $record);

        return [
            'path' => $relativepath,
            'name' => $filename,
            'size' => $info['size'],
            'sha1' => $info['contenthash'],
            'url' => $CFG->wwwroot . '/local/coursepilot/workbench/download.php?ticket=' . $secret,
        ];
    }

    /**
     * Redeems a ticket - the only way to read the bytes behind it. Checks in
     * this order: emergency brake (before any database access, so a globally
     * locked instance consumes no ticket at all), ticket known (and consumes
     * it immediately - from here on it is gone, regardless of the outcome of
     * the following checks), expiry, existence of the issuing connection,
     * active account, remote access grant, unchanged
     * `contenthash`.
     *
     * @param string $secret The ticket secret from the URL.
     * @return array{userid: int, path: string, filename: string, mimetype: string,
     *         content: string, size: int}
     * @throws workbench_ticket_redemption_failed remoteaccessdisabled, workbenchticketinvalid,
     *         workbenchticketexpired, workbenchticketconnectionrevoked,
     *         workbenchticketaccountinactive, remoteaccessnotgranted, workbenchticketcontentchanged
     */
    public static function redeem(string $secret): array {
        global $USER;

        if ((string) get_config('local_coursepilot', 'remoteaccessenabled') === '0') {
            throw new workbench_ticket_redemption_failed('remoteaccessdisabled', null);
        }

        $ticket = self::claim(hash('sha256', $secret));
        if (!$ticket) {
            // No path known - either the ticket was never issued, or a
            // concurrent redemption already snatched it from under us via
            // {@see claim()}. Indistinguishable from this request's point of
            // view, and that is intended (no timing channel).
            throw new workbench_ticket_redemption_failed('workbenchticketinvalid', null);
        }

        self::assert_still_valid($ticket);

        $requestuser = $USER;
        try {
            // Storage locations belong to the validated owner, including anonymous downloads.
            $USER = (object) ['id' => (int) $ticket->userid];
            [$directory, $filename] = material_files::resolve_file($ticket->path);
        } finally {
            $USER = $requestuser;
        }
        $file = self::resolve_ticket_file($ticket, $directory, $filename);

        return [
            'userid' => (int) $ticket->userid,
            'path' => $ticket->path,
            'filename' => $filename,
            'mimetype' => (string) ($file->get_mimetype() ?: 'application/octet-stream'),
            'content' => $file->get_content(),
            'size' => (int) $file->get_filesize(),
        ];
    }

    /**
     * Checks expiry, connection and account of the ticket owner (issue #523:
     * extracted from redeem() to keep the function under the 50-line limit).
     *
     * @param \stdClass $ticket
     * @throws workbench_ticket_redemption_failed
     */
    private static function assert_still_valid(\stdClass $ticket): void {
        global $DB;

        if ((int) $ticket->expires < time()) {
            throw new workbench_ticket_redemption_failed('workbenchticketexpired', $ticket->path);
        }

        // Never stronger than its connection (spec #486 §13): a ticket with
        // a known issuing connection needs it to still exist.
        // A ticket WITHOUT a known connection (#512: e.g. because the
        // issuing request never ran through the OAuth dispatcher) is
        // therefore not automatically stronger - it requires, as a substitute,
        // any still existing connection of the person. It can still be issued
        // (no additional issuance check needed),
        // but it survives a bulk revocation (#338) just as little as
        // a ticket with a known connection.
        $hasconnection = $ticket->oauthconnectionid !== null
            ? oauth_lib::grant_active((int) $ticket->oauthconnectionid, (int) $ticket->userid)
            : ($ticket->oauthtokenid !== null
            ? oauth_lib::connection_active((int) $ticket->oauthtokenid, (int) $ticket->userid)
            : oauth_lib::has_active_connection((int) $ticket->userid));
        if (!$hasconnection) {
            throw new workbench_ticket_redemption_failed('workbenchticketconnectionrevoked', $ticket->path);
        }

        $user = $DB->get_record('user', ['id' => (int) $ticket->userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user) {
            throw new workbench_ticket_redemption_failed('workbenchticketaccountinactive', $ticket->path);
        }

        // The anonymous download request is not the permission identity.
        // A ticket never outlives its owner's remote access grant (ADR 0026).
        if (!remote_access::is_granted((int) $ticket->userid)) {
            throw new workbench_ticket_redemption_failed('remoteaccessnotgranted', $ticket->path);
        }
    }

    /**
     * Resolves the workbench file and checks the contenthash (issue #523:
     * extracted from redeem()).
     * @param \stdClass $ticket
     * @param string $directory
     * @param string $filename
     * @return \stored_file
     * @throws workbench_ticket_redemption_failed
     */
    private static function resolve_ticket_file(\stdClass $ticket, string $directory, string $filename): \stored_file {
        $contextid = \context_user::instance((int) $ticket->userid)->id;
        $file = get_file_storage()->get_file(
            $contextid,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            $directory,
            $filename
        );
        if (!$file || $file->is_directory() || $file->get_contenthash() !== $ticket->contenthash) {
            throw new workbench_ticket_redemption_failed('workbenchticketcontentchanged', $ticket->path);
        }

        return $file;
    }

    /**
     * Atomically claims the ticket row for the given ticket hash and returns
     * it - or null if none exists (any more) (#512).
     *
     * Previously this was a SELECT by `tickethash`, followed by a DELETE by
     * `id`: two separate statements with a gap in between. Two simultaneous
     * redemptions of the same ticket could both pass the SELECT before either
     * ran the DELETE - both would have delivered the file. This method
     * replaces that with a single atomic UPDATE statement with the old ticket
     * hash in the WHERE clause (compare-and-swap): the database locks the
     * affected row for the duration of the statement, a simultaneous second
     * UPDATE with the same WHERE condition then sees the already changed value
     * and matches no row. This holds for every SQL database with row-level
     * locking on UPDATE (MySQL/InnoDB, PostgreSQL) and needs no return of the
     * number of affected rows, which Moodle's DB abstraction does not offer:
     * success shows in whether the row can afterwards be found under the own
     * claim marker, freshly generated in this process - no other process
     * knows it.
     *
     * @param string $tickethash SHA-256 hash of the ticket secret.
     * @return \stdClass|null Claimed row, or null.
     */
    private static function claim(string $tickethash): ?\stdClass {
        global $DB;

        $claim = hash('sha256', $tickethash . '|' . random_string(20));
        $DB->set_field_select(self::TABLE, 'tickethash', $claim, 'tickethash = :hash', ['hash' => $tickethash]);

        $ticket = $DB->get_record(self::TABLE, ['tickethash' => $claim]);
        if (!$ticket) {
            return null;
        }
        // Claimed, gone from here on - regardless of the outcome of the
        // following checks (expiry, connection, account, contenthash).
        $DB->delete_records(self::TABLE, ['id' => $ticket->id]);
        return $ticket;
    }

    /**
     * Removes expired ticket rows - opportunistically on every issue,
     * instead of via a dedicated scheduled task (spec #486 §13:
     * "expired tickets do not stay around permanently").
     *
     * @return void
     */
    private static function purge_expired(): void {
        global $DB;

        $DB->delete_records_select(self::TABLE, 'expires < :now', ['now' => time()]);
    }
}
