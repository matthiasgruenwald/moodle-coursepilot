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

use core_external\external_api;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The web service context restriction and the own-files right are checked before the tools act (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(create_workbench_download_links::class)]
#[CoversClass(list_activity_versions::class)]
#[CoversClass(dismiss_previous_location::class)]
final class restriction_guards_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Runs a call while the web service is restricted to an unrelated course.
     *
     * @param callable $call The call.
     * @return \Throwable|null
     */
    private function restricted(callable $call): ?\Throwable {
        $other = $this->getDataGenerator()->create_course();
        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            return $this->caught($call);
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
    }

    public function test_the_workbench_links_are_refused_outside_the_restricted_context(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->restricted(fn() => create_workbench_download_links::execute(['worksheet.pdf']));

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    public function test_the_workbench_links_need_the_right_to_manage_own_files(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/user:manageownfiles', CAP_PROHIBIT, $roleid, \context_user::instance($user->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $e = $this->caught(fn() => create_workbench_download_links::execute(['worksheet.pdf']));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_the_version_list_is_refused_outside_the_restricted_context(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $this->getDataGenerator()->create_course()->id,
        ]);

        $e = $this->restricted(fn() => list_activity_versions::execute((int) $page->cmid));

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    public function test_dismissing_the_previous_location_is_refused_outside_the_restricted_context(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->restricted(fn() => dismiss_previous_location::execute());

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }
}
