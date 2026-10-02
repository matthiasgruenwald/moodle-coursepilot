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
            'dry_run' => new external_value(PARAM_BOOL, 'Only with replaces_cmid: write nothing, return the references to the old activity (plan preview)', VALUE_DEFAULT, false),
            'replaces_cmid' => new external_value(PARAM_INT, 'Supersede this activity (same type, same course): the new one is placed directly behind it, the old one is only hidden. 0 = create only', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * @param int $courseid
     * @param string $modname
     * @param int $section
     * @param string $activityxml
     * @param bool $hidden
     * @param int $replacescmid
     * @param bool $dryrun
     * @return array
     */
    public static function execute(
        int $courseid,
        string $modname,
        int $section,
        string $activityxml,
        bool $hidden = false,
        int $replacescmid = 0,
        bool $dryrun = false
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'modname' => $modname,
            'section' => $section,
            'activity_xml' => $activityxml,
            'hidden' => $hidden,
            'replaces_cmid' => $replacescmid,
            'dry_run' => $dryrun,
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

        if ($params['replaces_cmid']) {
            $oldcontext = \context_module::instance($params['replaces_cmid']);
            require_capability('moodle/course:manageactivities', $oldcontext);
            require_capability('moodle/course:activityvisibility', $oldcontext);
        }
        if ($params['dry_run']) {
            if (!$params['replaces_cmid']) {
                throw new \invalid_parameter_exception('dry_run needs replaces_cmid.');
            }
            $preview = xml_activity_creator::preview_supersede(
                $params['courseid'], $params['modname'], $params['activity_xml'], $params['replaces_cmid']);
            return self::shape(['cmid' => 0, 'presets' => []] + $preview);
        }
        $result = xml_activity_creator::create(
            $params['courseid'],
            $params['modname'],
            $params['section'],
            $params['activity_xml'],
            $params['hidden'],
            $params['replaces_cmid'] ?: null
        );
        return self::shape($result);
    }

    /**
     * @param array{cmid: int, presets: string[], references: array, successor_cmid: int, hidden_predecessors: int} $result
     * @return array
     */
    private static function shape(array $result): array {
        $messages = [];
        if ($result['presets']) {
            $messages[] = get_string('createfromxmlpresets', 'local_coursepilot', implode(', ', $result['presets']));
        }
        if ($result['references']) {
            $places = implode(', ', array_map(static fn($r) => $r['kind'] . ' (' . $r['location'] . ')', $result['references']));
            $messages[] = get_string('createfromxmlreferences', 'local_coursepilot', $places);
        }
        if ($result['successor_cmid']) {
            $messages[] = get_string('createfromxmlsuccessor', 'local_coursepilot', $result['successor_cmid']);
        }
        if ($result['hidden_predecessors']) {
            $messages[] = get_string('createfromxmlhiddenpredecessors', 'local_coursepilot', $result['hidden_predecessors']);
        }
        return [
            'cmid' => $result['cmid'],
            'presets' => $result['presets'],
            'references' => $result['references'],
            'successor_cmid' => $result['successor_cmid'],
            'hidden_predecessors' => $result['hidden_predecessors'],
            'message' => implode(' ', $messages),
        ];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the new activity (0 for a dry run)'),
            'presets' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Path of a field Moodle filled in that was not in the XML'),
                'Moodle presets'
            ),
            'references' => new external_multiple_structure(
                new external_single_structure([
                    'kind' => new external_value(PARAM_ALPHANUMEXT, 'activity_availability, section_availability or course_completion'),
                    'location_id' => new external_value(PARAM_INT, 'cmid, section id or course id of the referencing place'),
                    'location' => new external_value(PARAM_TEXT, 'Readable place'),
                ]),
                'Places that still point at the superseded activity (only with replaces_cmid); not resolved'
            ),
            'successor_cmid' => new external_value(PARAM_INT,
                'Newest activity that already supersedes replaces_cmid (ask the teacher whether to supersede that one instead), '
                . '0 if none'),
            'hidden_predecessors' => new external_value(PARAM_INT,
                'Hidden earlier versions in the chain behind the new activity: replaces_cmid (hidden by superseding) '
                . 'plus its hidden predecessors; 0 without replaces_cmid'),
            'message' => new external_value(PARAM_RAW,
                'Hints about Moodle presets, unresolved references and the superseding chain, empty if there are none'),
        ]);
    }
}
