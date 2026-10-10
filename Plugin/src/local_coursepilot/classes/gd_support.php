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
 * Single GD check (Spec 0018 §3.3). Moodle's admin/environment.xml requires
 * GD for every version; installation fails without it. Still checks defensively
 * with a useful message instead of an imagecreatefromstring() fatal error.
 * No fallback or second image path (§3.3): missing GD blocks preview and crop,
 * while upload and embedding remain available.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class gd_support {
    /**
     * Raster extensions GD can read/write; SVG excluded (§3.3/§5).
     * Shared by preview_material_file and crop_material_file so a future
     * format (e.g. AVIF) needs to be added only once.
     *
     * @var string[]
     */
    public const RASTER_IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** @var bool|null Testueberschreibung, siehe {@see self::override_for_testing()}. */
    private static ?bool $overridefortests = null;

    /**
     * @return bool
     */
    public static function available(): bool {
        return self::$overridefortests ?? (extension_loaded('gd') && function_exists('imagecreatefromstring'));
    }

    /**
     * Overrides {@see self::available()} only for PHPUnit to test missing GD
     * (Moodle requires GD; #430 code review). Reset to null after the test.
     *
     * @param bool|null $value null = no override (real check).
     */
    public static function override_for_testing(?bool $value): void {
        self::$overridefortests = $value;
    }
}
