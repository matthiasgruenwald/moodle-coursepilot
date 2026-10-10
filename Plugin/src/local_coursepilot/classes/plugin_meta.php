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
 * Canonical source for $plugin->version/->release (#577). Previously,
 * dispatcher::handle() (MCP handshake serverInfo) and get_version_info::execute()
 * read version.php independently, duplicating the same logic. Now one place
 * reads the file and both callers query it.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class plugin_meta {
    /**
     * $plugin from the running version.php, not config_plugins, so deployment
     * without upgrade.php remains visible (see get_version_info::execute()).
     *
     * @return \stdClass
     */
    public static function current(): \stdClass {
        global $CFG;

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/coursepilot/version.php');
        return $plugin;
    }
}
