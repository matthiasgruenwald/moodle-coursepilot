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
 * Validated WebDAV user instance with freshly read credentials (Issue #490,
 * Spec #486 §2/§3). Exists only for one call and is never persisted. Builds
 * resource URLs within the instance and the corresponding webdav_client.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class resolved_webdav_instance {
    /**
     * Creates the resolved webdav instance.
     *
     * @param string $baseurl HTTPS instance URL including base path and trailing slash.
     * @param webdav_transport $transport Production curl_transport with freshly read credentials,
     *        or the injected test fake; the same seam as webdav_client
     *        (Spec #486 Testing Decisions).
     */
    public function __construct(
        /** @var string HTTPS instance URL including base path and trailing slash. */
        private readonly string $baseurl,
        /** @var webdav_transport Production curl_transport with freshly read credentials, */
        private readonly webdav_transport $transport,
    ) {
    }

    /**
     * Returns new client using the supplied transport.
     *
     * @return webdav_client New client using the supplied transport.
     */
    public function client(): webdav_client {
        return new webdav_client($this->transport);
    }

    /**
     * Returns directory URL with a trailing slash.
     *
     * @param string $relativepath Already segment-validated, without leading or trailing slashes.
     * @return string Directory URL with a trailing slash.
     */
    public function directory_url(string $relativepath): string {
        return $this->url($relativepath) . '/';
    }

    /**
     * Returns file URL without a trailing slash.
     *
     * @param string $relativepath Already segment-validated, without leading or trailing slashes.
     * @return string File URL without a trailing slash.
     */
    public function file_url(string $relativepath): string {
        return $this->url($relativepath);
    }

    /**
     * Provides url.
     *
     * @param string $relativepath
     * @return string
     */
    private function url(string $relativepath): string {
        $trimmed = trim($relativepath, '/');
        $base = rtrim($this->baseurl, '/');
        if ($trimmed === '') {
            return $base;
        }
        $encoded = implode('/', array_map('rawurlencode', explode('/', $trimmed)));
        return $base . '/' . $encoded;
    }
}
