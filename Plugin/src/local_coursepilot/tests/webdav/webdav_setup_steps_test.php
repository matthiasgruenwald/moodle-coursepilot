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

namespace local_coursepilot\webdav;

use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Setup steps without an authenticated user (Issue #505 finding #1).
 * CLI checks use USER id 0; context_user::instance(0) would fail the entire
 * run. Return false rather than throwing, while system steps 1/2 are
 * user-independent.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_setup_steps::class)]
final class webdav_setup_steps_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_catalog_does_not_throw_for_userid_zero(): void {
        $this->resetAfterTest();
        $this->enable_webdav_repository_type();

        $steps = webdav_setup_steps::catalog(0);

        $this->assertFalse($steps[webdav_setup_steps::STEP_CAPABILITY]['ok']);
    }

    public function test_catalog_still_reports_capability_for_real_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_webdav_instance($user);

        $steps = webdav_setup_steps::catalog((int) $user->id);

        $this->assertIsBool($steps[webdav_setup_steps::STEP_CAPABILITY]['ok']);
    }

    /**
     * Evaluate user instances (step 2) and capability (step 3) independently
     * of repository activation (step 1), so disabled repositories do not
     * make existing configuration appear missing (Issue #528).
     */
    public function test_step_two_and_three_stay_ok_when_step_one_is_off(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_webdav_instance($user);
        $this->grant_webdav_capability($user);
        // Without a third argument, catalog has_capability() checks USER rather
        // than userid, as in the WebDAV checks. Log in as the user under test.
        $this->setUser($user);

        global $DB;
        $DB->set_field('repository', 'visible', 0, ['type' => 'webdav']);

        $steps = webdav_setup_steps::catalog((int) $user->id);

        $this->assertFalse($steps[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['ok']);
        $this->assertTrue($steps[webdav_setup_steps::STEP_USER_INSTANCES]['ok']);
        $this->assertTrue($steps[webdav_setup_steps::STEP_CAPABILITY]['ok']);
    }

    /**
     * enabled_for_user() still requires all three steps despite catalog()
     * decoupling (Issue #528).
     */
    public function test_enabled_for_user_still_requires_all_three_steps(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_webdav_instance($user);
        $this->grant_webdav_capability($user);
        $this->setUser($user);

        global $DB;
        $DB->set_field('repository', 'visible', 0, ['type' => 'webdav']);

        $this->assertFalse(webdav_setup_steps::enabled_for_user((int) $user->id));
    }
}
