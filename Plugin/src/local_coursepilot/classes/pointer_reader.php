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
use local_coursepilot\webdav\webdav_setup_steps;

/**
 * Shared WebDAV read helpers for the {@see storage_port} adapters and their
 * callers: the weak external check value and the teacher-facing error text.
 * Reading and listing themselves run through {@see webdav_storage_port}
 * (Issue #645); this class no longer interprets locations.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_reader {

    /**
     * Derive a check value from ETag or `getlastmodified` (Issue #513,
     * Spec #486 §4/§6). {@see webdav_storage_port} returns it as `contenthash`
     * and accepts it as `expected_contenthash` to compare with the current state.
     *
     * This opaque value is not Moodle's content hash. Prefer an ETag (Nextcloud);
     * otherwise use `getlastmodified` (IServ), with the documented limitation of
     * second-level resolution.
     *
     * @param string|null $etag
     * @param int $timemodified
     * @return string 40-character SHA-1 hex value, safe for PARAM_ALPHANUMEXT, unlike quoted raw ETags.
     */
    public static function external_checkvalue(?string $etag, int $timemodified): string {
        return $etag !== null ? sha1('etag:' . $etag) : sha1('mtime:' . $timemodified);
    }

    /**
     * Translate a {@see webdav_error} into a teacher-facing message containing
     * only the error class and location selection page, without host, account,
     * password, HTTP status or response body (Spec #486 Testing Decisions).
     * Shared with {@see \local_coursepilot\location_selection} (Issue #494).
     *
     * The default `webdavexternalerror` addresses the AI about a context gap.
     * Location selection and material tools supply their own message keys for
     * their different audience and scope (Issue #526, Spec #486 §5/§8).
     *
     * For `UNCLEAR`, the default key selects a short retry instruction: throttling
     * often clears within seconds, whereas rejected authentication or full
     * storage requires intervention. Explicit caller keys are unchanged
     * (Issue #529).
     *
     * @param webdav_error $e
     * @param string $stringkey
     * @return \moodle_exception
     */
    public static function webdav_exception(webdav_error $e, string $stringkey = 'webdavexternalerror'): \moodle_exception {
        if ($stringkey === 'webdavexternalerror' && $e->errorclass === webdav_error::UNCLEAR) {
            $stringkey = 'webdavexternalerrorunclear';
        }
        return new \moodle_exception($stringkey, 'local_coursepilot', '', (object) [
            // Issue #565: localized label rather than the internal constant; see webdav_error::label().
            'errorclass' => webdav_error::label($e->errorclass),
            'page' => webdav_setup_steps::LOCATION_SELECTION_PAGE,
        ]);
    }
}
