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
 * Database proxy whose first read of one kind misses a row that another session has written in the meantime.
 *
 * Every call is forwarded to the real database. The first call of the configured method (optionally only one whose
 * first argument contains a given text) returns false, as a read that ran just before a concurrent write;
 * later calls see the real data.
 *
 * A database double is needed because the interleaving of two sessions cannot be produced deterministically any other
 * way and there is no port for it (rule T1). __call() and __get() exist so that every other call and property read
 * reaches the real database unchanged.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class stale_read_database {
    /** @var \moodle_database The real database. */
    private \moodle_database $inner;

    /** @var string Name of the read method whose first call returns false. */
    private string $method;

    /** @var string Text the first argument of the stale call must contain; empty matches every call. */
    private string $contains;

    /** @var bool Whether the stale read already happened. */
    private bool $missed = false;

    /**
     * Creates the proxy.
     *
     * @param \moodle_database $inner The real database.
     * @param string $method Read method whose first call misses the row, e.g. get_field.
     * @param string $contains Text the first argument of that call must contain (table or SQL); empty for any call.
     */
    public function __construct(\moodle_database $inner, string $method, string $contains = '') {
        $this->inner = $inner;
        $this->method = $method;
        $this->contains = $contains;
    }

    /**
     * Forwards a call to the real database; the first call of the configured read returns false.
     *
     * @param string $name Method name.
     * @param mixed[] $arguments Call arguments.
     * @return mixed What the real database returned.
     */
    public function __call(string $name, array $arguments): mixed {
        if (!$this->missed && $name === $this->method && str_contains((string) ($arguments[0] ?? ''), $this->contains)) {
            $this->missed = true;
            return false;
        }
        return $this->inner->$name(...$arguments);
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
