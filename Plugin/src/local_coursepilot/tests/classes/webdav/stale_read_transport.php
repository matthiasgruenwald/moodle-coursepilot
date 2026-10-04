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
 * Decorator around {@see fake_webdav_transport} producing a real conflict
 * (#491). pointer_writer reads current properties immediately before its
 * conditional PUT, so a synchronous fake alone cannot reproduce concurrency.
 * After the first PROPFIND response for the watched URL, edit the fake file
 * again. The subsequent stale If-Match/getlastmodified write returns 412.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class stale_read_transport implements webdav_transport {

    private bool $triggered = false;

    /**
     * @param fake_webdav_transport $fake Underlying storage, also modified by the simulated concurrent edit.
     * @param string $urlsubstring URL substring identifying the watched file.
     * @param fake_webdav_transport $inner Production would use another transport; the test intentionally
     *        uses the same instance and edits its storage directly.
     */
    public function __construct(
        private readonly fake_webdav_transport $fake,
        private readonly string $urlsubstring,
        private readonly fake_webdav_transport $inner,
    ) {
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        $response = $this->inner->request($method, $url, $headers, $body);
        if (!$this->triggered && $method === 'PROPFIND' && str_contains($url, $this->urlsubstring)) {
            $this->triggered = true;
            $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            $this->fake->seed_file($path, 'handaenderung');
        }
        return $response;
    }
}
