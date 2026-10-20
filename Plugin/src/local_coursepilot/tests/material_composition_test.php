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
 * Image composition (house style): part order, gaps, crops with PHP rounding, and the blue
 * source header drawn with the bundled bold font on a white canvas.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(material_composition::class)]
final class material_composition_test extends \advanced_testcase {
    /** @var string Bundled font. */
    private const FONT = __DIR__ . '/../fonts/FreeSansBold.ttf';

    /** @var int[] House blue. */
    private const BLUE = [21, 101, 192];

    /**
     * PNG bytes of an image whose colour depends on the column: red grows by 20 per x, green marks the row.
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    private function png(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, min(255, 10 + 20 * $x), min(255, 5 * $y), 7));
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
     * @param string $content
     * @param string $header
     * @param float[]|null $crop
     * @param string $path
     * @return mixed[]
     */
    private function part(string $content, string $header = '', ?array $crop = null, string $path = 'a.png'): array {
        $input = ['sourcepath' => $path, 'content' => $content, 'source_header_text' => $header];
        if ($crop !== null) {
            $input['crop'] = $crop;
        }
        return $input;
    }

    /**
     * RGB of a pixel in rendered PNG bytes.
     *
     * @param \GdImage $image
     * @param int $x
     * @param int $y
     * @return int[]
     */
    private function rgb(\GdImage $image, int $x, int $y): array {
        $c = imagecolorat($image, $x, $y);
        return [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
    }

    /**
     * Horizontal arrangement puts parts side by side with a 24 px white gap, aligned at the top.
     */
    public function test_horizontal_composition_places_parts_left_to_right_with_a_white_gap(): void {
        [$bytes, $width, $height] = material_composition::render(
            [$this->part($this->png(10, 8)), $this->part($this->png(6, 12))],
            'horizontal'
        );

        $this->assertSame([10 + 24 + 6, 12], [$width, $height]);
        $image = imagecreatefromstring($bytes);
        $this->assertSame([$width, $height], [imagesx($image), imagesy($image)]);
        $this->assertSame([10, 0, 7], $this->rgb($image, 0, 0));
        $this->assertSame([10 + 20 * 9, 5 * 7, 7], $this->rgb($image, 9, 7));
        $this->assertSame([255, 255, 255], $this->rgb($image, 9, 8), 'below the shorter part stays white');
        $this->assertSame([255, 255, 255], $this->rgb($image, 10, 0), 'gap');
        $this->assertSame([255, 255, 255], $this->rgb($image, 33, 11), 'gap end');
        $this->assertSame([10, 0, 7], $this->rgb($image, 34, 0));
        $this->assertSame([10 + 20 * 5, 5 * 11, 7], $this->rgb($image, 39, 11));
        $this->assertSame([255, 255, 255], $this->rgb($image, 0, 11), 'canvas is white where no part is');
    }

    /**
     * Vertical arrangement stacks parts with a 24 px white gap, aligned at the left.
     */
    public function test_vertical_composition_stacks_parts_with_a_white_gap(): void {
        [$bytes, $width, $height] = material_composition::render(
            [$this->part($this->png(10, 8)), $this->part($this->png(6, 12))],
            'vertical'
        );

        $this->assertSame([10, 8 + 24 + 12], [$width, $height]);
        $image = imagecreatefromstring($bytes);
        $this->assertSame([10, 0, 7], $this->rgb($image, 0, 0));
        $this->assertSame([255, 255, 255], $this->rgb($image, 0, 8), 'gap');
        $this->assertSame([255, 255, 255], $this->rgb($image, 0, 31), 'gap end');
        $this->assertSame([10, 0, 7], $this->rgb($image, 0, 32));
        $this->assertSame([10 + 20 * 5, 5 * 11, 7], $this->rgb($image, 5, 43));
        $this->assertSame([255, 255, 255], $this->rgb($image, 6, 32), 'right of the narrower part stays white');
    }

    /**
     * A crop keeps full-resolution pixels; coordinates are rounded half away from zero, never to floor or ceiling.
     */
    public function test_crop_rounds_coordinates_and_keeps_original_pixels(): void {
        [$bytes, $width, $height] = material_composition::render(
            [$this->part($this->png(10, 10), '', ['x0' => 0.25, 'y0' => 0.25, 'x1' => 0.74, 'y1' => 0.74])],
            'horizontal'
        );

        // X: round(2.5)=3 .. round(7.4)=7 (4 wide); y likewise (4 high).
        $this->assertSame([4, 4], [$width, $height]);
        $image = imagecreatefromstring($bytes);
        $this->assertSame([10 + 20 * 3, 5 * 3, 7], $this->rgb($image, 0, 0));
        $this->assertSame([10 + 20 * 6, 5 * 6, 7], $this->rgb($image, 3, 3));
    }

    /**
     * A crop narrower than one pixel still yields a one-pixel part.
     */
    public function test_degenerate_crop_keeps_at_least_one_pixel(): void {
        [, $width, $height] = material_composition::render(
            [$this->part($this->png(10, 10), '', ['x0' => 0.49, 'y0' => 0.49, 'x1' => 0.5, 'y1' => 0.5])],
            'horizontal'
        );

        $this->assertSame([1, 1], [$width, $height]);
    }

    /**
     * Crop coordinates must be finite, inside 0..1 and enclose a positive area (error shows all four).
     *
     * @return array<string, array{float[]}>
     */
    public static function invalid_crops(): array {
        return [
            'negative' => [['x0' => -0.1, 'y0' => 0.0, 'x1' => 1.0, 'y1' => 1.0]],
            'above one' => [['x0' => 0.0, 'y0' => 0.0, 'x1' => 1.1, 'y1' => 1.0]],
            'not finite' => [['x0' => 0.0, 'y0' => NAN, 'x1' => 1.0, 'y1' => 1.0]],
            'zero width' => [['x0' => 0.5, 'y0' => 0.0, 'x1' => 0.5, 'y1' => 1.0]],
            'zero height' => [['x0' => 0.0, 'y0' => 0.5, 'x1' => 1.0, 'y1' => 0.5]],
            'inverted' => [['x0' => 0.8, 'y0' => 0.0, 'x1' => 0.2, 'y1' => 1.0]],
        ];
    }

    /**
     * An unusable crop is refused with the coordinates in the message.
     *
     * @param float[] $crop
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_crops')]
    public function test_invalid_crop_is_refused_with_its_coordinates(array $crop): void {
        try {
            material_composition::render([$this->part($this->png(4, 4), '', $crop)], 'horizontal');
            $this->fail('Invalid crop must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('materialcropinvalidcoordinates', $e->errorcode);
            $this->assertSame(get_string('materialcropinvalidcoordinates', 'local_coursepilot', (object) $crop), $e->getMessage());
        }
    }

    /**
     * Sources that GD cannot read are refused by name: wrong extension (any case) or corrupt data.
     */
    public function test_unsupported_sources_are_refused_by_name(): void {
        $this->assertSame('materialcropsourceunsupported', $this->refusal([$this->part($this->png(2, 2), '', null, 'a.svg')]));
        $this->assertSame('materialcropsourceunsupported', $this->refusal([$this->part('not an image', '', null, 'b.png')]));
        // Upper-case extensions are accepted.
        [, $width] = material_composition::render([$this->part($this->png(2, 2), '', null, 'C.PNG')], 'horizontal');
        $this->assertSame(2, $width);
        try {
            material_composition::render([$this->part($this->png(2, 2), '', null, 'x.svg')], 'horizontal');
        } catch (\moodle_exception $e) {
            $this->assertSame(get_string('materialcropsourceunsupported', 'local_coursepilot', 'x.svg'), $e->getMessage());
        }
    }

    /**
     * Error code of a failing render.
     *
     * @param mixed[] $inputs
     * @return string
     */
    private function refusal(array $inputs): string {
        try {
            material_composition::render($inputs, 'horizontal');
        } catch (\moodle_exception $e) {
            return $e->errorcode;
        }
        return '';
    }

    /**
     * The header grows a part to fit its text, adds a 48 px strip and draws the text in house blue at the padding.
     */
    public function test_source_header_widens_the_part_and_draws_the_text_centered_in_its_strip(): void {
        $text = 'Quelle: Beispielbuch Seite 12';
        $box = imagettfbbox(20, 0, self::FONT, $text);
        $textwidth = max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]);

