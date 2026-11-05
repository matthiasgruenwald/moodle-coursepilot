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

namespace local_coursepilot;

/**
 * An adapter-neutral storage outage. Caller errors and checksum conflicts
 * remain separate; the caller decides whether an outage records a pending write.
 * Diagnostics stay internal and must never be displayed directly to a teacher.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class storage_failure extends \RuntimeException {
    /**
     * Carries a stable failure category and the original internal diagnostic.
     *
     * @param string $errorclass Stable storage failure category.
     * @param \Throwable $cause Original adapter failure, for diagnostics only.
     */
    public function __construct(
        /** @var string Stable storage failure category. */
        public readonly string $errorclass,
        \Throwable $cause,
    ) {
        parent::__construct($cause->getMessage(), 0, $cause);
    }
}
