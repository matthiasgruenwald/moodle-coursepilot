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
 * Database proxy that records every call and forwards it to the real database.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class recording_database {
    /** @var \moodle_database The real database. */
    private \moodle_database $inner;

    /** @var array<int, array{0: string, 1: mixed[]}> Method name and arguments per call. */
    public array $calls = [];

    /**
     * Creates the proxy.
     *
     * @param \moodle_database $inner The real database.
     */
    public function __construct(\moodle_database $inner) {
        $this->inner = $inner;
    }

    /**
     * Records and forwards a call.
     *
     * @param string $name Method name.
     * @param mixed[] $arguments Call arguments.
     * @return mixed What the real database returned.
     */
    public function __call(string $name, array $arguments): mixed {
        $this->calls[] = [$name, $arguments];
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
