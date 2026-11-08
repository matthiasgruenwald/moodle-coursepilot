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
namespace local_coursepilot\tests;

/**
 * Lock factory that records every requested lock and grants it without blocking.
 *
 * Select it with `$CFG->lock_factory` to see which resource name and timeout a caller asks for.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class recording_lock_factory implements \core\lock\lock_factory {
    /** @var array<int, array{type: string, resource: string, timeout: int}> Requests in order. */
    public static array $requests = [];

    /** @var string Namespace of this factory instance. */
    private string $type;

    /**
     * Creates the factory for one lock namespace.
     *
     * @param string $type Namespace of the locks.
     */
    public function __construct($type) {
        $this->type = (string) $type;
    }

    /**
     * Reports that timeouts are honoured.
     *
     * @return bool
     */
    public function supports_timeout() {
        return true;
    }

    /**
     * Reports that nothing is left behind after the process ends.
     *
     * @return bool
     */
    public function supports_auto_release() {
        return true;
    }

    /**
     * Reports that the factory can be used.
     *
     * @return bool
     */
    public function is_available() {
        return true;
    }

    /**
     * Records the request and grants the lock.
     *
     * @param string $resource Name of the locked resource.
     * @param int $timeout Seconds to wait.
     * @param int $maxlifetime Seconds before the lock may be released by others.
     * @return \core\lock\lock
     */
    public function get_lock($resource, $timeout, $maxlifetime = 86400) {
        self::$requests[] = ['type' => $this->type, 'resource' => (string) $resource, 'timeout' => (int) $timeout];
        return new \core\lock\lock($resource, $this);
    }

    /**
     * Accepts the release of a lock.
     *
     * @param \core\lock\lock $lock The lock.
     * @return bool
     */
    public function release_lock(\core\lock\lock $lock) {
        return true;
    }

    /**
     * Forgets the recorded requests.
     */
    public static function reset(): void {
        self::$requests = [];
    }
}
