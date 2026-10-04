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

use local_coursepilot\gd_support;
use local_coursepilot\material_files;
use local_coursepilot\storage_anchor;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use local_coursepilot\webdav\webdav_instance;

defined('MOODLE_INTERNAL') || die();

/**
 * Image preview of a material file (Spec 0018 §3, issue #430): longest
 * edge 768px, JPEG. A non-image file is not an error ("available": false
 * plus message), a missing file remains an error (as with the
 * other material folder tools).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(preview_material_file::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\material_files::class)]
final class preview_material_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * @param string $filename
     * @param int $width
     * @param int $height
     * @return void
     */
    private function store_png(string $filename, int $width = 1600, int $height = 100): void {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $png);
    }

    public function test_shrinks_wide_image_to_768px_longest_edge_jpeg(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('bild.png', 1600, 100);

        $result = preview_material_file::execute('bild.png');

        $this->assertTrue($result['available']);
        $this->assertSame('image/jpeg', $result['mimetype']);
        $this->assertSame(768, $result['width']);
        $this->assertSame(48, $result['height']);

        $decoded = base64_decode($result['image_base64'], true);
        $info = getimagesizefromstring($decoded);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(768, $info[0]);
    }

    public function test_small_image_is_not_upscaled(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('klein.png', 100, 50);

        $result = preview_material_file::execute('klein.png');

        $this->assertSame(100, $result['width']);
        $this->assertSame(50, $result['height']);
    }

    public function test_non_image_file_returns_message_instead_of_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'blatt.pdf',
        ], 'not a real PDF, good enough for the test');

        $result = preview_material_file::execute('blatt.pdf');

        $this->assertFalse($result['available']);
        $this->assertNotEmpty($result['message']);
        $this->assertNull($result['image_base64']);
    }

    public function test_missing_file_throws(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        preview_material_file::execute('nichtvorhanden.png');
    }

    /**
     * Spec 0018 §3.3: if GD is missing, the preview is blocked with a clear
     * message (no silent fallback).
     */
    public function test_missing_gd_blocks_preview_with_clear_message(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('bild.png');
        gd_support::override_for_testing(false);

        try {
            preview_material_file::execute('bild.png');
            $this->fail('materialgdmissing should have been thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialgdmissing', $e->errorcode);
        } finally {
            gd_support::override_for_testing(null);
        }
    }

    /**
     * Spec 0018 §3, acceptance criterion "preview of a non-image file =>
     * clear message instead of an error": also applies to a file with an image
     * extension that GD cannot read as a raster image (e.g. corrupt bytes) - no
     * error, but "available": false plus message.
     */
    public function test_unreadable_image_bytes_return_message_instead_of_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'kaputt.png',
        ], 'these are not real PNG bytes');

        $result = preview_material_file::execute('kaputt.png');

        $this->assertFalse($result['available']);
        $this->assertNotEmpty($result['message']);
        $this->assertNull($result['image_base64']);
    }

    /**
     * The "ort" parameter (issue #495) reads from the external material store
     * instead of from Moodle - the same preview as in the Moodle branch.
     */
    public function test_ort_bestand_reads_from_external_material(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Coursepilot/Material');
        $image = imagecreatetruecolor(1600, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        $fake->seed_file('/Coursepilot/Material/bild.png', $png);

        $result = preview_material_file::execute('bild.png');

        $this->assertTrue($result['available']);
        $this->assertSame(768, $result['width']);
    }

    public function test_unknown_ort_value_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('bild.png');

        try {
            preview_material_file::execute('bild.png', 'woanders');
            $this->fail('An unknown location value should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidmateriallocation', $e->errorcode);
        }
    }

    /**
     * If the context area lies within the store, the preview rejects a path
     * below it (issue #495, acceptance criterion 4).
     */
    public function test_rejects_path_under_kontextbereich(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot-material/kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ]);

        try {
            preview_material_file::execute('kontext/plan.png');
            $this->fail('A path below the context area should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiscontext', $e->errorcode);
        }
    }
}
