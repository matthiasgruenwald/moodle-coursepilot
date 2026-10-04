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

namespace local_coursepilot\webdav;

/**
 * Connection error of a {@see webdav_transport} (timeout,
 * DNS error) - distinguished from an interpreted HTTP response because here
 * no response came about at all. {@see webdav_client} catches it and turns
 * it into the error class `unreachable` (Issue #489, Spec #486 §4).
 *
 * Deliberately carries no server or credential data in the message (Spec §3:
 * "The secret takes no new path").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class webdav_transport_exception extends \RuntimeException {
}
