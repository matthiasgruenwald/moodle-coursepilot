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
 * Statusprüfung Schritt 2 des WebDAV-Schrittkatalogs (Issue #499, Spec #486
 * §12): `NA` solange Schritt 1 aus ist, sonst `WARNING`, wenn Nutzerinstanzen
 * nicht erlaubt sind, sonst `OK`.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(webdav_user_instances_check::class)]
final class webdav_user_instances_check_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_is_na_when_repository_inactive(): void {
        $this->resetAfterTest();

        $result = (new webdav_user_instances_check())->get_result();

        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_is_warning_when_user_instances_not_allowed(): void {
        $this->resetAfterTest();
        global $DB;
        $DB->insert_record('repository', (object) ['type' => 'webdav', 'visible' => 1, 'sortorder' => 1]);
        set_config('enableuserinstances', 0, 'webdav');

        $result = (new webdav_user_instances_check())->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
    }

    public function test_is_ok_when_user_instances_allowed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_webdav_repository_type();

        $result = (new webdav_user_instances_check())->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }
}
