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
use local_coursepilot\availability_privacy;
use local_coursepilot\catalog\module_state;
use local_coursepilot\catalog\registry;
use local_coursepilot\catalog\shared_block;

defined('MOODLE_INTERNAL') || die();

/**
 * Kurskatalog serverseitig (#341): kompakte, filterbare Lesesicht auf einen
 * Kurs - Abschnitte, sichtbare Inhalte, Teststruktur, Sichtbarkeit, Abschluss
 * und Voraussetzungen, mit maskiertem Personenbezug.
 *
 * Eigenstaendige Portierung von
 * local_coursepilot\external\get_course_catalog: local_coursepilot hat laut
 * Spec 0012 ("keine Abhaengigkeit zu local_coursepilot") keine
 * Laufzeitabhaengigkeit auf das andere Plugin - eine direkte Delegation an
 * dessen Klasse ist auf der lokal_coursepilot-Testinstanz (Spike, traegt
 * ausschliesslich local_coursepilot) ein Fatal Error ("Class ... not found",
 * Fund aus dem PHPUnit-Lauf zu #341). Der Vertrag (Feldnamen, Maskierung,
 * detail=compact/full) bleibt bewusst identisch zum lokalen Werkzeug.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
class get_course_catalog extends external_api {

    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based, -1 = all sections)', VALUE_DEFAULT, -1),
            'modname'    => new external_value(PARAM_TEXT, 'Optional module type filter: page, label, assign, quiz, url', VALUE_DEFAULT, ''),
            'detail'     => new external_value(PARAM_ALPHA, 'compact or full content detail', VALUE_DEFAULT, 'compact'),
        ]);
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modname
     * @param string $detail
     * @return array
     */
    public static function execute(
        int $courseid,
        int $sectionnum = -1,
        string $modname = '',
        string $detail = 'compact'
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid'   => $courseid,
            'sectionnum' => $sectionnum,
            'modname'    => $modname,
            'detail'     => $detail,
        ]);

        $context = context_course::instance($params['courseid']);
        self::validate_context($context);
        require_capability('local/coursepilot:use', $context);

        $fullcontent = strtolower($params['detail']) === 'full';
        $modulefilter = trim($params['modname']);

        return [
            'source' => 'aus Moodle gelesen',
            'courseid' => (int) $params['courseid'],
            'filters' => [
                'sectionnum' => (int) $params['sectionnum'],
                'modname' => $modulefilter,
                'detail' => $fullcontent ? 'full' : 'compact',
            ],
            'sections' => self::sections(
                (int) $params['courseid'],
                (int) $params['sectionnum'],
                $modulefilter,
                $fullcontent
            ),
        ];
    }

    /**
     * @param int $courseid
     * @param int $sectionnum
     * @param string $modulefilter
     * @param bool $fullcontent
     * @return array
     */
    private static function sections(int $courseid, int $sectionnum, string $modulefilter, bool $fullcontent): array {
        global $DB;

        $where = 'course = :courseid';
        $params = ['courseid' => $courseid];
        if ($sectionnum >= 0) {
            $where .= ' AND section = :sectionnum';
            $params['sectionnum'] = $sectionnum;
        }

        $sections = $DB->get_records_select(
            'course_sections',
            $where,
            $params,
            'section ASC',
            'id, section, name, summary, visible, availability'
        );

        $result = [];
        foreach ($sections as $section) {
            $result[] = [
                'id' => (int) $section->id,
                'sectionnum' => (int) $section->section,
                'name' => $section->name ?? '',
                'summary' => self::content_field((string) ($section->summary ?? ''), $fullcontent),
                'visible' => (int) $section->visible,
                'availability' => availability_privacy::sanitize((string) ($section->availability ?? '')),
                'modules' => self::modules((int) $section->id, $modulefilter, $fullcontent),
            ];
        }
        return $result;
    }

    /**
     * @param int $sectionid
     * @param string $modulefilter
     * @param bool $fullcontent
     * @return array
     */
    private static function modules(int $sectionid, string $modulefilter, bool $fullcontent): array {
        global $DB;

        $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id, sequence', MUST_EXIST);
        $where = 'cm.section = :sectionid AND cm.deletioninprogress = 0';
        $params = ['sectionid' => $sectionid];
        if ($modulefilter !== '') {
            $where .= ' AND m.name = :modname';
            $params['modname'] = $modulefilter;
        }

        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.visible, cm.visibleoncoursepage, cm.groupmode, cm.instance, cm.availability,
                    cm.completion, cm.completionview, cm.completionpassgrade,
                    m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE $where
           ORDER BY cm.id",
            $params
        );
        $rows = array_values($rows);
        usort($rows, function($a, $b) use ($section) {
            return self::sequence_index((string) $section->sequence, (int) $a->cmid)
                <=> self::sequence_index((string) $section->sequence, (int) $b->cmid);
        });

        $result = [];
        foreach ($rows as $row) {
            $details = self::module_details((string) $row->modname, (int) $row->instance, (int) $row->cmid, $fullcontent);
            $result[] = array_merge([
                'cmid' => (int) $row->cmid,
                'modname' => (string) $row->modname,
                'name' => $details['name'],
                'visible' => (int) $row->visible,
                'visibleoncoursepage' => (int) $row->visibleoncoursepage,
                'groupmode' => (int) $row->groupmode,
                'completion' => [
                    'completion' => (int) $row->completion,
                    'completionview' => (int) $row->completionview,
                    'completionpassgrade' => (int) $row->completionpassgrade,
                ],
                'availability' => availability_privacy::sanitize((string) ($row->availability ?? '')),
                'content' => $details['content'],
                'settings' => $details['settings'],
                'quizslots' => $details['quizslots'],
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
     * @param string $modname
     * @param int $instanceid
     * @param int $cmid
     * @param bool $fullcontent
     * @return array
     */
    private static function module_details(string $modname, int $instanceid, int $cmid, bool $fullcontent): array {
        $catalogclass = registry::for($modname);
        if ($catalogclass === null) {
            return module_state::unknown($modname, $instanceid, $fullcontent);
        }
        return $catalogclass::state($instanceid, $cmid, $fullcontent);
    }

    /**
     * @param string $html
     * @param bool $fullcontent
     * @return array
     */
    private static function content_field(string $html, bool $fullcontent): array {
        return [
            'html' => $fullcontent ? $html : '',
            'preview' => self::preview($html, $fullcontent),
            'truncated' => !$fullcontent && trim($html) !== '',
        ];
    }

    /**
     * @param string $html
     * @param bool $fullcontent
     * @return string
     */
    private static function preview(string $html, bool $fullcontent): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        if ($fullcontent || strlen($text) <= 280) {
            return $text;
        }
        return substr($text, 0, 277) . '...';
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $content = new external_single_structure([
            'html' => new external_value(PARAM_RAW, 'Full HTML content when detail=full, otherwise empty'),
            'preview' => new external_value(PARAM_TEXT, 'Compact teacher-readable content preview'),
            'truncated' => new external_value(PARAM_BOOL, 'True when compact mode omitted full HTML'),
        ]);

        $settings = new external_multiple_structure(
            new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Setting name'),
                'value' => new external_value(PARAM_RAW, 'Setting value'),
            ]),
            'Module settings as name/value pairs'
        );

        return new external_single_structure([
            'source' => new external_value(PARAM_TEXT, 'Source marker, always "aus Moodle gelesen"'),
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'filters' => new external_single_structure([
                'sectionnum' => new external_value(PARAM_INT, 'Applied section filter'),
                'modname' => new external_value(PARAM_TEXT, 'Applied module type filter'),
                'detail' => new external_value(PARAM_TEXT, 'Applied detail mode'),
            ]),
            'sections' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Section DB ID'),
                    'sectionnum' => new external_value(PARAM_INT, 'Section number'),
                    'name' => new external_value(PARAM_TEXT, 'Section name'),
                    'summary' => $content,
                    'visible' => new external_value(PARAM_INT, 'Visible flag'),
                    'availability' => new external_value(PARAM_RAW, 'Moodle availability JSON, if set'),
                    'modules' => new external_multiple_structure(
                        new external_single_structure([
                            'cmid' => new external_value(PARAM_INT, 'Course module ID'),
                            'modname' => new external_value(PARAM_TEXT, 'Module type'),
                            'name' => new external_value(PARAM_TEXT, 'Module display name'),
                            'visible' => new external_value(PARAM_INT, 'Visible flag'),
                            'visibleoncoursepage' => new external_value(PARAM_INT, 'Stealth: 1 = shown on course page, 0 = stealth'),
                            'coursepagevisibility' => new external_value(PARAM_TEXT, 'shown | stealth'),
                            'availability_status' => new external_value(PARAM_TEXT, 'shown | stealth | hidden'),
                            'groupmode' => new external_value(PARAM_INT, 'Group mode: 0 = none, 1 = separate, 2 = visible'),
                            'completion' => new external_single_structure([
                                'completion' => new external_value(PARAM_INT, 'Completion mode'),
                                'completionview' => new external_value(PARAM_INT, 'Require view completion flag'),
                                'completionpassgrade' => new external_value(PARAM_INT, 'Require pass grade completion flag'),
                            ]),
                            'availability' => new external_value(PARAM_RAW, 'Moodle availability JSON, if set'),
                            'content' => $content,
                            'settings' => $settings,
                            'quizslots' => new external_multiple_structure(
                                new external_single_structure([
                                    'slot' => new external_value(PARAM_INT, 'Quiz slot number'),
                                    'categoryid' => new external_value(PARAM_INT, 'Question category ID for moodle_get_question'),
                                    'questionbankentryid' => new external_value(PARAM_INT, 'Question bank entry ID'),
                                    'questionid' => new external_value(PARAM_INT, 'Latest question ID'),
                                    'version' => new external_value(PARAM_INT, 'Latest question version'),
                                    'questionname' => new external_value(PARAM_TEXT, 'Question name'),
                                    'qtype' => new external_value(PARAM_TEXT, 'Question type'),
                                ]),
                                'Quiz/test question structure'
                            ),
                        ]),
                        'Course modules in this section'
                    ),
                ]),
                'Course sections'
            ),
        ]);
    }
}
