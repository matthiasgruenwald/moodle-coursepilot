<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

/**
 * Fixed house-style raster composition using GD and the bundled bold FreeFont.
 * No resizing: relative crops use full-resolution originals and crop's rounding.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class material_composition {
    /** @var int White strip above a part with a source header. */
    private const HEADER_HEIGHT = 48;
    /** @var int White space between adjacent parts. */
    private const GAP = 24;
    /** @var int FreeType font size in points. */
    private const FONT_SIZE = 20;
    /** @var int Header left padding in pixels. */
    private const PADDING = 12;

    /** @return array{0: string, 1: int, 2: int} PNG bytes, width and height. */
    public static function render(array $inputs, string $arrangement): array {
        if (!gd_support::available()) {
            throw new \moodle_exception('materialgdmissing', 'local_coursepilot');
        }
        $font = __DIR__ . '/../fonts/FreeSansBold.ttf';
        if (!function_exists('imagettftext') || !is_readable($font)) {
            throw new \moodle_exception('materialcompositionfontmissing', 'local_coursepilot');
        }
        $parts = [];
        $canvas = null;
        try {
            foreach ($inputs as $input) {
                $parts[] = self::prepare_part($input, $font);
            }
            [$width, $height] = self::dimensions($parts, $arrangement);
            $canvas = imagecreatetruecolor($width, $height);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            self::draw_parts($canvas, $parts, $arrangement, $font);
            ob_start();
            imagepng($canvas);
            return [ob_get_clean(), $width, $height];
        } finally {
            foreach ($parts as $part) {
                imagedestroy($part['image']);
            }
            if ($canvas !== null) {
                imagedestroy($canvas);
            }
        }
    }

    /** @return array Image, pixel rectangle and layout size for one part. */
    private static function prepare_part(array $input, string $font): array {
        $extension = strtolower(pathinfo($input['sourcepath'], PATHINFO_EXTENSION));
        if (!in_array($extension, gd_support::RASTER_IMAGE_EXTENSIONS, true)) {
            throw new \moodle_exception('materialcropsourceunsupported', 'local_coursepilot', '', $input['sourcepath']);
        }
        $crop = $input['crop'] ?? ['x0' => 0.0, 'y0' => 0.0, 'x1' => 1.0, 'y1' => 1.0];
        self::guard_coordinates($crop);
        $image = @imagecreatefromstring($input['content']);
        if ($image === false) {
            throw new \moodle_exception('materialcropsourceunsupported', 'local_coursepilot', '', $input['sourcepath']);
        }
        $x = (int) round($crop['x0'] * imagesx($image));
        $y = (int) round($crop['y0'] * imagesy($image));
        $cropwidth = max(1, min(imagesx($image) - $x, (int) round($crop['x1'] * imagesx($image)) - $x));
        $cropheight = max(1, min(imagesy($image) - $y, (int) round($crop['y1'] * imagesy($image)) - $y));
        $text = $input['source_header_text'];
        $header = $text === '' ? 0 : self::HEADER_HEIGHT;
        $box = $text === '' ? [0, 0, 0, 0, 0, 0, 0, 0] : imagettfbbox(self::FONT_SIZE, 0, $font, $text);
        $textwidth = max($box[0], $box[2], $box[4], $box[6]) - min($box[0], $box[2], $box[4], $box[6]);
        return compact('image', 'x', 'y', 'cropwidth', 'cropheight', 'text', 'header', 'box') + [
            'width' => max($cropwidth, $text === '' ? 0 : $textwidth + 2 * self::PADDING),
            'height' => $cropheight + $header,
        ];
    }

    /** Same range, positive-area validation and rounding semantics as crop_material_file. */
    private static function guard_coordinates(array $crop): void {
        foreach ($crop as $value) {
            if (!is_finite($value) || $value < 0.0 || $value > 1.0) {
                throw new \moodle_exception('materialcropinvalidcoordinates', 'local_coursepilot', '', (object) $crop);
            }
        }
        if ($crop['x1'] <= $crop['x0'] || $crop['y1'] <= $crop['y0']) {
            throw new \moodle_exception('materialcropinvalidcoordinates', 'local_coursepilot', '', (object) $crop);
        }
    }

    /** @return array{0: int, 1: int} Width and height including inter-part gaps. */
    private static function dimensions(array $parts, string $arrangement): array {
        $widths = array_column($parts, 'width');
        $heights = array_column($parts, 'height');
        $gaps = (count($parts) - 1) * self::GAP;
        return $arrangement === 'vertical'
            ? [max($widths), array_sum($heights) + $gaps]
            : [array_sum($widths) + $gaps, max($heights)];
    }

    /** Place each part at the top/left, preserving its pixels including alpha. */
    private static function draw_parts(\GdImage $canvas, array $parts, string $arrangement, string $font): void {
        $x = 0;
        $y = 0;
        $blue = imagecolorallocate($canvas, 21, 101, 192);
        foreach ($parts as $part) {
            if ($part['header']) {
                imagealphablending($canvas, true);
                $box = $part['box'];
                $left = min($box[0], $box[2], $box[4], $box[6]);
                $top = min($box[1], $box[3], $box[5], $box[7]);
                $bottom = max($box[1], $box[3], $box[5], $box[7]);
                $baseline = (int) round((self::HEADER_HEIGHT - ($bottom - $top)) / 2) - $top;
                imagettftext($canvas, self::FONT_SIZE, 0, $x + self::PADDING - $left, $y + $baseline, $blue, $font, $part['text']);
            }
            imagealphablending($canvas, false);
            imagecopy($canvas, $part['image'], $x, $y + $part['header'], $part['x'], $part['y'], $part['cropwidth'], $part['cropheight']);
            if ($arrangement === 'vertical') {
                $y += $part['height'] + self::GAP;
            } else {
                $x += $part['width'] + self::GAP;
            }
        }
    }
}
