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

namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Template-Datenaufbereitung fuer surface.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(surface_page::class)]
final class surface_page_test extends \advanced_testcase {

    public function test_no_violations_means_no_violation_rows(): void {
        $this->resetAfterTest();

        $data = surface_page::page_data([], [], ['ok' => true, 'detail' => '', 'url' => 'https://example.test', 'httpcode' => 200]);

        $this->assertFalse($data['hasviolations']);
        $this->assertSame([], $data['violations']);
        $this->assertTrue($data['selfcheckok']);
        $this->assertNull($data['selfcheckerror']);
        $this->assertNull($data['emergencyexithint']);
    }

    public function test_violations_are_mapped_into_rows(): void {
        $this->resetAfterTest();

        $violations = [
            ['type' => 'unregistered', 'name' => 'local_coursepilot_secret', 'detail' => 'not on allowlist'],
        ];
        $data = surface_page::page_data(
            $violations,
            [],
            ['ok' => true, 'detail' => '', 'url' => 'https://example.test', 'httpcode' => 200]
        );

        $this->assertTrue($data['hasviolations']);
        $this->assertSame($violations[0], $data['violations'][0]);
    }

    public function test_failed_self_check_includes_error_and_emergency_exit_hint(): void {
        $this->resetAfterTest();

        $data = surface_page::page_data(
            [],
            [],
            ['ok' => false, 'detail' => 'selfcheckunexpectedstatus', 'url' => 'https://example.test', 'httpcode' => 500]
        );

        $this->assertFalse($data['selfcheckok']);
        $this->assertNotNull($data['selfcheckerror']);
        $this->assertNotNull($data['emergencyexithint']);
    }
}
