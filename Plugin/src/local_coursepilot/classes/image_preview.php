<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

/**
 * Builds the image preview from spec 0018 §3.1: longest edge 768px, JPEG.
 * Serves judging (the model picks a crop/writes alt text), not
 * processing - the original stays untouched, the crop (later,
 * own endpoint, §5) cuts from the original, not from this
 * preview.
 *
 * GD is raster-only (§3.3/§5) - SVG cannot be rendered through it,
 * that is the caller's job (preview_material_file), not this class's.
 *
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class image_preview {
    /** @var int Longest edge of the preview in pixels (spec 0018 §3.1). */
    private const MAX_EDGE = 768;

    /** @var int JPEG quality - "a few tens of kilobytes" instead of best quality (spec 0018 §3.1). */
    private const JPEG_QUALITY = 80;

    /**
     * Builds the image preview.
     *
     * @param string $binary Raw content of the source file.
     * @return array{image_base64: string, mimetype: string, width: int, height: int}
     * @throws \moodle_exception materialpreviewunsupported if GD cannot
     *         read the content as a raster image (e.g. SVG, broken image).
     */
    public static function build(string $binary): array {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new \moodle_exception('materialpreviewunsupported', 'local_coursepilot');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $newwidth = max(1, (int) round($width * $scale));
        $newheight = max(1, (int) round($height * $scale));

        // Always copy onto a white canvas without alpha channel - JPEG
        // has no transparency, without this step GD would
        // output transparent PNG areas as black.
        $canvas = imagecreatetruecolor($newwidth, $newheight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
        imagedestroy($source);

        ob_start();
        imagejpeg($canvas, null, self::JPEG_QUALITY);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);

        return [
            'image_base64' => base64_encode($jpeg),
            'mimetype' => 'image/jpeg',
            'width' => $newwidth,
            'height' => $newheight,
        ];
    }
}
