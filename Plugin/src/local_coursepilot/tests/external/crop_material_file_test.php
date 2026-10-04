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
 * Crop a stored material image (Spec 0018 §5, #431) and save the result
 * to material storage. Coordinates (0–1) refer to the preview; cropping
 * uses the full-resolution original.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(crop_material_file::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\material_files::class)]
final class crop_material_file_test extends \advanced_testcase {
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
    private function store_png(string $filename, int $width, int $height): void {
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

    /**
     * Verify that cropping uses the original rather than the preview
     * (Spec 0018 §3.1). The original exceeds the 768px preview edge, but
     * the crop must retain original resolution.
     */
    public function test_crops_from_full_resolution_original_not_preview(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 3000, 2000);

        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.5, 0.5, 0.75, 0.75);

        // 0.25 * 3000 = 750 and 0.25 * 2000 = 500. Cropping the 768px preview
        // would produce only a fraction of this resolution.
        $this->assertSame(750, $result['width']);
        $this->assertSame(500, $result['height']);
        $this->assertSame('ausschnitt.png', $result['path']);
        $this->assertTrue($result['created']);

        $stored = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            'ausschnitt.png'
        );
        $this->assertNotFalse($stored);
        $info = getimagesizefromstring($stored->get_content());
        $this->assertSame(750, $info[0]);
        $this->assertSame(500, $info[1]);
    }

    /**
     * Record provenance in Moodle’s existing source field (Spec 0018 §5).
     */
    public function test_origin_is_recorded_in_source_field(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        $stored = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            'ausschnitt.png'
        );
        $source = unserialize_object($stored->get_source());
        $this->assertSame('buchseite.png', $source->original);
    }

    /**
     * Crop the same sourcepath again with different coordinates, without
     * re-uploading the source.
     */
    public function test_source_file_can_be_cropped_again_without_reupload(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        $first = crop_material_file::execute('buchseite.png', 'versuch1.png', 0.0, 0.0, 0.5, 0.5);
        $second = crop_material_file::execute('buchseite.png', 'versuch2.png', 0.25, 0.25, 0.75, 0.75);

        $this->assertTrue($first['created']);
        $this->assertTrue($second['created']);
        $this->assertSame(500, $second['width']);
    }

    /**
     * Cropping to the same target path again overwrites the previous result.
     */
    public function test_recropping_into_same_target_overwrites(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.25, 0.25);

        $this->assertFalse($result['created']);
        $this->assertSame(250, $result['width']);
    }

    /**
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public static function invalid_coordinate_cases(): array {
        return [
            'x0 negativ' => [-0.1, 0.0, 0.5, 0.5],
            'y1 ueber eins' => [0.0, 0.0, 0.5, 1.1],
            'nullflaeche x' => [0.5, 0.0, 0.5, 0.5],
            'nullflaeche y' => [0.0, 0.5, 0.5, 0.5],
            'x1 kleiner x0' => [0.6, 0.0, 0.4, 0.5],
        ];
    }

    /**
     * @dataProvider invalid_coordinate_cases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_coordinate_cases')]
    public function test_rejects_invalid_coordinates(float $x0, float $y0, float $x1, float $y1): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', $x0, $y0, $x1, $y1);
            $this->fail('materialcropinvalidcoordinates should have been thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialcropinvalidcoordinates', $e->errorcode);
        }
    }

    /**
     * Reject SVG clearly because cropping supports raster images only
     * (Spec 0018 §5).
     */
    public function test_rejects_svg_source_with_clear_message(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'diagramm.svg',
        ], '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        try {
            crop_material_file::execute('diagramm.svg', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
            $this->fail('materialcropsourceunsupported should have been thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialcropsourceunsupported', $e->errorcode);
        }
    }

    /**
     * Target-file concurrency protection (Spec 0016 §5.3): an incorrect
     * expected_contenthash aborts before the expensive crop, as with uploads.
     */
    public function test_rejects_when_target_contenthash_does_not_match(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);
        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.1, 0.1, 0.6, 0.6, 'falscherhash');
            $this->fail('materialfilechanged should have been thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialfilechanged', $e->errorcode);
        }
    }

    /**
     * A full quota is a hard error (Spec 0018 §8.1), as with uploads.
     */
    public function test_rejects_when_quota_is_full(): void {
        global $CFG;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);
        $CFG->userquota = 1;

        $this->expectException(\moodle_exception::class);
        crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
    }

    /**
     * Reject target extensions GD cannot write, such as pdf and svg.
     * Cropping supports raster formats only.
     */
    public function test_rejects_unsupported_output_extension(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.svg', 0.0, 0.0, 0.5, 0.5);
            $this->fail('materialcropoutputunsupported should have been thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialcropoutputunsupported', $e->errorcode);
        }
    }

    public function test_missing_source_file_throws(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);
        crop_material_file::execute('nichtvorhanden.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
    }

    public function test_missing_gd_blocks_crop_with_clear_message(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);
        gd_support::override_for_testing(false);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
            $this->fail('materialgdmissing should have been thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialgdmissing', $e->errorcode);
        } finally {
            gd_support::override_for_testing(null);
        }
    }

    /**
     * @return string PNG bytes of a 1000x1000 image.
     */
    private function build_png(int $width = 1000, int $height = 1000): string {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        return $png;
    }

    /**
     * Read the source from external material storage (#495) and write the
     * result to the workbench (acceptance criteria 2/6).
     */
    public function test_reads_source_from_external_material_and_writes_result_to_workbench(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Coursepilot/Material');
        $fake->seed_file('/Coursepilot/Material/buchseite.png', $this->build_png(1000, 1000));

        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        $this->assertSame(500, $result['width']);
        $this->assertStringStartsWith('store:buchseite.png', $result['source']);

        $stored = get_file_storage()->get_file(
            material_files::own_context()->id,
            material_files::COMPONENT,
            material_files::FILEAREA,
            material_files::ITEMID,
            '/coursepilot-material/',
            'ausschnitt.png'
        );
        $this->assertNotFalse($stored, 'The result must be on the workbench (Moodle).');
    }

    /**
     * source records the location, size and modification time rather than
     * a contenthash unavailable in external storage.
     */
    public function test_source_field_names_ort_and_fingerprint(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        $result = crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);

        $this->assertMatchesRegularExpression(
            '/^store:buchseite\.png \(\d+ bytes, modified \d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\)$/u',
            $result['source']
        );
    }

    public function test_unknown_ort_value_is_rejected(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store_png('buchseite.png', 1000, 1000);

        try {
            crop_material_file::execute('buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5, '', 'woanders');
            $this->fail('An unknown location value should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidmateriallocation', $e->errorcode);
        }
    }

    /**
     * Reject sources below the context area in inventory (#495, criterion 4).
     */
    public function test_rejects_source_path_under_kontextbereich(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'coursepilot-material/kontext'],
            'material_store' => ['location' => 'moodle', 'path' => 'coursepilot-material'],
        ]);

        try {
            crop_material_file::execute('kontext/buchseite.png', 'ausschnitt.png', 0.0, 0.0, 0.5, 0.5);
            $this->fail('A source under the context area should have thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialpathiscontext', $e->errorcode);
        }
    }
}
