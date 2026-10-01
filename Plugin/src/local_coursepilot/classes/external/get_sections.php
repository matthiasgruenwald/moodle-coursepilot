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

defined('MOODLE_INTERNAL') || die();

/**
 * Abschnittsliste eines Kurses (#342): Kennungen (id, Nummer, Name) fuer
 * gezielte Zugriffe.
 *
 * Eigenstaendige Portierung von local_coursepilot\external\get_sections -
 * local_coursepilot hat laut Spec 0012 keine Laufzeitabhaengigkeit auf das
 * andere Plugin (siehe get_course_catalog.php aus #341, derselbe Fund).
 * Vertrag (Feldnamen) bleibt identisch zum lokalen Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_sections extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
        ]);
    }

    /**
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        $sections = $DB->get_records('course_sections',
            ['course' => $params['courseid']],
            'section ASC',
            'id, section, name, summary, visible'
        );

        $result = [];
        foreach ($sections as $s) {
            $result[] = [
                'id'         => (int) $s->id,
                'sectionnum' => (int) $s->section,
                'name'       => $s->name ?? '',
                'summary'    => $s->summary ?? '',
                'visible'    => (int) $s->visible,
            ];
        }

        return $result;
    }

    /**
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'id'         => new external_value(PARAM_INT,  'Section DB ID'),
                'sectionnum' => new external_value(PARAM_INT,  'Section number (0-based)'),
                'name'       => new external_value(PARAM_TEXT, 'Section name'),
                'summary'    => new external_value(PARAM_RAW,  'Section summary HTML'),
                'visible'    => new external_value(PARAM_INT,  'Visible flag'),
            ])
        );
    }
}
