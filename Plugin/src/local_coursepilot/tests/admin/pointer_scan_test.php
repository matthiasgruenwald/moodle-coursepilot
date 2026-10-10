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

namespace local_coursepilot\admin;

use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Read arbitrary users' pointers and pending notes without $USER or
 * network access (Issue #499, Spec #486 §12), supporting setup checks
 * and the storage-location column.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(pointer_scan::class)]
final class pointer_scan_test extends \advanced_testcase {
    use webdav_instance_fixture;

    /**
     * Storage states and defects use named constants shared by pointer_scan
     * and connection_storage_location (Issue #507, Spec #486 review).
     */
    public function test_state_and_defect_constants_have_the_expected_values(): void {
        $this->assertSame('open', pointer_scan::STATE_OPEN);
        $this->assertSame('moodle', pointer_scan::STATE_MOODLE);
        $this->assertSame('external', pointer_scan::STATE_EXTERNAL);
        $this->assertSame('broken', pointer_scan::STATE_BROKEN);
        $this->assertSame('instance_missing', pointer_scan::DEFECT_INSTANCE_MISSING);
        $this->assertSame('foreign_instance', pointer_scan::DEFECT_FOREIGN_INSTANCE);
        $this->assertSame('http', pointer_scan::DEFECT_HTTP);
        $this->assertSame('invalid', pointer_scan::DEFECT_INVALID);
    }

    public function test_raw_pointer_for_is_null_without_pointer_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull(pointer_scan::raw_pointer_for((int) $user->id));
    }

    public function test_raw_pointer_for_reads_a_foreign_users_pointer_without_setuser(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'context_area', $instanceid, 'Kontext');

        $decoded = pointer_scan::raw_pointer_for((int) $user->id);

        $this->assertSame('external', $decoded['context_area']['location']);
    }

    public function test_userids_with_pointer_finds_only_persons_with_a_pointer_file(): void {
        $this->resetAfterTest();
        $withpointer = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($withpointer);
        $this->write_v2_pointer($withpointer, 'context_area', $instanceid, 'Kontext');
        $this->getDataGenerator()->create_user();

        $userids = pointer_scan::userids_with_pointer();

        $this->assertSame([(int) $withpointer->id], $userids);
    }

    public function test_userids_with_external_target_finds_only_external_pointers(): void {
        $this->resetAfterTest();
        $external = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($external);
        $this->write_v2_pointer($external, 'context_area', $instanceid, 'Kontext');
        $inmoodle = $this->getDataGenerator()->create_user();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($inmoodle->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-location.json',
        ], json_encode([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ]));

        $userids = pointer_scan::userids_with_external_target();

        $this->assertSame([(int) $external->id], $userids);
    }

    public function test_target_state_is_offen_without_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $state = pointer_scan::target_state((int) $user->id, null, 'context_area');

        $this->assertSame('open', $state['state']);
    }

    public function test_target_state_is_moodle_for_a_moodle_target(): void {
        $this->resetAfterTest();
        $decoded = [
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ];

        $state = pointer_scan::target_state(1, $decoded, 'context_area');

        $this->assertSame('moodle', $state['state']);
        $this->assertNull($state['defect']);
    }

    public function test_target_state_is_extern_with_host_for_a_valid_external_target(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        // Build directly rather than through write_v2_pointer(), isolating resolution.
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => $instanceid,
                'path' => 'Kontext',
                'fingerprint' => $this->fixture_fingerprint(),
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ];

        $state = pointer_scan::target_state((int) $user->id, $decoded, 'context_area');

        $this->assertSame('external', $state['state']);
        $this->assertSame($this->fixtureserver, $state['host']);
        $this->assertNull($state['defect']);
    }

    public function test_target_state_defect_is_instanzfehlt_for_a_missing_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => 999999,
                'path' => 'Kontext',
                'fingerprint' => $this->fixture_fingerprint(),
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ];

        $state = pointer_scan::target_state((int) $user->id, $decoded, 'context_area');

        $this->assertSame('external', $state['state']);
        $this->assertSame('instance_missing', $state['defect']);
    }

    public function test_target_state_defect_is_fremdeinstanz_for_someone_elses_instance(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($owner);
        $stranger = $this->getDataGenerator()->create_user();
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => $instanceid,
                'path' => 'Kontext',
                'fingerprint' => $this->fixture_fingerprint(),
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ];

        $state = pointer_scan::target_state((int) $stranger->id, $decoded, 'context_area');

        $this->assertSame('foreign_instance', $state['defect']);
    }

    public function test_target_state_defect_is_http_for_a_non_https_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user, ['webdav_type' => 0]);
        $decoded = [
            'context_area' => [
                'location' => 'external',
                'instanceid' => $instanceid,
                'path' => 'Kontext',
                'fingerprint' => $this->fixture_fingerprint(),
            ],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ];

        $state = pointer_scan::target_state((int) $user->id, $decoded, 'context_area');

        $this->assertSame('http', $state['defect']);
    }

    public function test_target_state_is_kaputt_for_a_structurally_invalid_pointer(): void {
        $this->resetAfterTest();
        $decoded = ['context_area' => ['location' => 'moodle', 'path' => 'coursepilot']];

        $state = pointer_scan::target_state(1, $decoded, 'context_area');

        $this->assertSame('broken', $state['state']);
    }

    public function test_has_open_previous_location_reads_vorheriger_ort_field(): void {
        $this->resetAfterTest();

        $this->assertTrue(pointer_scan::has_open_previous_location(['previous_location' => ['location' => 'moodle', 'path' => 'alt']]));
        $this->assertFalse(pointer_scan::has_open_previous_location(['context_area' => []]));
        $this->assertFalse(pointer_scan::has_open_previous_location(null));
    }

    public function test_has_open_pending_reads_a_foreign_users_ausstandsnotiz(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(pointer_scan::has_open_pending((int) $user->id));

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/coursepilot/',
            'filename' => '.coursepilot-pending.json',
        ], json_encode(['ABCDEFGH' => ['timestamp' => time(), 'path' => 'a.md', 'operation' => 'create', 'error_class' => 'x']]));

        $this->assertTrue(pointer_scan::has_open_pending((int) $user->id));
    }
}
