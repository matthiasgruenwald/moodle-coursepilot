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


namespace local_coursepilot\tests\webdav;

use local_coursepilot\webdav\webdav_response;
use local_coursepilot\webdav\webdav_transport;

/**
 * Transport decorator that answers every PROPFIND with "not found" once a PUT has passed.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class hiding_transport implements webdav_transport {
    /** @var bool Whether a PUT happened. */
    private bool $put = false;

    /**
     * Creates the decorator.
     *
     * @param webdav_transport $inner Server double doing the real work.
     */
    public function __construct(
        /** @var webdav_transport Server double doing the real work. */
        private readonly webdav_transport $inner
    ) {
    }

    /**
     * Forgets the PUT so that later requests are answered normally.
     */
    public function reset(): void {
        $this->put = false;
    }

    /**
     * Forwards the request; after a PUT every PROPFIND is a DAV "not found".
     *
     * @param string $method
     * @param string $url
     * @param mixed[] $headers
     * @param string|null $body
     * @return webdav_response
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        $response = $this->inner->request($method, $url, $headers, $body);
        if ($method === 'PUT') {
            $this->put = true;
        }
        if ($method === 'PROPFIND' && $this->put) {
            return new webdav_response(404, ['content-type' => 'application/xml'], '<d:error xmlns:d="DAV:"/>');
        }
        return $response;
    }
}
