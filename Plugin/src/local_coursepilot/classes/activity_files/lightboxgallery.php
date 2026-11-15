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

namespace local_coursepilot\activity_files;

/**
 * Lightboxgallery's root image area and native caption/thumbnail operations.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class lightboxgallery {
    /** @var array<string, int> Allowed file areas and their fixed item IDs. */
    public const FILEAREAS = ['gallery_images' => 0];
    /** @var string Native permission to add gallery images. */
    public const CAPABILITY = 'mod/lightboxgallery:addimage';

    /**
     * Provides complete.
     *
     * @param \stdClass $cm The cm.
     * @param \stored_file $file The file.
     * @param string $caption The caption.
     */
    public static function complete(\stdClass $cm, \stored_file $file, string $caption): void {
        global $CFG, $DB;
        if (!$file->is_valid_image()) {
            throw new \moodle_exception('activityfileinvalidimage', 'local_coursepilot', '', $file->get_filename());
        }
        require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');
        $gallery = $DB->get_record('lightboxgallery', ['id' => $cm->instance], '*', MUST_EXIST);
        // The native constructor generates missing thumbnails with the module's fixed crop.
        $image = new \lightboxgallery_image($file, $gallery, $cm);
        $image->set_caption($caption);
    }
}
