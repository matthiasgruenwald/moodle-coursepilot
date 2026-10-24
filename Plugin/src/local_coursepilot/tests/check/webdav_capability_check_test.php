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
use local_coursepilot\connection;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * WebDAV setup check 3 (Issue #499, Spec #486 §12): effective capability
 * per connected user. Return NA without connections or an active repository,
 * otherwise OK/WARNING according to has_capability().
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_capability_check::class)]
final class webdav_capability_check_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Provides issue token.
     *
     * @param int $userid The userid.
     */
    private function issue_token(int $userid): void {
        global $DB;

        $connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $userid, 'clientid' => 'test-client', 'revoked' => 0,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => time(),
        ]);
        $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'connectionid' => $connectionid,
            'accesstokenhash' => hash('sha256', bin2hex(random_bytes(32))),
            'refreshtokenhash' => hash('sha256', bin2hex(random_bytes(32))),
            'clientid' => 'test-client',
            'userid' => $userid,
            'expires' => time() + connection::ACCESS_TOKEN_TTL,
            'refreshexpires' => time() + connection::REFRESH_TOKEN_TTL,
            'revoked' => 0,
            'timecreated' => time(),
        ]);
    }

    public function test_is_na_when_repository_inactive(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->issue_token((int) $user->id);

        $result = (new webdav_capability_check())->get_result();

        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_is_na_without_any_connection(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_webdav_repository_type();

        $result = (new webdav_capability_check())->get_result();

        $this->assertSame(result::NA, $result->get_status());
    }

    public function test_is_ok_when_all_connected_persons_have_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_webdav_repository_type();
        $user = $this->getDataGenerator()->create_user();
        $this->grant_webdav_capability($user);
        $this->issue_token((int) $user->id);

        $result = (new webdav_capability_check())->get_result();

        $this->assertSame(result::OK, $result->get_status());
    }

    public function test_is_warning_when_a_connected_person_is_missing_the_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->enable_webdav_repository_type();
        $withcapability = $this->getDataGenerator()->create_user(['firstname' => 'Hat', 'lastname' => 'Recht']);
        $this->grant_webdav_capability($withcapability);
        $this->issue_token((int) $withcapability->id);
        $withoutcapability = $this->getDataGenerator()->create_user(['firstname' => 'Ohne', 'lastname' => 'Recht']);
        $this->issue_token((int) $withoutcapability->id);

        $result = (new webdav_capability_check())->get_result();

        $this->assertSame(result::WARNING, $result->get_status());
        $this->assertStringContainsString('1', $result->get_summary());
        $this->assertStringContainsString('2', $result->get_summary());
        $this->assertStringContainsString('Ohne Recht', $result->get_details());
        $this->assertStringNotContainsString('Hat Recht', $result->get_details());
    }

    public function test_identifies_itself_for_the_status_report(): void {
        $this->resetAfterTest();
        $check = new webdav_capability_check();

        $this->assertSame('webdav_capability', $check->get_id());
        $this->assertSame(get_string('webdavcheck3name', 'local_coursepilot'), $check->get_name());
    }
}
