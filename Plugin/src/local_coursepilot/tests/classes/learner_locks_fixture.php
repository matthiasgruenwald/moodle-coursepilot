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
 * Minimal catalog stand-in for learner_locks: two lock conditions and one alias.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class learner_locks_fixture {
    /**
     * Lock conditions by form field.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function learner_locks(): array {
        return [
            'password' => ['op' => 'nonzero', 'reason' => 'A password blocks learners without it.'],
            'attempts' => ['op' => 'greater', 'value' => 1, 'reason' => 'Few attempts, unless teacher resets.'],
            'mode' => ['op' => 'equals', 'value' => 'locked', 'reason' => 'Locked mode.'],
            'visible' => ['op' => 'not_equals', 'value' => 1, 'reason' => 'Hidden from learners.'],
            'title' => ['op' => 'equals', 'value' => 'Größe/x', 'reason' => 'Special.'],
        ];
    }

    /**
     * Write options with the alias of a form field to its stored column.
     *
     * @return array<string, mixed>
     */
    public static function write_options(): array {
        return ['settings_aliases' => ['password' => 'storedpassword']];
    }
}
