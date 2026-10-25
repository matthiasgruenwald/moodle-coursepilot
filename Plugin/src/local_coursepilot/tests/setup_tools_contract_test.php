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

use local_coursepilot\admin\connection_storage_location;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_setup_steps;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact steps, enablement and storage location lines for the WebDAV setup and the administrator overview.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(webdav_setup_steps::class)]
#[CoversClass(connection_storage_location::class)]
final class setup_tools_contract_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_the_three_steps_carry_their_instruction_and_target_page(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $steps = webdav_setup_steps::catalog((int) $user->id);

        $this->assertSame(
            [
                webdav_setup_steps::STEP_REPOSITORY_ACTIVE,
                webdav_setup_steps::STEP_USER_INSTANCES,
                webdav_setup_steps::STEP_CAPABILITY,
            ],
            array_keys($steps)
        );
        foreach ($steps as $step) {
            $this->assertSame(['ok', 'instruction', 'targeturl'], array_keys($step));
        }
        $this->assertSame(
            get_string('webdavstep1instruction', 'local_coursepilot'),
            $steps[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['instruction']
        );
        $this->assertSame(
            get_string('webdavstep2instruction', 'local_coursepilot'),
            $steps[webdav_setup_steps::STEP_USER_INSTANCES]['instruction']
        );
        $this->assertSame(
            get_string('webdavstep3instruction', 'local_coursepilot'),
            $steps[webdav_setup_steps::STEP_CAPABILITY]['instruction']
        );
        $this->assertSame(
            (new \moodle_url('/admin/repository.php'))->out(false),
            $steps[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['targeturl']->out(false)
        );
        $this->assertSame(
            (new \moodle_url('/admin/repository.php'))->out(false),
            $steps[webdav_setup_steps::STEP_USER_INSTANCES]['targeturl']->out(false)
        );
        $this->assertSame(
            (new \moodle_url('/admin/roles/assign.php', ['contextid' => SYSCONTEXTID]))->out(false),
            $steps[webdav_setup_steps::STEP_CAPABILITY]['targeturl']->out(false)
        );
    }

    public function test_each_step_is_judged_on_its_own_and_only_all_three_enable_the_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $id = (int) $user->id;

        $none = webdav_setup_steps::catalog($id);
        $this->assertSame([false, false, false], array_values(array_map(static fn($s) => $s['ok'], $none)));
        $this->assertFalse(webdav_setup_steps::enabled_for_user($id));

        $this->enable_webdav_repository_type();
        $repository = webdav_setup_steps::catalog($id);
        $this->assertTrue($repository[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['ok']);
        $this->assertTrue($repository[webdav_setup_steps::STEP_USER_INSTANCES]['ok']);
        $this->assertFalse($repository[webdav_setup_steps::STEP_CAPABILITY]['ok']);
        $this->assertFalse(webdav_setup_steps::enabled_for_user($id));

        $this->grant_webdav_capability($user);
        $this->assertTrue(webdav_setup_steps::enabled_for_user($id));

        set_config('enableuserinstances', 0, 'webdav');
        $this->assertFalse(webdav_setup_steps::enabled_for_user($id));
        $this->assertFalse(webdav_setup_steps::catalog($id)[webdav_setup_steps::STEP_USER_INSTANCES]['ok']);
        set_config('enableuserinstances', 1, 'webdav');
        foreach ([0, 2] as $visible) {
            $DB->set_field('repository', 'visible', $visible, ['type' => 'webdav']);
            $this->assertFalse(
                webdav_setup_steps::catalog($id)[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['ok'],
                (string) $visible
            );
            $this->assertFalse(webdav_setup_steps::enabled_for_user($id));
        }
        $DB->delete_records('repository', ['type' => 'webdav']);
        $this->assertFalse(webdav_setup_steps::catalog($id)[webdav_setup_steps::STEP_REPOSITORY_ACTIVE]['ok']);
        $this->assertFalse(webdav_setup_steps::catalog(0)[webdav_setup_steps::STEP_CAPABILITY]['ok']);
    }

    public function test_the_storage_location_lists_one_line_per_target_and_each_defect_only_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $fingerprint = ['server' => 'cloud.example.test', 'basepath' => '', 'account' => 'a'];
        $external = static fn(string $path): array => [
            'location' => 'external', 'instanceid' => 987654, 'path' => $path, 'fingerprint' => $fingerprint,
        ];
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-location.json',
        ], json_encode(['context_area' => $external('A'), 'material_store' => $external('B')]));

        $location = connection_storage_location::describe((int) $user->id);

        $this->assertSame(['context_area', 'material_store'], array_keys($location['targets']));
        $this->assertSame(
            get_string('storagelocationtargetcontextarea', 'local_coursepilot') . ': '
                . get_string('storagelocationexternal', 'local_coursepilot', 'cloud.example.test'),
            $location['targets']['context_area']
        );
        $this->assertSame(
            get_string('storagelocationtargetmaterialstore', 'local_coursepilot') . ': '
                . get_string('storagelocationexternal', 'local_coursepilot', 'cloud.example.test'),
            $location['targets']['material_store']
        );
        $this->assertContains(get_string('storagelocationmarkernotallowed', 'local_coursepilot'), $location['markers']);
        $defects = array_filter(
            $location['markers'],
            static fn(string $m): bool => $m !== get_string('storagelocationmarkernotallowed', 'local_coursepilot')
        );
        $this->assertCount(1, $defects);
        $this->assertSame(array_values($defects), array_values(array_unique($defects)));
    }

    public function test_without_a_pointer_both_targets_are_open_and_there_are_no_markers(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $location = connection_storage_location::describe((int) $user->id);

        $this->assertSame(
            get_string('storagelocationtargetcontextarea', 'local_coursepilot') . ': ' . get_string(
                'storagelocationopen',
                'local_coursepilot'
            ),
            $location['targets']['context_area']
        );
        $this->assertSame([], $location['markers']);
    }
}
