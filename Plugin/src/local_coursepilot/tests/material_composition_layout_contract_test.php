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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Where every part, gap and cropped pixel of a rendered material composition ends up (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(material_composition::class)]
final class material_composition_layout_contract_test extends \basic_testcase {
    /**
     * Skips without the GD runtime the renderer needs.
     */
    protected function setUp(): void {
        parent::setUp();
        if (!gd_support::available() || !function_exists('imagettftext')) {
            $this->markTestSkipped('GD with FreeType is required.');
        }
    }

    /**
     * PNG bytes of an image whose pixel colours are given by a function.
     *
     * @param int $width Width.
     * @param int $height Height.
     * @param callable $colour Function (x, y) returning [r, g, b].
     * @return string
     */
    private function png(int $width, int $height, callable $colour): string {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $b] = $colour($x, $y);
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
            }
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    /**
     * One composition input.
     *
     * @param string $content PNG bytes.
     * @param string $header Source header text.
     * @param mixed[]|null $crop Crop rectangle.
     * @return mixed[]
     */
    private function part(string $content, string $header = '', ?array $crop = null): array {
        $input = ['sourcepath' => 'part.png', 'content' => $content, 'source_header_text' => $header];
        return $crop === null ? $input : $input + ['crop' => $crop];
    }

    /**
     * Renders and decodes the result.
     *
     * @param mixed[] $inputs Inputs.
     * @param string $arrangement Arrangement.
     * @return array{0: \GdImage, 1: int, 2: int} Image, width, height.
     */
    private function rendered(array $inputs, string $arrangement): array {
        [$bytes, $width, $height] = material_composition::render($inputs, $arrangement);
        $image = imagecreatefromstring($bytes);
        $this->assertSame($width, imagesx($image));
        $this->assertSame($height, imagesy($image));
        return [$image, $width, $height];
    }

    /**
     * RGB of a pixel.
     *
     * @param \GdImage $image Image.
     * @param int $x X.
     * @param int $y Y.
     * @return int[]
     */
    private function rgb(\GdImage $image, int $x, int $y): array {
        $value = imagecolorat($image, $x, $y);
        return [($value >> 16) & 255, ($value >> 8) & 255, $value & 255];
    }

    public function test_three_parts_follow_each_other_with_the_gap_in_both_arrangements(): void {
        $red = $this->png(2, 3, static fn() => [255, 0, 0]);
        $green = $this->png(2, 3, static fn() => [0, 255, 0]);
        $blue = $this->png(2, 3, static fn() => [0, 0, 255]);
        $inputs = [$this->part($red), $this->part($green), $this->part($blue)];

        [$horizontal, $width, $height] = $this->rendered($inputs, 'horizontal');
        $this->assertSame([54, 3], [$width, $height]);
        $this->assertSame([255, 0, 0], $this->rgb($horizontal, 0, 0));
        $this->assertSame([255, 255, 255], $this->rgb($horizontal, 10, 0));
        $this->assertSame([0, 255, 0], $this->rgb($horizontal, 26, 0));
        $this->assertSame([255, 255, 255], $this->rgb($horizontal, 40, 0));
        $this->assertSame([0, 0, 255], $this->rgb($horizontal, 52, 0));

        [$vertical, $width, $height] = $this->rendered($inputs, 'vertical');
        $this->assertSame([2, 57], [$width, $height]);
        $this->assertSame([255, 0, 0], $this->rgb($vertical, 0, 0));
        $this->assertSame([255, 255, 255], $this->rgb($vertical, 0, 10));
        $this->assertSame([0, 255, 0], $this->rgb($vertical, 0, 27));
        $this->assertSame([255, 255, 255], $this->rgb($vertical, 0, 40));
        $this->assertSame([0, 0, 255], $this->rgb($vertical, 0, 54));
    }

    public function test_the_white_gap_between_one_pixel_parts_is_filled_in_both_arrangements(): void {
        $dot = $this->png(1, 1, static fn() => [255, 0, 0]);
        $inputs = [$this->part($dot), $this->part($dot)];

        [$vertical] = $this->rendered($inputs, 'vertical');
        [$horizontal] = $this->rendered($inputs, 'horizontal');

        $this->assertSame([255, 255, 255], $this->rgb($vertical, 0, 12));
        $this->assertSame([255, 255, 255], $this->rgb($horizontal, 12, 0));
    }

    public function test_a_crop_is_rounded_to_the_nearest_pixel_edge_on_all_four_sides(): void {
        $gradient = $this->png(10, 10, static fn(int $x, int $y) => [$x * 20, $y * 20, 7]);

        [$image, $width, $height] = $this->rendered(
            [$this->part($gradient, '', ['x0' => 0.21, 'y0' => 0.21, 'x1' => 0.79, 'y1' => 0.79])],
            'horizontal'
        );

        $this->assertSame([6, 6], [$width, $height]);
        $this->assertSame([40, 40, 7], $this->rgb($image, 0, 0));
        $this->assertSame([140, 140, 7], $this->rgb($image, 5, 5));
    }

    public function test_the_headers_of_two_identical_parts_are_rendered_identically(): void {
        $image = $this->png(300, 40, static fn() => [250, 250, 250]);
        $inputs = [$this->part($image, 'Quelle jgq'), $this->part($image, 'Quelle jgq')];

        [$both, $width, $height] = $this->rendered($inputs, 'horizontal');

        $partwidth = (int) (($width - 24) / 2);
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $partwidth; $x++) {
                $this->assertSame(
                    imagecolorat($both, $x, $y),
                    imagecolorat($both, $x + $partwidth + 24, $y),
                    "pixel $x/$y of the second part differs from the first"
                );
            }
        }
    }
}
