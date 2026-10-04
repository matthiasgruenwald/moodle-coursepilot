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
use local_coursepilot\material_files;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * List material storage (Spec 0018 §2, #428): file paths, sizes,
 * contenthashes, modification times and remaining quota. #495 adds
 * inventory/workbench selection, fake external WebDAV and the context-area
 * exclusion (Spec #486 §2/§7).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(list_material_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\storage_anchor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\material_files::class)]
final class list_material_files_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    public function test_lists_empty_root(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = list_material_files::execute();

        $this->assertSame('', $result['path']);
        $this->assertSame([], $result['entries']);
    }

    public function test_lists_uploaded_file_with_metadata(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;
        $stored = get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute();

        $this->assertCount(1, $result['entries']);
        $entry = $result['entries'][0];
        $this->assertSame('blatt.pdf', $entry['name']);
        $this->assertSame('file', $entry['type']);
        $this->assertSame(strlen('Inhalt'), $entry['size']);
        $this->assertSame($stored->get_contenthash(), $entry['contenthash']);
        $this->assertGreaterThan(0, $entry['timemodified']);
    }

    /**
     * Include remaining storage space in the response (#428).
     */
    public function test_reports_remaining_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 1000;

        $result = list_material_files::execute();

        $this->assertNotNull($result['remaining_quota_mb']);
    }

    public function test_remaining_quota_is_null_without_quota(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = 0;

        $result = list_material_files::execute();

        $this->assertNull($result['remaining_quota_mb']);
    }

    public function test_lists_subfolder(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/faecher/mathe/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute('faecher/mathe');

        $this->assertSame('faecher/mathe', $result['path']);
        $this->assertSame('blatt.pdf', $result['entries'][0]['name']);
    }

    public function test_rejects_traversal_path(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        list_material_files::execute('../../../etc');
    }

    /**
     * The context pointer (#445) lives in the context anchor, not material
     * storage. Still exclude it defensively if both roots ever coincide.
     */
    public function test_pointer_file_is_excluded_from_listing(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => \local_coursepilot\storage_anchor::POINTER_FILENAME,
        ], '{"context_area":"coursepilot","materialordner":"coursepilot-material"}');

        $result = list_material_files::execute();

        $this->assertSame([], $result['entries']);
    }

    /**
     * Without a context pointer, inventory and workbench share a location
     * (#495, criterion 1).
     */
    public function test_ort_bestand_and_ort_werkbank_show_the_same_place_in_moodle(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $bestand = list_material_files::execute('', material_files::LOCATION_STORE);
        $werkbank = list_material_files::execute('', material_files::LOCATION_WORKBENCH);

        $this->assertSame(['blatt.pdf'], array_column($bestand['entries'], 'name'));
        $this->assertSame(array_column($bestand['entries'], 'name'), array_column($werkbank['entries'], 'name'));
    }

    /**
     * A v1 pointer with a custom material path moves inventory and workbench
     * together instead of leaving the workbench at its default root
     * (#520, Spec #486 §1).
     */
    public function test_ort_bestand_and_ort_werkbank_follow_legacy_pointer_together(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => storage_anchor::own_context()->id,
            'component' => storage_anchor::COMPONENT,
            'filearea' => storage_anchor::FILEAREA,
            'itemid' => storage_anchor::ITEMID,
            'filepath' => '/' . storage_anchor::ANCHOR_DEFAULT_ROOT . '/',
            'filename' => storage_anchor::POINTER_FILENAME,
        ], json_encode(['context_area' => 'coursepilot', 'materialordner' => 'eigener-materialpfad']));
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/eigener-materialpfad/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $bestand = list_material_files::execute('', material_files::LOCATION_STORE);
        $werkbank = list_material_files::execute('', material_files::LOCATION_WORKBENCH);

        $this->assertSame(['blatt.pdf'], array_column($bestand['entries'], 'name'));
        $this->assertSame(array_column($bestand['entries'], 'name'), array_column($werkbank['entries'], 'name'));
    }

    public function test_unknown_ort_value_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            list_material_files::execute('', 'woanders');
            $this->fail('Ein unbekannter Ort-Wert haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidmateriallocation', $e->errorcode);
        }
    }

    /**
     * External material storage (#495, Spec #486 §2/§7) lists through WebDAV
     * with the same response contract; contenthash stays empty.
     */
    public function test_lists_external_material_via_webdav(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Coursepilot/Material');
        $fake->seed_file('/Coursepilot/Material/blatt.pdf', 'Inhalt');

        $result = list_material_files::execute();
        $result = external_api::clean_returnvalue(list_material_files::execute_returns(), $result);

        $entry = $this->find_entry($result['entries'], 'blatt.pdf');
        $this->assertNotNull($entry);
        $this->assertSame('file', $entry['type']);
        $this->assertSame('', $entry['contenthash']);
    }

    /**
     * Missing external folders produce an empty listing.
     */
    public function test_listing_missing_external_material_directory_is_empty(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();

        $result = list_material_files::execute();

        $this->assertSame([], $result['entries']);
    }

    /**
     * The workbench stays in Moodle when inventory is external. Writing
     * tools always target the workbench, which does not use the inventory
     * pointer (#495, criterion 2).
     */
    public function test_ort_werkbank_stays_in_moodle_when_material_is_external(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'werkbankdatei.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute('', material_files::LOCATION_WORKBENCH);

        $this->assertSame(['werkbankdatei.pdf'], array_column($result['entries'], 'name'));
    }

    /**
     * A context area in inventory appears as context_area, not as a
     * regular folder (#495, criterion 4).
     */
    public function test_kontextbereich_inside_bestand_appears_as_own_entry_type(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        // Context is deliberately nested within inventory, an allowed
        // direction (CONTEXT.md, Material inventory).
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot-material/kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/kontext/',
            'filename' => 'plan.md',
        ], '# Plan');
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $result = list_material_files::execute();

        $kontexteintrag = $this->find_entry($result['entries'], 'kontext');
        $this->assertNotNull($kontexteintrag);
        $this->assertSame('context_area', $kontexteintrag['type']);
        $materialeintrag = $this->find_entry($result['entries'], 'blatt.pdf');
        $this->assertSame('file', $materialeintrag['type']);
    }

    /**
     * Reject paths at or below the context area with a named error,
     * preventing access through material tools (#495, criterion 4).
     */
    public function test_listing_path_under_kontextbereich_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot-material/kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ]);

        try {
            list_material_files::execute('kontext');
            $this->fail('Ein Pfad unter dem Kontextbereich haette werfen muessen.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiscontext', $e->errorcode);
        }
    }

    /**
     * Issue #645: the material store returns the same field set at both
     * locations through the anchor's adapter; the weaker external check
     * value is not presented as a content hash.
     */
    public function test_store_listing_has_the_same_fields_at_both_locations(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');
        $moodle = external_api::clean_returnvalue(list_material_files::execute_returns(), list_material_files::execute());

        [, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Coursepilot/Material');
        $fake->seed_file('/Coursepilot/Material/blatt.pdf', 'Inhalt');
        $external = external_api::clean_returnvalue(list_material_files::execute_returns(), list_material_files::execute());

        $moodleentry = $this->find_entry($moodle['entries'], 'blatt.pdf');
        $externalentry = $this->find_entry($external['entries'], 'blatt.pdf');
        $this->assertSame(array_keys($moodleentry), array_keys($externalentry));
        $this->assertSame(sha1('Inhalt'), $moodleentry['contenthash']);
        $this->assertSame('', $externalentry['contenthash']);
        $this->assertSame($moodleentry['size'], $externalentry['size']);
    }

    /**
     * Issue #645: the workbench lists and reads through the anchor's
     * Private Files adapter with the unchanged directory, path and error
     * contract.
     */
    public function test_workbench_lists_and_reads_through_the_anchor(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/faecher/',
            'filename' => 'blatt.pdf',
        ], 'Inhalt');

        $listed = list_material_files::execute('faecher/', material_files::LOCATION_WORKBENCH);
        $this->assertSame('faecher', $listed['path']);
        $this->assertSame(sha1('Inhalt'), $this->find_entry($listed['entries'], 'blatt.pdf')['contenthash']);

        $read = \local_coursepilot\material_area::read_for_location(material_files::LOCATION_WORKBENCH, 'faecher/blatt.pdf');
        $this->assertSame('faecher/blatt.pdf', $read['path']);
        $this->assertSame('Inhalt', $read['content']);
        $this->assertSame(sha1('Inhalt'), $read['contenthash']);

        foreach (['../blatt.pdf', ''] as $invalid) {
            try {
                \local_coursepilot\material_area::read_for_location(material_files::LOCATION_WORKBENCH, $invalid);
                $this->fail('Invalid path accepted: ' . $invalid);
            } catch (\moodle_exception $e) {
                $this->assertSame('invalidmaterialpath', $e->errorcode);
            }
        }
    }

    /**
     * @param array $entries
     * @param string $name
     * @return array|null
     */
    private function find_entry(array $entries, string $name): ?array {
        foreach ($entries as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }
        return null;
    }
}
