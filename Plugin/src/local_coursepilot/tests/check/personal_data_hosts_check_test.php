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

namespace local_coursepilot\check;

use core\check\result;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Statusprüfung Schritt 4 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): eine leere Liste zugelassener Speicher heisst `INFO`, kein Mangel.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(personal_data_hosts_check::class)]
final class personal_data_hosts_check_test extends \advanced_testcase {

    public function test_is_info_when_list_is_empty(): void {
        $this->resetAfterTest();

        $result = (new personal_data_hosts_check())->get_result();

        $this->assertSame(result::INFO, $result->get_status());
    }

    public function test_is_ok_when_hosts_are_configured(): void {
        $this->resetAfterTest();
        set_config('personaldatahosts', "cloud.example.test\n", 'local_coursepilot');

        $result = (new personal_data_hosts_check())->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }
}
