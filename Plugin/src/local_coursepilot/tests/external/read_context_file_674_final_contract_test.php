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

namespace local_coursepilot\external;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The context-area reader honours the web service context restriction (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(read_context_file::class)]
final class read_context_file_674_final_contract_test extends \advanced_testcase {
    use \local_coursepilot\tests\caught_throwable;

    /**
     * A request restricted to a course context cannot read the user context area.
     */
    public function test_a_restricted_request_context_is_refused(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $course = $this->getDataGenerator()->create_course();
        \core_external\external_api::set_context_restriction(\context_course::instance($course->id));

        try {
            $e = $this->caught(fn() => read_context_file::execute('plan.md'));
        } finally {
            \core_external\external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }
}
