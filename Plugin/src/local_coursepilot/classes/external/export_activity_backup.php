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

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_coursepilot\activity_backup;
use moodle_exception;

/**
 * Activity XML of an existing activity (Spec 0026, #588): thin adapter over
 * {@see activity_backup::export()}. Read-only despite the "export_" prefix
 * (exception in tool_registry::is_write_class): nothing is written to the course.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class export_activity_backup extends external_api {
    /**
     * Describes the parameters of execute.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the activity to export'),
        ]);
    }

    /**
     * Runs the export activity backup tool.
     *
     * @param int $cmid
     * @return mixed[]
     * @throws moodle_exception clonenobackupsupport
     */
    public static function execute(int $cmid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $cm = get_coursemodule_from_id('', $params['cmid'], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/backup:backupactivity', $context);
        if (!plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2)) {
            throw new moodle_exception('clonenobackupsupport', 'local_coursepilot', '', ['modname' => $cm->modname]);
        }

        return [
            'cmid' => (int) $cm->id,
            'modname' => $cm->modname,
            'xml' => activity_backup::export($cm),
        ];
    }

    /**
     * Describes the return value of execute.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the exported activity'),
            'modname' => new external_value(PARAM_ALPHANUMEXT, 'Activity type, e.g. "book"'),
            'xml' => new external_value(PARAM_RAW, 'Activity XML (<module>.xml of the backup), without user data'),
        ]);
    }
}
