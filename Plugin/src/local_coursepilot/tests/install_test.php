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

/**
 * Installation smoke test on Moodle 5.0 (acceptance #309 criterion 1).
 * Running tests already requires successful installation; verify the
 * registered foundations used by the rest of the plugin.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class install_test extends \advanced_testcase {

    /**
     * The installed plugin requires at least Moodle 5.0.
     */
    public function test_plugin_is_installed_and_requires_moodle_50(): void {
        global $CFG;

        $this->resetAfterTest();

        $installed = get_config('local_coursepilot', 'version');
        $this->assertNotFalse($installed, 'local_coursepilot is not installed.');

        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/coursepilot/version.php');

        $this->assertSame('local_coursepilot', $plugin->component);
        $this->assertEquals($plugin->version, $installed);
        // 2025041400 is Moodle 5.0’s version stamp (#300, item 10).
        $this->assertGreaterThanOrEqual(2025041400, $plugin->requires);
    }

    /**
     * Both capabilities from #296 are registered in the correct contexts.
     */
    public function test_capabilities_are_registered(): void {
        global $DB;

        $this->resetAfterTest();

        $use = $DB->get_record('capabilities', ['name' => 'local/coursepilot:use']);
        $this->assertNotEmpty($use, 'local/coursepilot:use fehlt.');
        $this->assertEquals(CONTEXT_COURSE, $use->contextlevel);

        $remote = $DB->get_record('capabilities', ['name' => 'local/coursepilot:useremote']);
        $this->assertNotEmpty($remote, 'local/coursepilot:useremote fehlt.');
        $this->assertEquals(CONTEXT_SYSTEM, $remote->contextlevel);
    }

    /**
     * The external service exists and is enabled.
     */
    public function test_external_service_is_registered(): void {
        global $DB;

        $this->resetAfterTest();

        $service = $DB->get_record('external_services', ['shortname' => privacy_surface::SERVICE_SHORTNAME]);
        $this->assertNotEmpty($service, 'Externer Dienst "coursepilot" fehlt.');
        $this->assertEquals(1, $service->enabled);
    }
}
