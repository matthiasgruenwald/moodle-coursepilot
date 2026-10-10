<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\event;

/**
 * Successful Coursepilot tool call through MCP (#339).
 *
 * Emitted through Moodle's event API for native admin log reports when
 * access_log is set to at least reads and errors.
 *
 * @property-read array $other {
 *      - string toolname: Called MCP tool name.
 *      - string|null path: Context or material path touched by the tool
 *        (Spec 0018 §9.2), otherwise null.
 * }
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class tool_access_succeeded extends \core\event\base {
    /**
     * Returns description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' successfully called the Coursepilot tool '{$this->other['toolname']}'.";
    }

    /**
     * Returns name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_tool_access_succeeded', 'local_coursepilot');
    }

    /**
     * Initialises the tool access succeeded.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->context = \context_system::instance();
    }

    /**
     * Validates data.
     *
     * @return void
     * @throws \coding_exception
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['toolname'])) {
            throw new \coding_exception('The \'toolname\' value must be set in other.');
        }
    }

    /**
     * Returns other mapping.
     *
     * @return false
     */
    public static function get_other_mapping() {
        return false;
    }
}
