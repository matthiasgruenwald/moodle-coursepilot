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

use local_coursepilot\history\retention;
use local_coursepilot\history\version_writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Change history observer (#385, Spec 0015 §10.8): serializes current state
 * to snapshot tables without calling MCP tools or web services. Also deletes
 * history when an activity or course is deleted (#387, Spec 0015 §10.7).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class observer {
    /**
     * Version 1 is captured on creation (#386, Spec 0015 §10.3). Activities
     * created since history was introduced therefore bypass discovered-state
     * backfill on their first course_module_updated event.
     *
     * @param \core\event\course_module_created $event
     * @return void
     */
    public static function course_module_created(\core\event\course_module_created $event): void {
        version_writer::capture((int) $event->objectid, (int) $event->userid);
    }

    /**
     * Capture the activity state after a native Moodle update (#385).
     *
     * @param \core\event\course_module_updated $event
     * @return void
     */
    public static function course_module_updated(\core\event\course_module_updated $event): void {
        version_writer::capture_on_update((int) $event->objectid, (int) $event->userid);
    }

    /**
     * Activity cascade (#387): deleting an activity deletes its history.
     * The recycle bin already retains content as .mbz for seven days;
     * change history is not a second recycle bin.
     *
     * @param \core\event\course_module_deleted $event
     * @return void
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        retention::purge_cm((int) $event->objectid);
    }

    /**
     * Course cascade (#387): deleting a course deletes its history.
     * course_modules has already been deleted, so association uses the stored
     * courseid; see {@see \local_coursepilot\history\version_writer::capture()}.
     *
     * @param \core\event\course_deleted $event
     * @return void
     */
    public static function course_deleted(\core\event\course_deleted $event): void {
        retention::purge_course((int) $event->objectid);
    }

    /**
     * Quiz arrangement state (#396, Spec 0015 §10): one observer for all
     * 16 mod_quiz structure events (see db/events.php) that can change
     * quiz_slots/question_references/quiz_sections/quiz_feedback. All use module
     * context (structure.php: $this->quizobj->get_context()), so cmid always
     * comes from context rather than varying event fields. Shares
     * capture_on_update() with course_module_updated (#385); existing activities
     * without history first receive a backfilled discovered version 1.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function quiz_structure_changed(\core\event\base $event): void {
        $cmid = (int) $event->get_context()->instanceid;
        version_writer::capture_on_update($cmid, (int) $event->userid);
    }
}
