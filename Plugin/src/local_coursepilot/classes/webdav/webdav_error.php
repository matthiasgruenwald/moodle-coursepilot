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

namespace local_coursepilot\webdav;

/**
 * A named error class instead of a bare status code (Issue #489,
 * Spec #486 §4, ADR 0022). Every caller of {@see webdav_client}
 * distinguishes only these eight classes, never an HTTP code.
 *
 * Deliberately never carries username, password, auth header, server path or
 * response body in the message (Spec §3/§8, secret test).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_error extends \RuntimeException {
    /** @var string No DAV XML body on a 404, or another unclear status - silently retried. */
    public const UNCLEAR = 'unclear';

    /** @var string 404 with a DAV XML body. */
    public const NOT_FOUND = 'not_found';

    /** @var string 401/403. */
    public const AUTH_REJECTED = 'auth_rejected';

    /** @var string Timeout, DNS error. */
    public const UNREACHABLE = 'unreachable';

    /** @var string 507. */
    public const STORAGE_FULL = 'storage_full';

    /** @var string 409/412. */
    public const CONFLICT = 'conflict';

    /** @var string Moodle's host block. */
    public const BLOCKED = 'blocked';

    /** @var string 3xx response - the client follows no redirect (Issue #510). */
    public const REDIRECTED = 'redirected';

    /**
     * @param string $errorclass One of the constants of this class.
     * @param string $message Internal, developer-oriented message - never
     *        passed on to a teacher, never a secret.
     */
    public function __construct(
        public readonly string $errorclass,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $errorclass);
    }

    /**
     * Translated label for an error class (Issue #565): the constants
     * above are fixed internal identifiers for comparisons in code
     * (`$errorclass === webdav_error::UNCLEAR`), never meant for display.
     * Every place that shows an error class to a teacher
     * (e.g. via {$a->errorclass} in locationselectionexternalerror/
     * webdavexternalerror/materialexternalerror) must go through this label
     * instead of interpolating the constant directly - otherwise the
     * text stays in the internal identifier language instead of the user's.
     *
     * @param string $errorclass One of the constants of this class.
     * @return string translated label, or the constant itself as a
     *         fallback if it matches no known class.
     */
    public static function label(string $errorclass): string {
        static $map = [
            self::UNCLEAR => 'webdaverrorunclear',
            self::NOT_FOUND => 'webdaverrornotfound',
            self::AUTH_REJECTED => 'webdaverrorauthrejected',
            self::UNREACHABLE => 'webdaverrorunreachable',
            self::STORAGE_FULL => 'webdaverrorstoragefull',
            self::CONFLICT => 'webdaverrorconflict',
            self::BLOCKED => 'webdaverrorblocked',
            self::REDIRECTED => 'webdaverrorredirected',
        ];
        return isset($map[$errorclass]) ? get_string($map[$errorclass], 'local_coursepilot') : $errorclass;
    }

    /**
     * The single error picture of the WebDAV storage (Issue #506): "not
     * found" means empty, every other error stays a named error.
     * Shared by {@see \local_coursepilot\webdav_storage_port} and
     * {@see \local_coursepilot\location_selection}. What "every other
     * error" concretely means remains up to the caller: the adapter
     * passes the raw error on unchanged (the pending-write handling
     * still has to recognise it as a {@see webdav_error}), the location
     * selection immediately translates it into a teacher message.
     *
     * @template T
     * @param self $e
     * @param T $whenmissing Return value when $e is "not found".
     * @param callable(self): \Throwable $onfailure Builds the exception for
     *        every other error - or passes $e through unchanged
     *        ({@see \local_coursepilot\webdav_storage_port}).
     * @return T
     * @throws \Throwable The result of $onfailure($e).
     */
    public static function empty_when_missing(self $e, mixed $whenmissing, callable $onfailure): mixed {
        if ($e->errorclass === self::NOT_FOUND) {
            return $whenmissing;
        }
        throw $onfailure($e);
    }
}
