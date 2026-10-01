<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/

namespace local_coursepilot\external;

use local_coursepilot\material_area;
use local_coursepilot\material_files;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Composition at the public tool boundary, observing the stored PNG pixels.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(compose_material_file::class)]
#[CoversClass(\local_coursepilot\material_composition::class)]
final class compose_material_file_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    private function png(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            imageline($image, 0, $y, $width - 1, $y, imagecolorallocate($image, $y % 256, 120, 50));
        }
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        imagedestroy($image);
        return $content;
    }

    private function store(string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $content);
    }

    private function result_image(string $path): \GdImage {
        $stored = material_area::read_for_ort('werkbank', $path);
        $this->assertNotNull($stored);
        $this->assertSame('image/png', $stored['mimetype']);
        return imagecreatefromstring($stored['content']);
    }

    private function assert_pixels(\GdImage $source, \GdImage $target, int $x, int $y): void {
        for ($sy = 0; $sy < imagesy($source); $sy++) {
            for ($sx = 0; $sx < imagesx($source); $sx++) {
                $this->assertSame(imagecolorat($source, $sx, $sy), imagecolorat($target, $x + $sx, $y + $sy));
            }
        }
    }

    public function test_single_crop_with_source_header_uses_original_pixels(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('page.png', $this->png(1000, 1000));
        crop_material_file::execute('page.png', 'crop.png', 0.5, 0.5, 0.75, 0.75);

        $result = compose_material_file::execute([[
            'sourcepath' => 'page.png', 'location' => 'werkbank',
            'crop' => ['x0' => 0.5, 'y0' => 0.5, 'x1' => 0.75, 'y1' => 0.75],
            'source_header_text' => 'ML S. 36',
        ]], 'vertical', 'composed.png');

        $this->assertSame('composed.png', $result['path']);
        $this->assertTrue($result['created']);
        $this->assertSame(250, $result['width']);
        $this->assertSame(298, $result['height']);
        $stored = material_area::read_for_ort('werkbank', $result['path']);
        $this->assertSame($stored['contenthash'], $result['contenthash']);
        $this->assertStringStartsWith('werkbank:page.png', $result['sources'][0]);
        $image = $this->result_image('composed.png');
        $blue = 0;
        for ($y = 0; $y < 48; $y++) {
            for ($x = 0; $x < 250; $x++) {
                $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $blue += (int) ($rgb['blue'] > $rgb['red'] + 50 && $rgb['blue'] > $rgb['green']);
            }
        }
        $this->assertGreaterThan(100, $blue);
        $crop = $this->result_image('crop.png');
        $this->assert_pixels($crop, $image, 0, 48);
        imagedestroy($crop);
        imagedestroy($image);
    }

    public function test_mixed_locations_vertical_and_horizontal_keep_order_and_gap(): void {
        $this->resetAfterTest();
        [, $fake] = $this->set_up_external_material();
        $fake->seed_folder('/Coursepilot/Material');
        $first = $this->png(200, 80);
        $second = $this->png(120, 100);
        $fake->seed_file('/Coursepilot/Material/page.png', $first);
        $this->store('detail.png', $second);
        $parts = [
            ['sourcepath' => 'page.png', 'location' => 'bestand', 'source_header_text' => 'ML S. 36',
                'expected_contenthash' => sha1($first)],
            ['sourcepath' => 'detail.png', 'location' => 'werkbank', 'expected_contenthash' => sha1($second)],
        ];
        foreach (['vertical' => [200, 252, 0, 152], 'horizontal' => [344, 128, 224, 0]] as $layout => $expected) {
            $result = compose_material_file::execute($parts, $layout, $layout . '.png');
            $this->assertSame($expected[0], $result['width']);
            $this->assertSame($expected[1], $result['height']);
            $this->assertStringStartsWith('bestand:page.png', $result['sources'][0]);
            $this->assertStringStartsWith('werkbank:detail.png', $result['sources'][1]);
            $image = $this->result_image($result['path']);
            $original = imagecreatefromstring($first);
            $this->assert_pixels($original, $image, 0, 48);
            imagedestroy($original);
            $original = imagecreatefromstring($second);
            $this->assert_pixels($original, $image, $expected[2], $expected[3]);
            $this->assertSame(0xffffff, imagecolorat($image, $layout === 'vertical' ? 0 : 210,
                $layout === 'vertical' ? 140 : 0));
            imagedestroy($original);
            imagedestroy($image);
        }
        $this->assertSame($first, material_area::read_for_ort('bestand', 'page.png')['content']);
    }

    public function test_without_header_or_crop_preserves_original_including_alpha(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $original = imagecreatefromstring($this->png(60, 40));
        imagealphablending($original, false);
        imagesavealpha($original, true);
        imagesetpixel($original, 0, 0, imagecolorallocatealpha($original, 20, 30, 40, 90));
        ob_start();
        imagepng($original);
        $this->store('original.png', ob_get_clean());
        $result = compose_material_file::execute([['sourcepath' => 'original.png']], 'vertical', 'sub/result.png');
        $this->assertSame(60, $result['width']);
        $this->assertSame(40, $result['height']);
        $image = $this->result_image($result['path']);
        $this->assert_pixels($original, $image, 0, 0);
        $again = compose_material_file::execute([['sourcepath' => 'original.png']], 'vertical', 'sub/result.png');
        $this->assertFalse($again['created']);
        imagedestroy($original);
        imagedestroy($image);
    }

    public static function failure_cases(): array {
        $valid = ['sourcepath' => 'page.png'];
        return [
            'missing later source' => [[$valid, ['sourcepath' => 'missing.png']], 'vertical', 'result.png', 'materialfilenotfound'],
            'SVG' => [[['sourcepath' => 'page.svg']], 'vertical', 'result.png', 'materialcropsourceunsupported'],
            'broken raster' => [[['sourcepath' => 'broken.png']], 'vertical', 'result.png', 'materialcropsourceunsupported'],
            'negative' => [[$valid + ['crop' => ['x0' => -0.1, 'y0' => 0, 'x1' => 1, 'y1' => 1]]],
                'vertical', 'result.png', 'materialcropinvalidcoordinates'],
            'out of range' => [[$valid + ['crop' => ['x0' => 0, 'y0' => 0, 'x1' => 1, 'y1' => 1.1]]],
                'vertical', 'result.png', 'materialcropinvalidcoordinates'],
            'empty rectangle' => [[$valid + ['crop' => ['x0' => 0.5, 'y0' => 0, 'x1' => 0.5, 'y1' => 1]]],
                'vertical', 'result.png', 'materialcropinvalidcoordinates'],
            'reverse rectangle' => [[$valid + ['crop' => ['x0' => 0.7, 'y0' => 0, 'x1' => 0.5, 'y1' => 1]]],
                'vertical', 'result.png', 'materialcropinvalidcoordinates'],
            'empty list' => [[], 'vertical', 'result.png', 'materialcompositionemptyparts'],
            'hash mismatch' => [[$valid + ['expected_contenthash' => 'wrong']], 'vertical', 'result.png', 'materialfilechanged'],
            'invalid location' => [[$valid + ['location' => 'elsewhere']], 'vertical', 'result.png', 'invalidmaterialort'],
            'invalid arrangement' => [[$valid], 'grid', 'result.png', 'materialcompositioninvalidarrangement'],
            'not PNG' => [[$valid], 'vertical', 'result.svg', 'materialcompositionoutputunsupported'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failure_cases')]
    public function test_failures_leave_no_new_file_and_preserve_existing_target(
        array $parts, string $arrangement, string $target, string $errorcode
    ): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('page.png', $this->png(200, 80));
        $this->store('page.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->store('broken.png', 'not image data');
        foreach ([false, true] as $existing) {
            if ($existing) {
                $this->store($target, 'existing target');
            }
            try {
                compose_material_file::execute($parts, $arrangement, $target);
                $this->fail('Expected ' . $errorcode);
            } catch (\moodle_exception $e) {
                $this->assertSame($errorcode, $e->errorcode);
                if ($errorcode === 'materialcompositionoutputunsupported') {
                    $this->assertStringContainsString('PNG', $e->getMessage());
                    $this->assertStringNotContainsString('jpeg', $e->getMessage());
                }
                if ($errorcode === 'invalidmaterialort') {
                    $this->assertStringContainsString('bestand', $e->getMessage());
                    $this->assertStringContainsString('werkbank', $e->getMessage());
                }
            }
            $stored = material_area::read_for_ort('werkbank', $target);
            if ($existing) {
                $this->assertSame('existing target', $stored['content']);
            } else {
                $this->assertNull($stored);
            }
        }
    }

    public function test_umlaut_is_rendered_as_the_actual_glyph_with_two_dots(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('page.png', $this->png(300, 40));
        $images = [];
        foreach (['umlaut' => 'Ökologie S. 12', 'plain' => 'Okologie S. 12'] as $name => $text) {
            compose_material_file::execute([['sourcepath' => 'page.png', 'source_header_text' => $text]],
                'vertical', $name . '.png');
            $images[$name] = $this->result_image($name . '.png');
        }
        // Independent visual signature of Ö: two separated ink runs above its round body.
        $dotrows = 0;
        for ($y = 0; $y < 20; $y++) {
            $runs = 0;
            $wasink = false;
            for ($x = 12; $x < 33; $x++) {
                $ink = imagecolorat($images['umlaut'], $x, $y) !== 0xffffff;
                $runs += (int) ($ink && !$wasink);
                $wasink = $ink;
            }
            $dotrows += (int) ($runs === 2);
        }
        $this->assertGreaterThanOrEqual(2, $dotrows);
        $this->assertNotSame(material_area::read_for_ort('werkbank', 'plain.png')['contenthash'],
            material_area::read_for_ort('werkbank', 'umlaut.png')['contenthash']);
        foreach ($images as $image) {
            imagedestroy($image);
        }
    }

    public function test_tool_is_registered_as_writing(): void {
        $this->assertTrue(\local_coursepilot\tool_registry::is_write('coursepilot_compose_material_file'));
        $this->assertSame('local_coursepilot_compose_material_file',
            \local_coursepilot\privacy_surface::function_for_tool('coursepilot_compose_material_file'));
        $this->assertSame('write', \local_coursepilot\tool_registry::service_functions()['local_coursepilot_compose_material_file']['type']);
    }
}
