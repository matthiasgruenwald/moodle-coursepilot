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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\xml_activity_creator;

defined('MOODLE_INTERNAL') || die();

/**
 * Creates a developed (not catalogued) activity from an activity XML (Spec 0026, #590,
 * ADR 0028). Thin adapter: checks parameters and rights, calls
 * {@see xml_activity_creator}, shapes the answer.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class create_activity_from_xml extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Developed activity type, e.g. "book"'),
            'section' => new external_value(PARAM_INT, 'Section number (0 = general section)'),
            'activity_xml' => new external_value(PARAM_RAW, 'Activity XML (<module>.xml of a backup), e.g. from coursepilot_export_default_activity'),
            'hidden' => new external_value(PARAM_BOOL, 'Leave the activity hidden after the check', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * @param int $courseid
     * @param string $modname
     * @param int $section
     * @param string $activityxml
     * @param bool $hidden
     * @return array
     */
    public static function execute(int $courseid, string $modname, int $section, string $activityxml, bool $hidden = false): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'modname' => $modname,
            'section' => $section,
            'activity_xml' => $activityxml,
            'hidden' => $hidden,
        ]);
        if ($params['section'] < 0) {
            throw new \invalid_parameter_exception('section must not be negative.');
        }
        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:manageactivities', $context);
        require_capability('moodle/backup:backupactivity', $context);
        require_capability('moodle/restore:restoreactivity', $context);
        require_capability('mod/' . $params['modname'] . ':addinstance', $context);

        $result = xml_activity_creator::create(
            $params['courseid'],
            $params['modname'],
            $params['section'],
            $params['activity_xml'],
            $params['hidden']
        );
        return [
            'cmid' => $result['cmid'],
            'presets' => $result['presets'],
            'message' => $result['presets']
                ? get_string('createfromxmlpresets', 'local_coursepilot', implode(', ', $result['presets']))
                : '',
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the new activity'),
            'presets' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Path of a field Moodle filled in that was not in the XML'),
                'Moodle presets'
            ),
            'message' => new external_value(PARAM_RAW, 'Hint about Moodle presets, empty if there are none'),
        ]);
    }
}
