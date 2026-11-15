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
 * Transport double that records requests and answers from a queue (the last answer repeats).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class recording_transport implements webdav_transport {
    /** @var array<int, array{0: string, 1: string, 2: array<string, string>}> Recorded requests. */
    public array $requests = [];

    /**
     * Creates the double.
     *
     * @param webdav_response[] $responses Answers in order.
     */
    public function __construct(
        /** @var webdav_response[] Answers in order. */
        private array $responses
    ) {
    }

    /**
     * Records the request and answers.
     *
     * @param string $method
     * @param string $url
     * @param mixed[] $headers
     * @param string|null $body
     * @return webdav_response
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        $this->requests[] = [$method, $url, $headers];
        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }
}
