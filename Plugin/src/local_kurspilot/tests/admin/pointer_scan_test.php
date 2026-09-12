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

namespace local_kurspilot\admin;

use local_kurspilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Pointer- und Ausstandslesen fuer beliebige Personen, ohne $USER-Bezug und
 * ohne Netz (Issue #499, Spec #486 §12) - Grundlage der Statusprüfungen und
 * der Spalte Ablageort.
 *
 * @package    local_kurspilot
 * @copyright  2026 Kurspilot
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(pointer_scan::class)]
final class pointer_scan_test extends \advanced_testcase {
    use webdav_instance_fixture;

    public function test_raw_pointer_for_is_null_without_pointer_file(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertNull(pointer_scan::raw_pointer_for((int) $user->id));
    }

    public function test_raw_pointer_for_reads_a_foreign_users_pointer_without_setuser(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        $this->write_v2_pointer($user, 'kontextbereich', $instanceid, 'Kontext');

        $decoded = pointer_scan::raw_pointer_for((int) $user->id);

        $this->assertSame('extern', $decoded['kontextbereich']['ort']);
    }

    public function test_userids_with_pointer_finds_only_persons_with_a_pointer_file(): void {
        $this->resetAfterTest();
        $withpointer = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($withpointer);
        $this->write_v2_pointer($withpointer, 'kontextbereich', $instanceid, 'Kontext');
        $this->getDataGenerator()->create_user();

        $userids = pointer_scan::userids_with_pointer();

        $this->assertSame([(int) $withpointer->id], $userids);
    }

    public function test_userids_with_external_target_finds_only_external_pointers(): void {
        $this->resetAfterTest();
        $external = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($external);
        $this->write_v2_pointer($external, 'kontextbereich', $instanceid, 'Kontext');
        $inmoodle = $this->getDataGenerator()->create_user();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($inmoodle->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/',
            'filename' => '.kurspilot-ort.json',
        ], json_encode([
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'kurspilot'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ]));

        $userids = pointer_scan::userids_with_external_target();

        $this->assertSame([(int) $external->id], $userids);
    }

    public function test_target_state_is_offen_without_pointer(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $state = pointer_scan::target_state((int) $user->id, null, 'kontextbereich');

        $this->assertSame('offen', $state['state']);
    }

    public function test_target_state_is_moodle_for_a_moodle_target(): void {
        $this->resetAfterTest();
        $decoded = [
            'kontextbereich' => ['ort' => 'moodle', 'pfad' => 'kurspilot'],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ];

        $state = pointer_scan::target_state(1, $decoded, 'kontextbereich');

        $this->assertSame('moodle', $state['state']);
        $this->assertNull($state['defect']);
    }

    public function test_target_state_is_extern_with_host_for_a_valid_external_target(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user);
        // Von Hand aufgebaut statt write_v2_pointer(), damit hier nur die
        // Aufloesung getestet wird.
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => $instanceid,
                'pfad' => 'Kontext',
                'pruefmerkmal' => $this->fixture_fingerprint(),
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ];

        $state = pointer_scan::target_state((int) $user->id, $decoded, 'kontextbereich');

        $this->assertSame('extern', $state['state']);
        $this->assertSame($this->fixtureserver, $state['host']);
        $this->assertNull($state['defect']);
    }

    public function test_target_state_defect_is_instanzfehlt_for_a_missing_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => 999999,
                'pfad' => 'Kontext',
                'pruefmerkmal' => $this->fixture_fingerprint(),
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ];

        $state = pointer_scan::target_state((int) $user->id, $decoded, 'kontextbereich');

        $this->assertSame('extern', $state['state']);
        $this->assertSame('instanzfehlt', $state['defect']);
    }

    public function test_target_state_defect_is_fremdeinstanz_for_someone_elses_instance(): void {
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($owner);
        $stranger = $this->getDataGenerator()->create_user();
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => $instanceid,
                'pfad' => 'Kontext',
                'pruefmerkmal' => $this->fixture_fingerprint(),
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ];

        $state = pointer_scan::target_state((int) $stranger->id, $decoded, 'kontextbereich');

        $this->assertSame('fremdeinstanz', $state['defect']);
    }

    public function test_target_state_defect_is_http_for_a_non_https_instance(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $instanceid = $this->create_webdav_instance($user, ['webdav_type' => 0]);
        $decoded = [
            'kontextbereich' => [
                'ort' => 'extern',
                'instanzid' => $instanceid,
                'pfad' => 'Kontext',
                'pruefmerkmal' => $this->fixture_fingerprint(),
            ],
            'materialbestand' => ['ort' => 'moodle', 'pfad' => 'kurspilot-material'],
        ];

        $state = pointer_scan::target_state((int) $user->id, $decoded, 'kontextbereich');

        $this->assertSame('http', $state['defect']);
    }

    public function test_target_state_is_kaputt_for_a_structurally_invalid_pointer(): void {
        $this->resetAfterTest();
        $decoded = ['kontextbereich' => ['ort' => 'moodle', 'pfad' => 'kurspilot']];

        $state = pointer_scan::target_state(1, $decoded, 'kontextbereich');

        $this->assertSame('kaputt', $state['state']);
    }

    public function test_has_open_altbestand_reads_vorheriger_ort_field(): void {
        $this->resetAfterTest();

        $this->assertTrue(pointer_scan::has_open_altbestand(['vorheriger_ort' => ['ort' => 'moodle', 'pfad' => 'alt']]));
        $this->assertFalse(pointer_scan::has_open_altbestand(['kontextbereich' => []]));
        $this->assertFalse(pointer_scan::has_open_altbestand(null));
    }

    public function test_has_open_ausstand_reads_a_foreign_users_ausstandsnotiz(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->assertFalse(pointer_scan::has_open_ausstand((int) $user->id));

        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/kurspilot/',
            'filename' => '.kurspilot-ausstand.json',
        ], json_encode(['ABCDEFGH' => ['zeitpunkt' => time(), 'pfad' => 'a.md', 'vorgang' => 'anlegen', 'fehlerklasse' => 'x']]));

        $this->assertTrue(pointer_scan::has_open_ausstand((int) $user->id));
    }
}
