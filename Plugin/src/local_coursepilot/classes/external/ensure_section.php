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

namespace local_coursepilot\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Write core 13 (Spec 0015 phase 3, ticket #391): idempotent creation of a
 * section - creates it if "sectionnum" does not exist yet, otherwise only
 * the name is reconciled (no second section, no other
 * change to an existing section).
 *
 * Creation runs via course_create_sections_if_missing() (course/lib.php),
 * which internally calls {@see \core_courseformat\local\sectionactions::create_if_missing()}
 * - the same existence check by section number that this
 * endpoint needs for idempotency anyway, not duplicated here.
 * Name reconciliation runs via {@see \core_courseformat\local\sectionactions::update()}.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class ensure_section extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'name' => new external_value(PARAM_TEXT, 'Optional section name', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);
    }

    /**
     * Runs the ensure section tool.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param string|null $name
     * @return array
     * @throws \moodle_exception invalidsectionnum
     */
    public static function execute(int $courseid, int $sectionnum, ?string $name = null): array {
        global $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'sectionnum' => $sectionnum,
            'name' => $name,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        // Native permission check: creating/renaming sections is
        // course editing, same capability as course/editsection.php.
        require_capability('moodle/course:update', $context);

        if ($params['sectionnum'] < 0) {
            throw new \moodle_exception('invalidsectionnum', 'local_coursepilot', '', ['sectionnum' => $params['sectionnum']]);
        }

        require_once($CFG->dirroot . '/course/lib.php');
        $course = get_course($params['courseid']);

        $sections = get_fast_modinfo($course)->get_section_info_all();
        $existed = array_key_exists($params['sectionnum'], $sections);

        if (!$existed) {
            course_create_sections_if_missing($course, [$params['sectionnum']]);
            $sections = get_fast_modinfo($course)->get_section_info_all();
        }

        $sectioninfo = $sections[$params['sectionnum']];
        $oldname = (string) ($sectioninfo->name ?? '');
        $namechanged = false;

        $wantedname = $params['name'];
        if ($wantedname !== null && $wantedname !== $oldname) {
            // Note: course_update_section() (course/lib.php) is the thin,
            // stable wrapper around sectionactions::update() - identical to
            // what course/editsection.php calls when saving the form.
            course_update_section($course, $sectioninfo, ['name' => $wantedname]);
            $namechanged = true;
        }

        $finalname = $namechanged ? $wantedname : $oldname;

        return [
            'id' => (int) $sectioninfo->id,
            'sectionnum' => (int) $params['sectionnum'],
            'name' => $finalname,
            'created' => !$existed,
            'message' => self::build_message($params['sectionnum'], $existed, $namechanged, $oldname, $finalname),
        ];
    }

    /**
     * Builds message.
     *
     * @param int $sectionnum
     * @param bool $existed
     * @param bool $namechanged
     * @param string $oldname
     * @param string $finalname
     * @return string
     */
    private static function build_message(int $sectionnum, bool $existed, bool $namechanged, string $oldname, string $finalname): string {
        $a = (object) ['sectionnum' => $sectionnum, 'oldname' => $oldname, 'name' => $finalname];
        if (!$existed) {
            return $namechanged
                ? get_string('sectioncreatednamed', 'local_coursepilot', $a)
                : get_string('sectioncreated', 'local_coursepilot', $a);
        }
        if ($namechanged) {
            return get_string('sectionreusedrenamed', 'local_coursepilot', $a);
        }
        return get_string('sectionreused', 'local_coursepilot', $a);
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Section DB ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)'),
            'name' => new external_value(PARAM_TEXT, 'Current section name'),
            'created' => new external_value(PARAM_BOOL, 'true if the section was newly created'),
            'message' => new external_value(PARAM_RAW, 'Teacher-facing message'),
        ]);
    }
}
