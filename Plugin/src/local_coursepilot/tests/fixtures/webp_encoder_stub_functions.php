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

/**
 * Test fixture: replaceable imagewebp() for local_coursepilot\external classes.
 *
 * Not every GD build ships the optional WebP encoder. The function lives in the namespace of the code under test,
 * where PHP resolves the unqualified call to it. While stubbed it writes a size marker instead of an image,
 * otherwise it delegates to the real encoder (see tests/classes/webp_encoder_stub.php).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\external;

if (!function_exists('local_coursepilot\external\imagewebp')) {
    /**
     * Writes "webp:<width>x<height>" while stubbed, otherwise encodes for real.
     *
     * @param \GdImage $image Canvas to encode.
     * @return bool
     */
    function imagewebp(\GdImage $image): bool {
        if (\local_coursepilot\tests\webp_encoder_stub::$active) {
            echo 'webp:' . imagesx($image) . 'x' . imagesy($image);
            return true;
        }
        return \imagewebp($image);
    }
}
