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
 * Failed Coursepilot MCP access (#339): authentication, permission,
 * unknown tool/method, or failure during tool execution.
 *
 * Store a short fixed reason code/text, never the access token
 * (dispatcher::error()/handle_tools_call()). Emitted from errors-only
 * logging onward.
 *
 * @property-read array $other {
 *      - string reason: Short error description, without secrets.
 *      - string|null toolname: Affected tool name, if known.
 *      - string|null path: Path touched by the failed access, if known (#501).
 *      - string|null detail: Internal diagnostic, only with full logging (#457).
 * }
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class tool_access_failed extends \core\event\base {
    /**
     * Returns description.
     *
     * @return string
     */
    public function get_description() {
        $tool = $this->other['toolname'] ?? null;
        $suffix = $tool !== null ? " (tool: {$tool})" : '';
        return "A Coursepilot access failed: {$this->other['reason']}{$suffix}.";
    }

    /**
     * Returns name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_tool_access_failed', 'local_coursepilot');
    }

    /**
     * Initialises the tool access failed.
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
        if (!isset($this->other['reason'])) {
            throw new \coding_exception('The \'reason\' value must be set in other.');
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
