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

namespace local_coursepilot\admin;

use local_coursepilot\personal_data_hosts;

// admin_setting_configtextarea is a legacy global class from adminlib.php,
// without PSR-4 autoloading. Admin settings usually load it first, but PHPUnit
// and early callers may not. Import $CFG because autoloading can run inside
// a function where it would otherwise be out of scope.
global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Setting for `local_coursepilot | personaldatahosts` (Issue #493):
 * a textarea that rejects single-part names or wildcard entries on save.
 * Shared rule: {@see personal_data_hosts::first_invalid_entry()}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class personaldatahosts_setting extends \admin_setting_configtextarea {
    /**
     * Validates the personaldatahosts setting.
     *
     * @param mixed $data
     * @return mixed true if valid, otherwise an error message.
     */
    public function validate($data) {
        $parentvalidation = parent::validate($data);
        if ($parentvalidation !== true) {
            return $parentvalidation;
        }

        $invalid = personal_data_hosts::first_invalid_entry((string) $data);
        if ($invalid !== null) {
            return get_string('personaldatahostsinvalid', 'local_coursepilot', $invalid);
        }
        return true;
    }
}
