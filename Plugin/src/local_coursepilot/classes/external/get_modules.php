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
use local_coursepilot\catalog\shared_block;

defined('MOODLE_INTERNAL') || die();

/**
 * Aktivitaeten eines Kurses oder Abschnitts (#342): Kennungen (cmid, Typ,
 * Name) fuer gezielte Zugriffe, ohne Kursinhalt mitzuliefern.
 *
 * Eigenstaendige Portierung von local_coursepilot\external\get_modules -
 * local_coursepilot hat laut Spec 0012 keine Laufzeitabhaengigkeit auf das
 * andere Plugin (siehe get_course_catalog.php aus #341, derselbe Fund).
 * Vertrag (Feldnamen) bleibt identisch zum lokalen Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_modules extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based, -1 = all sections)', VALUE_DEFAULT, -1),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @return array
     */
    public static function execute(int $courseid, int $sectionnum = -1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        $where = 'cm.course = :courseid AND cm.deletioninprogress = 0';
        $sqlparams = ['courseid' => $params['courseid']];

        if ($params['sectionnum'] >= 0) {
            $section = $DB->get_record('course_sections',
                ['course' => $params['courseid'], 'section' => $params['sectionnum']], 'id');
            if ($section) {
                $where .= ' AND cm.section = :sectionid';
                $sqlparams['sectionid'] = $section->id;
            }
        }

        $sql = "SELECT cm.id as cmid, cm.visible, cm.visibleoncoursepage, cs.section as sectionnum, cs.sequence,
                       m.name as modname, cm.instance
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE $where
              ORDER BY cs.section";

        $rows = array_values($DB->get_records_sql($sql, $sqlparams));
        usort($rows, function($a, $b) {
            return [$a->sectionnum, self::sequence_index((string) $a->sequence, (int) $a->cmid)]
                <=> [$b->sectionnum, self::sequence_index((string) $b->sequence, (int) $b->cmid)];
        });

        $result = [];
        foreach ($rows as $row) {
            $displayname = '';
            try {
                $rec = $DB->get_record($row->modname, ['id' => $row->instance], 'name', IGNORE_MISSING);
                $displayname = $rec ? $rec->name : '';
            } catch (\Exception $e) {
                $displayname = '';
            }

            $result[] = array_merge([
                'cmid'       => (int) $row->cmid,
                'sectionnum' => (int) $row->sectionnum,
                'modname'    => $row->modname,
                'name'       => $displayname,
                'visible'    => (int) $row->visible,
                'visibleoncoursepage' => (int) $row->visibleoncoursepage,
            ], shared_block::derive_visibility((int) $row->visible, (int) $row->visibleoncoursepage));
        }

        return $result;
    }

    /**
     * @param string $sequence
     * @param int $cmid
     * @return int
     */
    private static function sequence_index(string $sequence, int $cmid): int {
        $ids = array_values(array_filter(array_map('intval', explode(',', $sequence))));
        $index = array_search($cmid, $ids, true);
        return $index === false ? PHP_INT_MAX : $index;
    }

    /**
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'cmid'       => new external_value(PARAM_INT,  'Course module ID (use for update calls)'),
                'sectionnum' => new external_value(PARAM_INT,  'Section number'),
                'modname'    => new external_value(PARAM_TEXT, 'Module type (page, assign, label, url...)'),
                'name'       => new external_value(PARAM_TEXT, 'Display name of the activity'),
                'visible'    => new external_value(PARAM_INT,  'Visible (1) or hidden (0)'),
                'visibleoncoursepage' => new external_value(PARAM_INT, 'Stealth: 1 = shown on course page, 0 = stealth'),
                'coursepagevisibility' => new external_value(PARAM_TEXT, 'shown | stealth'),
                'availability_status' => new external_value(PARAM_TEXT, 'shown | stealth | hidden'),
            ])
        );
    }
}
