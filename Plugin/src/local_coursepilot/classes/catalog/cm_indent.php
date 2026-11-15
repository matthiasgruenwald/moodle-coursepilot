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
 * add_moduleinfo() and update_moduleinfo() ignore the property, so the write
 * goes through the course editor's own actions (cm_moveright/cm_moveleft), which
 * check the capability, rebuild the course cache and raise course_module_updated.
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
     * Refuses an indentation in a course whose format does not display one.
     * Resetting to 0 stays possible, it only removes a leftover.
     *
     * @param \stdClass $course
     * @param mixed[] $changes Named fields of the write.
     * @return void
     * @throws moodle_exception indentationnotsupported
     */
    public static function assert_supported(\stdClass $course, array $changes): void {
        if ((int) ($changes[self::FIELD] ?? 0) === 0 || course_get_format($course)->uses_indentation()) {
            return;
        }
        throw new moodle_exception('indentationnotsupported', 'local_coursepilot', '', [
            'format' => course_get_format($course)->get_format(),
        ]);
    }

    /**
     * Writes the named indentation of a saved activity; does nothing when the
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
