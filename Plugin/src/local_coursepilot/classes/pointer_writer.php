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

use local_coursepilot\webdav\webdav_error;

/**
 * Pending-note translation for external location failures (Issue #492, ADR
 * 0023): the WebDAV failure vocabulary and the five-part failure answer.
 * Writing itself runs through {@see webdav_storage_port} (Issue #645); this
 * class only records failures that {@see context_area} observes before the
 * adapter is reached (pointer resolution, preflight read).
 *
 * A conflict is a caller error and never creates a pending note (ADR 0023
 * point 2). Every other failure at storage, connection or location records
 * an entry in the pending note before the error is returned.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_writer {
    /**
     * @var string Create operation in the pending-note vocabulary (ADR 0023).
     *      Public for write-endpoint personal-data preflight checks
     *      using {@see record_preread_failure()} (Issue #505 finding #10).
     */
    public const OP_CREATE = pending_write_translation::OP_CREATE;

    /** @var string Append operation. */
    public const OP_APPEND = pending_write_translation::OP_APPEND;

    /**
     * @var string Unknown operation (Issue #561); see
     *      {@see pending_write_translation::OP_UNKNOWN}.
     */
    public const OP_UNKNOWN = pending_write_translation::OP_UNKNOWN;

    /**
     * @var string[] moodle_exception codes that record pending writes like webdav_error
     *      (location failures under ADR 0023). The first five come from
     *      webdav_instance::resolve(); `contextrootmissing` comes from
     *      webdav_storage_port (Issue #514). Other codes pass through unchanged
     *      as programming errors.
     */
    private const LOCATION_FAILURE_CODES = [
        'webdavinstancemissing',
        'webdavinstanceforeign',
        'webdavnotenabled',
        'webdavauthunsupported',
        'webdavfingerprintchanged',
        'contextrootmissing',
        // Check 8 (Issue #516, Spec #486 §2/§8) records pending writes, as do checks 2-6.
        // Checks 1 (incomplete pointer) and 7 (nesting) are caller errors, not storage failures.
        'webdaviservfilesonly',
    ];

    /**
     * @var string[] Temporary error classes that may clear without teacher intervention.
     *      Part 2 of the failure response (Issue #516, Spec #486 §8); all
     *      other classes require action on the storage.
     */
    private const LATER_CLASSES = [
        webdav_error::UNCLEAR,
        webdav_error::UNREACHABLE,
    ];

    /**
     * @var array<string, string> Error class/code to teacher-facing reason, part 2 of the
     *      five-part failure response (Issue #492).
     */
    private const REASONS = [
        // External Nextcloud rate limiting is expected; use calm wording.
        webdav_error::UNCLEAR => 'the storage is briefly throttling requests (normal on some Nextcloud instances)',
        webdav_error::NOT_FOUND => 'the target folder cannot be reached there',
        webdav_error::AUTH_REJECTED => 'the login to the storage was rejected',
        webdav_error::UNREACHABLE => 'the storage is currently unreachable',
        webdav_error::STORAGE_FULL => 'the storage is full',
        webdav_error::BLOCKED => 'access to the storage is blocked',
        webdav_error::REDIRECTED => 'the storage redirected to a different address',
        'webdavinstancemissing' => 'the connection no longer exists',
        'webdavinstanceforeign' => 'the connection no longer belongs to you',
        'webdavnotenabled' => 'external storage is no longer enabled for you',
        'webdavauthunsupported' => 'the connection uses a login method that is no longer supported',
        'webdavfingerprintchanged' => 'server, path or account of the connection have changed',
        'contextrootmissing' => 'the selected context area no longer exists there (moved, deleted'
            . ' or renamed) — please choose again on the location selection page',
        'webdaviservfilesonly' => 'the selected path is outside "Files/" on IServ',
    ];

    /**
     * Handle a preflight read failure like a write failure (Issue #505 finding
     * #10): the same pending note and five-part response. The personal-data
     * preflight checks in write_context_file and append_context_file call this
     * when GET fails at the connection or location before the actual write.
     *
     * Abort before attempting PUT. Continuing after a failed GET could overwrite
     * a marked target without checking it when PUT happens to succeed, violating
     * the preflight-read rule in Issue #515.
     *
     * @param webdav_error $e
     * @param pointer_location $location
     * @param string $clientpath
     * @param string $operation One of the OP_* constants.
     * @param int $courseid Course ID for the pending note, or 0 without a course.
     * @return \moodle_exception
     */
    public static function record_preread_failure(
        webdav_error $e,
        pointer_location $location,
        string $clientpath,
        string $operation,
        int $courseid
    ): \moodle_exception {
        return self::translate_or_record($e, $clientpath, $location, $operation, $courseid);
    }

    /**
     * A conflict returns the existing merge instruction and creates no pending
     * note (ADR 0023 point 2: caller error, not storage failure). Every other
     * failure records a pending write through {@see fail()}.
     *
     * @param webdav_error $e The e.
     * @param string $clientpath The clientpath.
     * @param pointer_location $location The location.
     * @param string $operation The operation.
     * @param int $courseid The courseid.
     */
    private static function translate_or_record(
        webdav_error $e,
        string $clientpath,
        pointer_location $location,
        string $operation,
        int $courseid
    ): \moodle_exception {
        if ($e->errorclass === webdav_error::CONFLICT) {
            return new \moodle_exception('contextfileexternalconflict', 'local_coursepilot', '', $clientpath);
        }
        return self::fail(
            $e->errorclass,
            $e->getMessage(),
            $clientpath,
            $operation,
            (string) ($location->fingerprint['server'] ?? ''),
            $location->instanceid,
            $courseid
        );
    }

    /**
     * Record pending writes for location failures; pass other moodle_exception
     * codes through unchanged. Codes from webdav_instance::resolve() (checks
     * 2-6 plus contextrootmissing, Issue #514) have a resolved $location.
     * webdaviservfilesonly arises during pointer resolution (check 8), so its
     * exception payload supplies the host and instance ID (Issue #516).
     *
     * Public for context_area to report IServ resolution failures (Issue #541).
     *
     * @param \moodle_exception $e
     * @param string $clientpath
     * @param pointer_location|null $location null for `webdaviservfilesonly`.
     * @param string $operation One of the OP_* constants.
     * @param int $courseid Course ID for the pending note, or 0 without a course.
     * @return \moodle_exception
     */
    public static function record_location_failure(
        \moodle_exception $e,
        string $clientpath,
        ?pointer_location $location,
        string $operation,
        int $courseid
    ): \moodle_exception {
        if (!in_array($e->errorcode, self::LOCATION_FAILURE_CODES, true)) {
            return $e;
        }
        if ($location !== null) {
            $host = (string) ($location->fingerprint['server'] ?? '');
            $instanceid = $location->instanceid;
        } else {
            $host = (string) ($e->a->server ?? '');
            $instanceid = is_numeric($e->a->instanceid ?? null) ? (int) $e->a->instanceid : null;
        }
        return self::fail($e->errorcode, $e->getMessage(), $clientpath, $operation, $host, $instanceid, $courseid);
    }

    /**
     * Record a pending write ({@see pending_write_notice::record()}) and build
     * the five-part failure response (Issues #492/#516, Spec #486 §8/§10):
     * path and operation; teacher-facing reason with retry/intervention advice;
     * pending identifier; instruction to retain content, retry with
     * `pending_entry=` and use no other location; instance name and host.
     *
     * Exclude absolute server paths, account names, passwords, HTTP status and
     * response bodies. Raw diagnostics go to the access log. If saving the note
     * fails because Private Files is full, report that explicitly.
     *
     * @param string $errorclass webdav_error constant or a LOCATION_FAILURE_CODES entry.
     * @param string $rawmessage Internal diagnostic (e.g. "HTTP 507") for the access log only.
     * @param string $clientpath
     * @param string $operation One of the OP_* constants.
     * @param string $host
     * @param int|null $instanceid
     * @param int $courseid Course ID for the pending note (Issue #516).
     * @return \moodle_exception
     */
    private static function fail(
        string $errorclass,
        string $rawmessage,
        string $clientpath,
        string $operation,
        string $host,
        ?int $instanceid,
        int $courseid
    ): \moodle_exception {
        return pending_write_translation::record_and_translate(
            $errorclass,
            'WebDAV ' . $errorclass . ': ' . $rawmessage,
            $clientpath,
            $operation,
            self::reason_for($errorclass),
            self::describe_target($host, $instanceid),
            $courseid
        );
    }

    /**
     * Teacher-facing reason and retry/intervention advice (Issue #516,
     * Spec #486 §8). Temporary failures can be retried later; other failures
     * require action on the storage. Public for {@see webdav_storage_port}
     * to share the WebDAV reason wording (Issue #540).
     *
     * @param string $errorclass
     * @return string
     */
    public static function reason_for(string $errorclass): string {
        $reason = self::REASONS[$errorclass] ?? ('error class "' . $errorclass . '"');
        return $reason . ' – ' . self::classify($errorclass);
    }

    /**
     * Part 2 of the failure response (Issue #516, Spec #486 §8): advise retrying
     * later for temporary failures, or action on the storage otherwise.
     *
     * @param string $errorclass
     * @return string
     */
    private static function classify(string $errorclass): string {
        return in_array($errorclass, self::LATER_CLASSES, true)
            ? 'this can be added later'
            : 'something needs to be done on your storage';
    }

    /**
     * Instance name and host (part 5 of the failure response). Never expose
     * the full base path. The fingerprint contains only the host, not secrets.
     * Read the name from the database; a deleted instance has no name, so only
     * the supplied host remains. Shared with {@see webdav_storage_port}
     * (Issue #540).
     *
     * @param string $host
     * @param int|null $instanceid
     * @return string
     */
    public static function describe_target(string $host, ?int $instanceid): string {
        global $DB;

        $name = $instanceid !== null
            ? $DB->get_field('repository_instances', 'name', ['id' => $instanceid])
            : false;

        if ($name === false || $name === null || $name === '') {
            return $host;
        }
        return $name . ' (' . $host . ')';
    }
}
