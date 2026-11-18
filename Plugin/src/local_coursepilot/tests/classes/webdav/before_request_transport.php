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
 * Transport double that runs an action once before the n-th request of one method, then delegates:
 * a concurrent change that lands between a port's read and its mutating request.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class before_request_transport implements webdav_transport {
    /** @var \Closure|null Action still to run. */
    private ?\Closure $action;

    /** @var int Requests of the method seen so far. */
    private int $seen = 0;

    /**
     * Creates the double.
     *
     * @param webdav_transport $inner Real transport.
     * @param string $method Method before whose n-th request the action runs.
     * @param \Closure $action The intervening change.
     * @param int $occurrence Which request of the method triggers the action, 1-based.
     */
    public function __construct(
        /** @var webdav_transport Real transport. */
        private readonly webdav_transport $inner,
        /** @var string Method before whose n-th request the action runs. */
        private readonly string $method,
        \Closure $action,
        /** @var int Which request of the method triggers the action. */
        private readonly int $occurrence = 1,
    ) {
        $this->action = $action;
    }

    /**
     * Runs the action once, then delegates.
     *
     * @param string $method
     * @param string $url
     * @param mixed[] $headers
     * @param string|null $body
     * @return webdav_response
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): webdav_response {
        if ($method === $this->method && ++$this->seen === $this->occurrence && $this->action !== null) {
            $action = $this->action;
            $this->action = null;
            $action();
        }
        return $this->inner->request($method, $url, $headers, $body);
    }
}
