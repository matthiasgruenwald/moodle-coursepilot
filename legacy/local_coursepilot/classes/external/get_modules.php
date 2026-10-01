<?php
namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;

class get_modules extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based)', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(int $courseid, int $sectionnum = -1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'sectionnum' => $sectionnum,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        // Build query
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
            // Get the display name from the module's own table
            $displayname = '';
            try {
                $rec = $DB->get_record($row->modname, ['id' => $row->instance], 'name', IGNORE_MISSING);
                $displayname = $rec ? $rec->name : '';
            } catch (\Exception $e) {
                $displayname = '';
            }

            $result[] = [
                'cmid'                => (int) $row->cmid,
                'sectionnum'          => (int) $row->sectionnum,
                'modname'             => $row->modname,
                'name'                => $displayname,
                'visible'             => (int) $row->visible,
                'visibleoncoursepage' => (int) $row->visibleoncoursepage,
                'availability_status' => ((int) $row->visible === 0) ? 'hidden' : (((int) $row->visibleoncoursepage === 0) ? 'stealth' : 'shown'),
            ];
        }

        return $result;
    }

    private static function sequence_index(string $sequence, int $cmid): int {
        $ids = array_values(array_filter(array_map('intval', explode(',', $sequence))));
        $index = array_search($cmid, $ids, true);
        return $index === false ? PHP_INT_MAX : $index;
    }

    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'cmid'                => new external_value(PARAM_INT,  'Course module ID (use for update calls)'),
                'sectionnum'          => new external_value(PARAM_INT,  'Section number'),
                'modname'             => new external_value(PARAM_TEXT, 'Module type (page, assign, label, url...)'),
                'name'                => new external_value(PARAM_TEXT, 'Display name of the activity'),
                'visible'             => new external_value(PARAM_INT,  'Visible: 1 = sichtbar/stealth, 0 = verborgen'),
                'visibleoncoursepage' => new external_value(PARAM_INT,  'Kursseiten-Sichtbarkeit: 1 = auf Kursseite, 0 = Stealth'),
                'availability_status' => new external_value(PARAM_TEXT, 'shown | stealth | hidden'),
            ])
        );
    }
}
