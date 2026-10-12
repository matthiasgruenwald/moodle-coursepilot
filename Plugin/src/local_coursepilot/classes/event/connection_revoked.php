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

namespace local_coursepilot\event;

/**
 * A Coursepilot connection was revoked; token generations are not logged.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class connection_revoked extends \core\event\base {
    /**
     * Localised name for Moodle's native log report.
     *
     * @return string Event name.
     */
    public static function get_name(): string {
        return get_string('event_connection_revoked', 'local_coursepilot');
    }

    /**
     * Describe the connection and owner using ids, without token secrets.
     *
     * @return string Event description.
     */
    public function get_description(): string {
        return "Coursepilot connection with id '{$this->objectid}' for the user with id '{$this->relateduserid}' was revoked.";
    }

    /**
     * Set the stable connection as the security event's object.
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'local_coursepilot_oauth_grant';
        $this->context = \context_system::instance();
    }

    /**
     * Site-level connections are not part of course backup or restore.
     *
     * @return int Unmapped object marker.
     */
    public static function get_objectid_mapping(): int {
        return self::NOT_MAPPED;
    }
}
