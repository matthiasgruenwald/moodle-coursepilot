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

/**
 * Location-neutral failure response (Issue #540, ADR 0023, Spec 0021):
 * record a pending write, then build the five-part response. Extracted from
 * {@see pointer_writer}'s `fail()` without changing its behavior. Error class,
 * path, operation, identifier and course ID are location-neutral; callers
 * supply the completed reason and target description.
 *
 * Used by {@see pointer_writer}, {@see webdav_storage_port} and
 * {@see context_area} for failures at external storage and Moodle Private
 * Files. WebDAV-specific reason wording remains in
 * {@see pointer_writer::reason_for()}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pending_write_translation {
    /** @var string Create operation. */
    public const OP_CREATE = 'create';

    /** @var string Overwrite operation. */
    public const OP_OVERWRITE = 'overwrite';

    /** @var string Append operation. */
    public const OP_APPEND = 'append';

    /**
     * @var string Unknown operation (Issue #561): the preflight read failed, so whether
     *      the target already existed is unknown, rather than create or overwrite.
     */
    public const OP_UNKNOWN = 'unknown';

    /**
     * Record a pending write and build the five-part failure response (Issues
     * #492/#516/#540): (1) path and operation; (2) teacher-facing reason supplied
     * by the caller; (3) saved pending identifier; (4) instruction to retain the
     * content and retry with `pending_entry=`; (5) target description supplied
     * by the caller (external instance and host, or Private Files).
     *
     * Absolute server paths, account names, passwords, HTTP status and response
     * bodies stay out of the response; raw diagnostics go to the access log.
     * If the pending note cannot be saved because Private Files is full, report
     * that explicitly instead of hiding the original failure.
     *
     * @param string $errorclass Error class, never free text (Issue #516).
     * @param string $logmessage Internal diagnostic for the access log only.
     * @param string $clientpath
     * @param string $operation One of the OP_* constants.
     * @param string $reason Teacher-facing reason including retry or storage intervention advice.
     * @param string $target Instance name and host (external), or a short Private Files description.
     * @param int $courseid Course ID for the pending note (Issue #516), or 0 without a course.
     * @return \moodle_exception
     */
    public static function record_and_translate(
        string $errorclass,
        string $logmessage,
        string $clientpath,
        string $operation,
        string $reason,
        string $target,
        int $courseid
    ): \moodle_exception {
        access_log::log_failure($logmessage);

        try {
            $identifier = pending_write_notice::record($clientpath, $operation, $errorclass, $courseid);
        } catch (\moodle_exception $quotaerror) {
            if ($quotaerror->errorcode !== 'pendingnotequotaexceeded') {
                throw $quotaerror;
            }
            return new \moodle_exception('pendingnotewritefailed', 'local_coursepilot', '', (object) [
                'path' => $clientpath,
                'operation' => self::operation_label($operation),
            ]);
        }

        return new \moodle_exception('pendingwritefailed', 'local_coursepilot', '', (object) [
            'path' => $clientpath,
            'operation' => self::operation_label($operation),
            'reason' => $reason,
            'identifier' => $identifier,
            'target' => $target,
        ]);
    }

    /**
     * Display label for an operation (#602): stored values are English keys;
     * teachers read the label in their language.
     *
     * @param string $operation One of the OP_* constants.
     * @return string
     */
    public static function operation_label(string $operation): string {
        return get_string('pendingoperation' . $operation, 'local_coursepilot');
    }
}
