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
 * The swappable transport seam of {@see webdav_client} (Issue #489,
 * Spec #486 §4/Testing Decisions, ADR 0022). In operation exactly one
 * implementation, {@see curl_transport}, on Moodle's \curl. In tests the
 * reusable in-memory fake
 * `\local_coursepilot\tests\webdav\fake_webdav_transport`.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
interface webdav_transport {
    /**
     * A single HTTP request. The transport does not interpret the status -
     * that is the job of {@see webdav_client}.
     *
     * @param string $method PROPFIND|GET|PUT|MKCOL|MOVE|DELETE.
     * @param string $url Complete https address.
     * @param array $headers Additional request headers
     * @phpstan-param array<string,string> $headers
     *        (e.g. Depth, If-Match, If-None-Match, Destination), without
     *        the auth header - the transport sets that itself.
     * @param string|null $body Body, e.g. PROPFIND XML or file content.
     * @return webdav_response
     * @throws webdav_transport_exception on connection errors
     *         (timeout, DNS) - see {@see webdav_client}, which turns
     *         them into the error class `unreachable`.
     * @throws webdav_error directly, if the transport itself already knows a
     *         named error class (e.g. `blocked` by Moodle's
     *         host block in {@see curl_transport}).
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response;
}
