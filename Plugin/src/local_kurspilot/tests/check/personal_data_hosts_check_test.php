<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\check;

use core\check\result;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Statusprüfung Schritt 4 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): eine leere Liste zugelassener Speicher heisst `INFO`, kein Mangel.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
        set_config('personaldatahosts', "cloud.example.test\n", 'local_kurspilot');

        $result = (new personal_data_hosts_check())->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }
}
