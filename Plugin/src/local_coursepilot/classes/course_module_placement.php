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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Course module placement (Spec 0026 module 4, ADR 0028): position, visibility and the
 * one delete of the plugin, all through the allowed Moodle paths.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class course_module_placement {

    /**
     * Moves a cm into a section, before $beforecmid (null = section end).
     * Capability moodle/course:manageactivities is checked by cm_move itself.
     */
    public static function move_to(int $cmid, int $sectionid, ?int $beforecmid): void {
        $courseid = self::course_of($cmid);
        $course = get_course($courseid);
        $format = course_get_format($course);
        $format->get_stateactions_instance()->cm_move(
            $format->get_stateupdates_instance(),
            $course,
            [$cmid],
            $sectionid,
            $beforecmid
        );
    }

    /**
     * Places a cm directly behind another one (the same section as $aftercmid).
     */
    public static function place_after(int $cmid, int $aftercmid): void {
        $courseid = self::course_of($cmid);
        $modinfo = get_fast_modinfo($courseid);
        $after = $modinfo->get_cm($aftercmid);
        $siblings = $modinfo->sections[$after->sectionnum] ?? [];
        $position = array_search($after->id, $siblings, true);
        if ($position === false) {
            throw new \coding_exception('place_after: the target cm is not in its section');
        }
        $successor = $siblings[$position + 1] ?? null;
        self::move_to($cmid, (int) $after->section, $successor === null ? null : (int) $successor);
    }

    private static function course_of(int $cmid): int {
        global $DB;
        return (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], MUST_EXIST);
    }

    /**
     * Shows or hides a cm (cmactions::set_visibility).
     */
    public static function set_visible(int $cmid, bool $visible): void {
        $courseid = self::course_of($cmid);
        (new \core_courseformat\local\cmactions(get_course($courseid)))->set_visibility($cmid, $visible ? 1 : 0);
    }

    /**
     * The only delete in the plugin. Only for cmids that arose in the same call and were
     * never visible to the teacher (the caller guarantees that): deletes at once, without
     * recycle bin. An active bin hook still files an item; it is removed again here.
     * A half-made row (instance = 0) is removed by hand, the regular delete cannot take it.
     * An unknown cmid is a no-op (idempotent).
     */
    public static function discard_failed(int $cmid): void {
        global $DB;
        $cm = $DB->get_record('course_modules', ['id' => $cmid]);
        if (!$cm) {
            return;
        }
        if ((int) $cm->instance === 0) {
            delete_mod_from_section($cm->id, $cm->section);
            $DB->delete_records('course_modules', ['id' => $cm->id]);
            rebuild_course_cache($cm->course, true);
            return;
        }
        $hasbin = $DB->get_manager()->table_exists('tool_recyclebin_course');
        $binmark = $hasbin
            ? (int) $DB->get_field_sql('SELECT MAX(id) FROM {tool_recyclebin_course} WHERE courseid = ?', [$cm->course])
            : 0;
        rebuild_course_cache($cm->course, true);
        $info = get_fast_modinfo($cm->course)->get_cm($cmid);
        $name = $info->name;
        course_get_format($cm->course)->delete_module($info, false);
        if ($hasbin) {
            $bin = new \tool_recyclebin\course_bin($cm->course);
            // Only the item of this very cm: other deletions in the course stay untouched.
            $select = 'courseid = ? AND id > ? AND module = ? AND name = ?';
            $mine = [$cm->course, $binmark, $cm->module, $name];
            foreach ($DB->get_records_select('tool_recyclebin_course', $select, $mine) as $item) {
                $bin->delete_item($item);
            }
        }
        rebuild_course_cache($cm->course, true);
    }
}
