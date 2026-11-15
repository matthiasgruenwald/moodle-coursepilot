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
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\plugin_meta::class)]
final class get_version_info_test extends \advanced_testcase {
    use \local_coursepilot\tests\caught_throwable;

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

    /**
     * A stored plugin version that lags behind version.php is flagged, so a deployment
     * without upgrade.php stays visible to support.
     */
    public function test_warns_when_the_installed_version_lags_behind_the_code(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        set_config('version', 2020010100, 'local_coursepilot');

        $result = external_api::clean_returnvalue(get_version_info::execute_returns(), get_version_info::execute());

        $this->assertSame(2020010100, $result['plugin_version_db']);
        $this->assertStringContainsString(
            get_string('versioninfoupgradewarning', 'local_coursepilot', 2020010100),
            $result['message']
        );
    }

    /**
     * The tool takes no arguments.
     */
    public function test_declares_no_parameters(): void {
        $this->assertSame([], get_version_info::execute_parameters()->keys);
    }

    /**
     * The untouched response: exact keys, values, types and the summary sentence when the database is up to date.
     */
    public function test_raw_response_is_exact_when_the_installation_is_current(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $plugin = \local_coursepilot\plugin_meta::current();
        set_config('version', $plugin->version, 'local_coursepilot');

        $result = get_version_info::execute();

        $this->assertSame([
            'moodle_release' => $CFG->release,
            'moodle_version' => (string) $CFG->version,
            'moodle_branch' => $CFG->branch,
            'plugin_version' => $plugin->version,
            'plugin_release' => $plugin->release,
            'plugin_version_db' => $plugin->version,
            'date' => date('Y-m-d'),
            'message' => 'Moodle ' . $CFG->release . ' (branch ' . $CFG->branch . '), Coursepilot plugin '
                . $plugin->release . ' (version ' . $plugin->version . ').',
        ], $result);
    }

    /**
     * Without a registered plugin version the database version is reported as 0 and no upgrade warning appears.
     */
    public function test_unregistered_plugin_reports_zero_without_a_warning(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        unset_config('version', 'local_coursepilot');

        $result = get_version_info::execute();

        $this->assertSame(0, $result['plugin_version_db']);
        $this->assertStringNotContainsString('upgrade.php', $result['message']);
    }

    /**
     * A lagging database version appends the upgrade warning after one blank, as its own sentence.
     */
    public function test_upgrade_warning_follows_the_summary_after_one_blank(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $plugin = \local_coursepilot\plugin_meta::current();
        set_config('version', 2020010100, 'local_coursepilot');

        $result = get_version_info::execute();

        $this->assertSame(
            'Moodle ' . $CFG->release . ' (branch ' . $CFG->branch . '), Coursepilot plugin ' . $plugin->release
                . ' (version ' . $plugin->version . '). Warning: The database records version 2020010100'
                . ' - upgrade.php was not run after the last deployment.',
            $result['message']
        );
    }

    /**
     * A web service restricted to a course never reaches the site-wide version information.
     */
    public function test_respects_the_external_context_restriction(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $course = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($course->id));
        try {
            $e = $this->caught(fn() => get_version_info::execute());
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }
}
