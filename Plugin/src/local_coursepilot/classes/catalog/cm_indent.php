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

namespace local_coursepilot\catalog;

use core_courseformat\stateactions;
use core_courseformat\stateupdates;
use moodle_exception;

/**
 * Indentation of an activity on the course page (course_modules.indent).
 *
 * update_moduleinfo() writes the course module row it is given, so an update
 * sets the value on that row first. add_moduleinfo() builds its row itself and
 * ignores the property, so a new activity is indented afterwards through the
 * course editor's own actions (cm_moveright/cm_moveleft), which check the
 * capability, rebuild the course cache and raise course_module_updated.
 * Moodle's interface knows two levels, so the catalog allows exactly 0 and 1.
 * Whether a course shows indentation is the course format's decision.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class cm_indent {
    /** @var string Catalog field name, also the course_modules column. */
    public const FIELD = 'indent';

    /**
     * Refuses a changed indentation in a course whose format does not display one.
     * Resetting to 0 stays possible, it only removes a leftover, and repeating
     * the current value changes nothing.
     *
     * @param \stdClass $course
     * @param mixed[] $changes Named fields of the write.
     * @param int $current Indentation the activity has now (0 for a new one).
     * @return void
     * @throws moodle_exception indentationnotsupported
     */
    public static function assert_supported(\stdClass $course, array $changes, int $current = 0): void {
        $requested = (int) ($changes[self::FIELD] ?? 0);
        if ($requested === 0 || $requested === $current || self::course_shows_indentation($course)) {
            return;
        }
        throw new moodle_exception('indentationnotsupported', 'local_coursepilot', '', [
            'format' => course_get_format($course)->get_format(),
        ]);
    }

    /**
     * Whether the course's format displays indentation.
     *
     * @param \stdClass $course
     * @return bool
     */
    public static function course_shows_indentation(\stdClass $course): bool {
        return course_get_format($course)->uses_indentation();
    }

    /**
     * Removes the indentation from a patch for a course that does not show one,
     * so a restore still brings back the rest.
     *
     * @param mixed[] $patch
     * @param \stdClass $course
     * @return mixed[]
     */
    public static function without_unsupported(array $patch, \stdClass $course): array {
        if (!self::course_shows_indentation($course)) {
            unset($patch[self::FIELD]);
        }
        return $patch;
    }

    /**
     * Puts the named indentation on the course module row that update_moduleinfo()
     * writes back, so the update records one change instead of two.
     *
     * @param \stdClass $cm Course module row handed to update_moduleinfo().
     * @param mixed[] $changes Named fields of the write.
     * @return void
     */
    public static function stage(\stdClass $cm, array $changes): void {
        if (array_key_exists(self::FIELD, $changes)) {
            $cm->indent = (int) $changes[self::FIELD];
        }
    }

    /**
     * Indents a newly created activity; does nothing when the
     * write does not name the field or the value already holds.
     *
     * @param \stdClass $course
     * @param int $cmid
     * @param mixed[] $changes Named fields of the write.
     * @return void
     */
    public static function apply(\stdClass $course, int $cmid, array $changes): void {
        global $DB;

        if (!array_key_exists(self::FIELD, $changes)) {
            return;
        }
        $indent = (int) $changes[self::FIELD];
        if ((int) $DB->get_field('course_modules', 'indent', ['id' => $cmid], MUST_EXIST) === $indent) {
            return;
        }
        $actions = new stateactions();
        $updates = new stateupdates(course_get_format($course));
        if ($indent === 1) {
            $actions->cm_moveright($updates, $course, [$cmid]);
        } else {
            $actions->cm_moveleft($updates, $course, [$cmid]);
        }
    }
}
