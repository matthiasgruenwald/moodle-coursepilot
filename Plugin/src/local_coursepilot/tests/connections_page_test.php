<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

/**
 * Access control for the personal connections page (Issue #548).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class connections_page_test extends \advanced_testcase {
    public function test_rejects_a_user_without_remote_access_grant(): void {
        $source = (string) file_get_contents(__DIR__ . '/../connections.php');
        $this->assertStringContainsString('\\local_coursepilot\\remote_access::require_granted();', $source);

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('REMOTE_ACCESS_NOT_GRANTED');
        remote_access::require_granted();
    }
}