        [$bytes, $width, $height] = material_composition::render([$this->part($this->png(10, 9), $text)], 'horizontal');

        $this->assertSame([$textwidth + 24, 48 + 9], [$width, $height]);
        $image = imagecreatefromstring($bytes);
        $reference = imagecreatetruecolor($width, 48);
        imagefill($reference, 0, 0, imagecolorallocate($reference, 255, 255, 255));
        $left = min($box[0], $box[2], $box[4], $box[6]);
        $top = min($box[1], $box[3], $box[5], $box[7]);
        $bottom = max($box[1], $box[3], $box[5], $box[7]);
        $baseline = (int) round((48 - ($bottom - $top)) / 2) - $top;
        imagettftext($reference, 20, 0, 12 - $left, $baseline, imagecolorallocate($reference, ...self::BLUE), self::FONT, $text);
        $mismatch = 0;
        $blue = 0;
        for ($y = 0; $y < 48; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $mismatch += $this->rgb($image, $x, $y) === $this->rgb($reference, $x, $y) ? 0 : 1;
                $blue += $this->rgb($image, $x, $y) === self::BLUE ? 1 : 0;
            }
        }
        $this->assertSame(0, $mismatch, 'header strip equals the reference rendering');
        $this->assertGreaterThan(50, $blue);
        // The picture starts right below the strip, in the top-left corner.
        $this->assertSame([10, 0, 7], $this->rgb($image, 0, 48));
        $this->assertSame([10 + 20 * 9, 5 * 8, 7], $this->rgb($image, 9, 48 + 8));
        $this->assertSame([255, 255, 255], $this->rgb($image, 10, 48), 'right of the narrow picture stays white');
    }

    /**
     * A header over a wide picture keeps the picture width; parts without text get no strip.
     */
    public function test_header_over_a_wide_picture_keeps_the_picture_width(): void {
        [, $width, $height] = material_composition::render(
            [$this->part($this->png(300, 5), 'Q'), $this->part($this->png(20, 7))],
            'horizontal'
        );

        $this->assertSame([300 + 24 + 20, 48 + 5], [$width, $height]);
    }
}
