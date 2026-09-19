<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_course;

/**
 * Ensures that a course section exists and optionally updates it.
 */
class ensure_section extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT,  'Course ID'),
            'sectionnum' => new external_value(PARAM_INT,  'Section number (0-based)'),
            'name'       => new external_value(PARAM_TEXT, 'Section name', VALUE_DEFAULT, ''),
            'summary'    => new external_value(PARAM_RAW,  'Section summary (HTML)', VALUE_DEFAULT, ''),
            'visible'    => new external_value(PARAM_INT,  'Visible (1), hidden (0), or unchanged (-1)', VALUE_DEFAULT, -1),
        ]);
    }

    public static function execute(int $courseid, int $sectionnum, string $name = '', string $summary = '', int $visible = -1): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'name'       => $name,
            'summary'    => $summary,
            'visible'    => $visible,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);
        require_capability('moodle/course:update', $context);

        $existing = $DB->record_exists('course_sections', [
            'course' => $params['courseid'],
            'section' => $params['sectionnum'],
        ]);

        course_create_sections_if_missing($params['courseid'], $params['sectionnum']);

        $section = $DB->get_record('course_sections',
            ['course' => $params['courseid'], 'section' => $params['sectionnum']],
            '*', MUST_EXIST);

        $data = new \stdClass();
        $data->id = $section->id;
        $changed = false;

        if ($params['name'] !== '') {
            $data->name = $params['name'];
            $changed = true;
        }

        if ($params['summary'] !== '') {
            $data->summary = $params['summary'];
            $data->summaryformat = FORMAT_HTML;
            $changed = true;
        }

        if ($params['visible'] !== -1) {
            $data->visible = $params['visible'];
            $changed = true;
        }

        if ($changed) {
            $DB->update_record('course_sections', $data);
        }
        rebuild_course_cache($params['courseid'], true);

        return [
            'sectionid' => (int) $section->id,
            'sectionnum' => (int) $section->section,
            'created' => $existing ? 0 : 1,
            'message' => 'Section ' . $params['sectionnum'] . ' ensured successfully.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'sectionid' => new external_value(PARAM_INT,  'Section DB ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number'),
            'created' => new external_value(PARAM_INT, '1 if the section was created, otherwise 0'),
            'message' => new external_value(PARAM_TEXT, 'Success message'),
        ]);
    }
}
