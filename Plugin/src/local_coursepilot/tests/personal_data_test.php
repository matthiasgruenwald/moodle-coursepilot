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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Personal-data switch and front matter marker of context files (ADR 0021).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(personal_data::class)]
final class personal_data_test extends \advanced_testcase {
    public function test_delivery_follows_the_admin_setting(): void {
        $this->resetAfterTest();

        set_config('allowpersonaldata', 0, 'local_coursepilot');
        $this->assertFalse(personal_data::allowed());
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $this->assertTrue(personal_data::allowed());
    }

    public function test_front_matter_flag_marks_a_file(): void {
        $this->assertTrue(personal_data::is_marked("---\npersonenbezug: true\n---\nText"));
    }

    public function test_file_without_front_matter_or_flag_is_unmarked(): void {
        $this->assertFalse(personal_data::is_marked("personenbezug: true\nText"));
        $this->assertFalse(personal_data::is_marked("---\npersonenbezug: false\n---\nText"));
    }
}
