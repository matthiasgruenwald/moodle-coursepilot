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

namespace local_coursepilot\check;

use core\check\result;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Statusprüfung Schritt 1 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): aus heisst `INFO` ("optional"), solange kein Pointer extern zeigt,
 * sonst `WARNING` mit Anzahl.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_repository_check::class)]
final class webdav_repository_check_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_is_info_when_inactive_and_no_external_pointer(): void {
        $this->resetAfterTest();

        $result = (new webdav_repository_check())->get_result();

        $this->assertSame(result::INFO, $result->get_status());
    }

    public function test_is_warning_when_inactive_but_pointer_points_external(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');
        // create_webdav_instance() aktiviert den Repository-Typ als
        // Nebenwirkung (enable_webdav_repository_type()) - hier wieder
        // ausgeschaltet, um den Fall "aus, aber Pointer zeigt extern" zu bauen.
        global $DB;
        $DB->set_field('repository', 'visible', 0, ['type' => 'webdav']);

        $result = (new webdav_repository_check())->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('1', $result->get_summary());
    }

    public function test_is_ok_when_active(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_webdav_repository_type();

        $result = (new webdav_repository_check())->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }
}
