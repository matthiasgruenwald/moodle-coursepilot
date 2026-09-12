<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_kurspilot\check;

use core\check\result;
use local_kurspilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Statusprüfung Schritt 1 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): aus heisst `INFO` ("optional"), solange kein Pointer extern zeigt,
 * sonst `WARNING` mit Anzahl.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
