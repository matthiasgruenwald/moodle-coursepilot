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
 * Failed ticket redemption (#501, Spec #486 §13), retaining the workbench
 * path when known for workbench/download.php access logging. A regular
 * moodle_exception loses the path if workbench_ticket::redeem() has already
 * deleted the ticket row before a later check fails.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class workbench_ticket_redemption_failed extends \moodle_exception {
    /**
     * Creates the workbench ticket redemption failed.
     *
     * @param string $errorcode Language key in local_coursepilot.
     * @param string|null $path Workbench path, or null if the ticket was already unknown.
     */
    public function __construct(
        string $errorcode,
        /** @var ?string Workbench path, or null if the ticket was already unknown. */
        public readonly ?string $path,
    ) {
        parent::__construct($errorcode, 'local_coursepilot');
    }
}
