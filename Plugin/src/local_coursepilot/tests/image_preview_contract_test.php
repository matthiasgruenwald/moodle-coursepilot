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

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact size, background and encoding of the image preview (Spec 0018 §3.1).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(image_preview::class)]
final class image_preview_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Encodes a GD image as PNG bytes.
     *
     * @param \GdImage $image
     * @return string
     */
    private function png(\GdImage $image): string {
        ob_start();
        imagepng($image);
        return ob_get_clean();
    }

    /**
     * Encodes a GD image as JPEG with the quality of the preview.
     *
     * @param \GdImage $image
     * @return string
     */
    private function jpeg(\GdImage $image): string {
        ob_start();
        imagejpeg($image, null, 80);
        return ob_get_clean();
    }

    /**
     * Provides source sizes and the expected preview size.
     *
     * @return array<string, array{int, int, int, int}>
     */
    public static function sizes(): array {
        return [
            'small image keeps its size' => [40, 30, 40, 30],
            'exactly the longest edge keeps its size' => [768, 100, 768, 100],
            'landscape height rounds down' => [1000, 331, 768, 254],
            'landscape height rounds up' => [1000, 333, 768, 256],
            'portrait width rounds down' => [331, 1000, 254, 768],
            'portrait width rounds up' => [333, 1000, 256, 768],
            'a hairline keeps one pixel' => [1, 2000, 1, 768],
            'a flat strip keeps one pixel' => [3000, 1, 768, 1],
        ];
    }

    #[DataProvider('sizes')]
    public function test_the_preview_is_scaled_to_the_longest_edge_with_rounded_sides(
        int $width,
        int $height,
        int $expectedwidth,
        int $expectedheight
    ): void {
        $source = imagecreatetruecolor($width, $height);

        $result = image_preview::build($this->png($source));

        $this->assertSame(
            ['image_base64', 'mimetype', 'width', 'height'],
            array_keys($result)
        );
        $this->assertSame('image/jpeg', $result['mimetype']);
        $this->assertSame([$expectedwidth, $expectedheight], [$result['width'], $result['height']]);
        $decoded = imagecreatefromstring(base64_decode($result['image_base64'], true));
        $this->assertSame([$expectedwidth, $expectedheight], [imagesx($decoded), imagesy($decoded)]);
    }

    public function test_a_transparent_source_is_flattened_onto_pure_white(): void {
        $source = imagecreatetruecolor(20, 10);
        imagealphablending($source, false);
        imagesavealpha($source, true);
        imagefill($source, 0, 0, imagecolorallocatealpha($source, 0, 0, 0, 127));
        $white = imagecreatetruecolor(20, 10);
        imagefill($white, 0, 0, imagecolorallocate($white, 255, 255, 255));

        $result = image_preview::build($this->png($source));

        $this->assertSame(base64_encode($this->jpeg($white)), $result['image_base64']);
    }

    public function test_every_pixel_is_copied_to_its_place(): void {
        $source = imagecreatetruecolor(24, 16);
        for ($x = 0; $x < 24; $x++) {
            for ($y = 0; $y < 16; $y++) {
                imagesetpixel($source, $x, $y, imagecolorallocate($source, $x * 10, $y * 15, ($x * $y) % 256));
            }
        }
        $reference = imagecreatetruecolor(24, 16);
        imagefill($reference, 0, 0, imagecolorallocate($reference, 255, 255, 255));
        imagecopyresampled($reference, $source, 0, 0, 0, 0, 24, 16, 24, 16);

        $result = image_preview::build($this->png($source));

        $this->assertSame(base64_encode($this->jpeg($reference)), $result['image_base64']);
    }

    public function test_something_that_is_no_raster_image_is_refused(): void {
        $e = $this->caught(fn() => image_preview::build('<svg xmlns="http://www.w3.org/2000/svg"/>'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialpreviewunsupported', $e->errorcode);
    }
}
