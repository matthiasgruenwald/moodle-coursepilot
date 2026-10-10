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

namespace local_coursepilot\webdav;

/**
 * Raw WebDAV response from webdav_transport::request() (Issue #489,
 * Spec #486 §4, ADR 0022). webdav_client::classify() interprets status/body
 * into named error classes. The exception is BLOCKED: curl_transport
 *  detects Moodle host restrictions before any response and throws
 * webdav_error directly.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_response {
    /**
     * Creates the webdav response.
     *
     * @param int $statuscode HTTP status.
     * @param string[] $headers Response headers with lowercased keys (e.g. etag, content-type).
     * @param string $body Unmodified response body.
     */
    public function __construct(
        /** @var int HTTP status. */
        public readonly int $statuscode,
        /** @var array The headers. */
        public readonly array $headers,
        /** @var string Unmodified response body. */
        public readonly string $body,
    ) {
    }

    /**
     * Read a response header case-insensitively.
     *
     * @param string $name
     * @return string|null
     */
    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }
}
