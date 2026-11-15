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

defined('MOODLE_INTERNAL') || die();

/**
 * A source credit taller than its strip is centred by rounding away from zero (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(material_composition::class)]
final class material_composition_674_final_contract_test extends \advanced_testcase {
    /** @var string Bundled font. */
    private const FONT = __DIR__ . '/../fonts/FreeSansBold.ttf';

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
     * Two-line credit: its height is 51 px, so the centring offset is -1.5 and must round to -2, not -1.
     */
    public function test_a_two_line_credit_is_centred_with_half_a_pixel_rounded_away_from_zero(): void {
        $text = "A\nB";
        $box = imagettfbbox(20, 0, self::FONT, $text);
        $left = min($box[0], $box[2], $box[4], $box[6]);
        $top = min($box[1], $box[3], $box[5], $box[7]);
        $bottom = max($box[1], $box[3], $box[5], $box[7]);
        if (($bottom - $top) % 2 !== 1) {
            $this->markTestSkipped('The installed FreeType build measures an even text height; no half-pixel offset to test.');
        }
        $this->assertLessThan(0, 48 - ($bottom - $top), 'Precondition: the credit is taller than the strip.');
        $picture = imagecreatetruecolor(10, 60);
        ob_start();
        imagepng($picture);
        $bytes = ob_get_clean();

        [$rendered, $width] = material_composition::render([
            ['sourcepath' => 'a.png', 'content' => $bytes, 'source_header_text' => $text],
        ], 'horizontal');

        $image = imagecreatefromstring($rendered);
        $reference = imagecreatetruecolor($width, 48);
        imagefill($reference, 0, 0, imagecolorallocate($reference, 255, 255, 255));
        $baseline = -2 - $top;
        imagettftext($reference, 20, 0, 12 - $left, $baseline, imagecolorallocate($reference, 21, 101, 192), self::FONT, $text);
        $mismatch = 0;
        for ($y = 0; $y < 48; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $mismatch += imagecolorat($image, $x, $y) === imagecolorat($reference, $x, $y) ? 0 : 1;
            }
        }
        $this->assertSame(0, $mismatch, 'The strip equals the reference drawn at the rounded baseline.');
    }
}
