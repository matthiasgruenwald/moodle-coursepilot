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

defined('MOODLE_INTERNAL') || die();

/**
 * Version information (#425 F3): Moodle and plugin versions for question-type
 * reference headers, instance checking (#340) and support.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_version_info::class)]
final class get_version_info_test extends \advanced_testcase {
    /**
     * Return nonempty Moodle release/version/branch and plugin version/release
     * from version.php, without placeholders.
     */
    public function test_reports_moodle_and_plugin_versions(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = get_version_info::execute();
        $result = external_api::clean_returnvalue(get_version_info::execute_returns(), $result);

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/coursepilot/version.php');

        $this->assertSame($CFG->release, $result['moodle_release']);
        $this->assertSame((string) $CFG->version, $result['moodle_version']);
        $this->assertSame((string) $CFG->branch, $result['moodle_branch']);
        $this->assertSame((int) $plugin->version, $result['plugin_version']);
        $this->assertSame($plugin->release, $result['plugin_release']);
    }

    /**
     * date supplies the question-type reference verification date as YYYY-MM-DD,
     * without timestamp reformatting.
     */
    public function test_date_is_iso_date(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = get_version_info::execute();
        $result = external_api::clean_returnvalue(get_version_info::execute_returns(), $result);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['date']);
    }

    /**
     * The teacher-facing summary contains the same versions in plain text.
     */
    public function test_message_names_both_versions(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = get_version_info::execute();
        $result = external_api::clean_returnvalue(get_version_info::execute_returns(), $result);

        $this->assertStringContainsString($CFG->release, $result['message']);
        $this->assertStringContainsString((string) $result['plugin_version'], $result['message']);
    }
}
