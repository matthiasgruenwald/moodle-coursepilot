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
use local_coursepilot\previous_location;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\webdav_instance_fixture;

defined('MOODLE_INTERNAL') || die();

/**
 * Explicitly dismiss the previous context location (Issue #498,
 * Spec #486 §9), following dismiss_pending_entry_test.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(dismiss_previous_location::class)]
final class dismiss_previous_location_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Clear the previous location while preserving current context/material
     * targets and location history.
     */
    public function test_dismisses_open_previouslocation(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->write_pointer_with_previous_location($user);

        $result = dismiss_previous_location::execute();
        $result = external_api::clean_returnvalue(dismiss_previous_location::execute_returns(), $result);

        $this->assertNotEmpty($result['message']);
        $this->assertFalse(previous_location::open());
        $document = storage_anchor::read_raw_pointer();
        $this->assertSame('coursepilot', $document['context_area']['path']);
        $this->assertArrayNotHasKey('previous_location', $document);
    }

    /**
     * No previous location produces a named error rather than silent success.
     */
    public function test_rejects_when_nothing_is_open(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            dismiss_previous_location::execute();
            $this->fail('Without open legacy items this should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('previouslocationclosed', $e->errorcode);
        }
    }

    /**
     * Require moodle/user:manageownfiles, as in dismiss_pending_entry.
     */
    public function test_rejects_missing_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->write_pointer_with_previous_location($user);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        dismiss_previous_location::execute();
    }

    /**
     * External previous locations can be dismissed without manageownfiles
     * (Issue #517, Spec §6); that permission applies only within Moodle.
     */
    public function test_dismisses_external_previouslocation_without_manageownfiles_capability(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $this->write_pointer_with_external_previous_location($user, $instanceid);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $roleid,
            \context_user::instance($user->id)->id,
            true
        );

        $result = dismiss_previous_location::execute();
        $result = external_api::clean_returnvalue(dismiss_previous_location::execute_returns(), $result);

        $this->assertNotEmpty($result['message']);
        $this->assertFalse(previous_location::open());
    }

    /**
     * Users cannot dismiss another user's previous location.
     */
    public function test_person_a_cannot_dismiss_person_bs_previouslocation(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        $this->write_pointer_with_previous_location($teachera);

        $this->setUser($teacherb);
        try {
            dismiss_previous_location::execute();
            $this->fail('Without own legacy items this should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('previouslocationclosed', $e->errorcode);
        }
        $this->assertTrue(true);

        $this->setUser($teachera);
        $this->assertTrue(previous_location::open(), "Person A's legacy items must stay untouched.");
    }

    /**
     * The endpoint belongs to the Coursepilot service and allowlist.
     */
    public function test_registered_in_service_and_allowlist(): void {
        $this->assertArrayHasKey(
            'coursepilot_dismiss_previous_location',
            \local_coursepilot\privacy_surface::allowed_tools()
        );
        $this->assertContains(
            'local_coursepilot_dismiss_previous_location',
            \local_coursepilot\tool_registry::service_function_names()
        );
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_dismiss_previous_location'));
    }
}
