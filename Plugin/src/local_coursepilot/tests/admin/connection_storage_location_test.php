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

namespace local_coursepilot\admin;

use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Connections overview storage column (Issue #499, Spec #486 §12):
 * per-target state and markers for unapproved hosts, pending writes,
 * previous locations and broken pointers, without network/path access.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection_storage_location::class)]
final class connection_storage_location_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_both_targets_are_offen_without_any_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $description = connection_storage_location::describe((int) $user->id);

        $offen = get_string('storagelocationopen', 'local_coursepilot');
        $this->assertStringContainsString($offen, $description['targets']['context_area']);
        $this->assertStringContainsString($offen, $description['targets']['material_store']);
        $this->assertSame([], $description['markers']);
    }

    public function test_marks_a_host_that_is_not_on_the_approved_list(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Kontext');
        // Empty personaldatahosts leaves the instance host unapproved.

        $description = connection_storage_location::describe((int) $user->id);

        $this->assertContains(get_string('storagelocationmarkernotallowed', 'local_coursepilot'), $description['markers']);
    }

    public function test_does_not_mark_an_approved_host(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Kontext');
        set_config('personaldatahosts', $this->fixtureserver, 'local_coursepilot');

        $description = connection_storage_location::describe((int) $user->id);

        $this->assertNotContains(get_string('storagelocationmarkernotallowed', 'local_coursepilot'), $description['markers']);
    }

    public function test_marks_an_open_previouslocation(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->write_pointer_with_previous_location($user);

        $description = connection_storage_location::describe((int) $user->id);

        $this->assertContains(get_string('storagelocationmarkerpreviouslocation', 'local_coursepilot'), $description['markers']);
    }

    public function test_marks_an_open_ausstand(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-pending.json',
        ], json_encode(['ABCDEFGH' => ['timestamp' => time(), 'path' => 'a.md', 'operation' => 'create', 'error_class' => 'x']]));

        $description = connection_storage_location::describe((int) $user->id);

        $this->assertContains(get_string('storagelocationmarkerpending', 'local_coursepilot'), $description['markers']);
    }

    public function test_marks_a_broken_pointer_with_a_missing_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->write_v2_pointer($user, 'context_area', 999999, 'Kontext');

        $description = connection_storage_location::describe((int) $user->id);

        $expected = get_string('storagelocationmarkerdefect', 'local_coursepilot', get_string('storagelocationdefectinstancemissing', 'local_coursepilot'));
        $this->assertContains($expected, $description['markers']);
    }

    public function test_does_not_reveal_the_chosen_path(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Unterricht/Geheim');

        $description = connection_storage_location::describe((int) $user->id);

        $this->assertStringNotContainsString('Unterricht', $description['targets']['context_area']);
        $this->assertStringNotContainsString('Geheim', $description['targets']['context_area']);
        $this->assertStringContainsString($this->fixtureserver, $description['targets']['context_area']);
    }
}
