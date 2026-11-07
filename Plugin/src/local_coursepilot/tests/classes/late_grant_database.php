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
 * Database proxy that lets a concurrent session act between two statements of connection::revoke_all_tokens().
 *
 * Every call is forwarded to the real database. Right after the grant lock statement (set_field of the
 * statehash column) the configured callback runs, which stands in for another session that creates or
 * revokes a connection at that moment.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class late_grant_database {
    /** @var \moodle_database The real database. */
    private \moodle_database $inner;

    /** @var callable Called with the real database and the lock marker after the lock statement. */
    private $afterlock;

    /** @var bool Whether the callback already ran. */
    private bool $fired = false;

    /**
     * Creates the proxy.
     *
     * @param \moodle_database $inner The real database.
     * @param callable $afterlock Receives the real database and the marker written to statehash.
     */
    public function __construct(\moodle_database $inner, callable $afterlock) {
        $this->inner = $inner;
        $this->afterlock = $afterlock;
    }

    /**
     * Forwards a call to the real database and fires the callback after the grant lock.
     *
     * @param string $name Method name.
     * @param mixed[] $arguments Call arguments.
     * @return mixed What the real database returned.
     */
    public function __call(string $name, array $arguments): mixed {
        $result = $this->inner->$name(...$arguments);
        if (!$this->fired && $name === 'set_field' && ($arguments[1] ?? null) === 'statehash') {
            $this->fired = true;
            ($this->afterlock)($this->inner, (string) $arguments[2]);
        }
        return $result;
    }

    /**
     * Forwards a property read to the real database.
     *
     * @param string $name Property name.
     * @return mixed
     */
    public function __get(string $name): mixed {
        return $this->inner->$name;
    }
}
