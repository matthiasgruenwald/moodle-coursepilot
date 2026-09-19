<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/grade/grading/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_course;
use context_module;
use local_coursepilot\assign_settings;
use local_coursepilot\availability_privacy;

/**
 * Returns a compact read-only Moodle catalog for Kurspilot planning.
 */
class get_course_catalog extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid'   => new external_value(PARAM_INT, 'Course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number (0-based, -1 = all sections)', VALUE_DEFAULT, -1),
            'modname'    => new external_value(PARAM_TEXT, 'Optional module type filter: page, label, assign, quiz, url', VALUE_DEFAULT, ''),
            'detail'     => new external_value(PARAM_ALPHA, 'compact or full content detail', VALUE_DEFAULT, 'compact'),
        ]);
    }

    public static function execute(
        int $courseid,
        int $sectionnum = -1,
        string $modname = '',
        string $detail = 'compact'
    ): array {
        global $DB;

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
            $result[] = [
                'cmid' => (int) $row->cmid,
                'modname' => (string) $row->modname,
                'name' => $details['name'],
                'visible' => (int) $row->visible,
                'visibleoncoursepage' => (int) $row->visibleoncoursepage,
                'availability_status' => ((int) $row->visible === 0) ? 'hidden' : (((int) $row->visibleoncoursepage === 0) ? 'stealth' : 'shown'),
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
            ];
        }
        return $result;
    }

    private static function sequence_index(string $sequence, int $cmid): int {
        $ids = array_values(array_filter(array_map('intval', explode(',', $sequence))));
        $index = array_search($cmid, $ids, true);
        return $index === false ? PHP_INT_MAX : $index;
    }

    private static function module_details(string $modname, int $instanceid, int $cmid, bool $fullcontent): array {
        global $DB;

        $details = [
            'name' => '',
            'content' => self::content_field('', $fullcontent),
            'settings' => [],
            'quizslots' => [],
        ];

        if (!in_array($modname, ['page', 'label', 'assign', 'quiz', 'url'], true)) {
            $record = $DB->get_record($modname, ['id' => $instanceid], 'name', IGNORE_MISSING);
            $details['name'] = $record ? (string) $record->name : '';
            return $details;
        }

        if ($modname === 'page') {
            $page = $DB->get_record('page', ['id' => $instanceid], 'name, intro, content', IGNORE_MISSING);
            if ($page) {
                $details['name'] = (string) $page->name;
                $details['content'] = self::content_field((string) $page->content, $fullcontent);
                $details['settings'] = self::settings([
                    'intro' => self::preview((string) $page->intro, $fullcontent),
                ]);
            }
            return $details;
        }

        if ($modname === 'label') {
            $label = $DB->get_record('label', ['id' => $instanceid], 'name, intro', IGNORE_MISSING);
            if ($label) {
                $details['name'] = (string) $label->name;
                $details['content'] = self::content_field((string) $label->intro, $fullcontent);
            }
            return $details;
        }

        if ($modname === 'assign') {
            $assign = $DB->get_record('assign', ['id' => $instanceid], 'id, name, intro, allowsubmissionsfromdate, duedate, cutoffdate, gradingduedate, completionsubmit, grade, submissiondrafts, requiresubmissionstatement, maxattempts, attemptreopenmethod, teamsubmission, requireallteammemberssubmit, teamsubmissiongroupingid, sendnotifications, sendlatenotifications, sendstudentnotifications, blindmarking, markingworkflow, markingallocation', IGNORE_MISSING);
            if ($assign) {
                $gradingmethod = \get_grading_manager(\context_module::instance($cmid), 'mod_assign', 'submissions')->get_active_method();
                $gradeitem = $DB->get_record('grade_items', [
                    'itemtype' => 'mod',
                    'itemmodule' => 'assign',
                    'iteminstance' => $assign->id,
                ], 'gradepass, categoryid', IGNORE_MISSING);
                $details['name'] = (string) $assign->name;
                $details['content'] = self::content_field((string) $assign->intro, $fullcontent);
                $pluginsettings = [];
                foreach ($DB->get_records('assign_plugin_config', ['assignment' => $assign->id]) as $config) {
                    $pluginsettings[assign_settings::plugin_config_field($config)] = (string) $config->value;
                }
                $files = [];
                $filecontext = context_module::instance($cmid);
                foreach (get_file_storage()->get_area_files($filecontext->id, 'mod_assign', 'introattachment', 0, 'filepath, filename', false) as $file) {
                    $files[] = ['filename' => $file->get_filename(), 'filepath' => $file->get_filepath(), 'filesize' => $file->get_filesize(), 'mimetype' => $file->get_mimetype()];
                }
                $details['settings'] = self::settings(array_merge([
                    'duedate' => (string) ((int) $assign->duedate),
                    'allowsubmissionsfromdate' => (string) ((int) $assign->allowsubmissionsfromdate),
                    'cutoffdate' => (string) ((int) $assign->cutoffdate),
                    'gradingduedate' => (string) ((int) $assign->gradingduedate),
                    'completionsubmit' => (string) ((int) $assign->completionsubmit),
                    'grade' => (string) ((int) $assign->grade),
                    'gradepass' => (string) ((float) ($gradeitem->gradepass ?? 0)),
                    'submissiondrafts' => (string) ((int) $assign->submissiondrafts),
                    'maxattempts' => (string) ((int) $assign->maxattempts),
                    'attemptreopenmethod' => (string) $assign->attemptreopenmethod,
                    'requiresubmissionstatement' => (string) ((int) $assign->requiresubmissionstatement),
                    'teamsubmission' => (string) ((int) $assign->teamsubmission),
                    'requireallteammemberssubmit' => (string) ((int) $assign->requireallteammemberssubmit),
                    'teamsubmissiongroupingid' => (string) ((int) $assign->teamsubmissiongroupingid),
                    'sendnotifications' => (string) ((int) $assign->sendnotifications),
                    'sendlatenotifications' => (string) ((int) $assign->sendlatenotifications),
                    'sendstudentnotifications' => (string) ((int) $assign->sendstudentnotifications),
                    'blindmarking' => (string) ((int) $assign->blindmarking),
                    'markingworkflow' => (string) ((int) $assign->markingworkflow),
                    'markingallocation' => (string) ((int) $assign->markingallocation),
                    'gradecat' => (string) ((int) ($gradeitem->categoryid ?? 0)),
                    'gradingmethod' => (string) ($gradingmethod ?: 'none'),
                    'additionalfiles' => json_encode($files),
                ], [
                    'onlinetext_enabled' => $pluginsettings['assignsubmission_onlinetext_enabled'] ?? '0',
                    'onlinetext_wordlimit_enabled' => $pluginsettings['assignsubmission_onlinetext_wordlimit_enabled'] ?? '0',
                    'onlinetext_wordlimit' => $pluginsettings['assignsubmission_onlinetext_wordlimit'] ?? '0',
                    'submission_file_enabled' => $pluginsettings['assignsubmission_file_enabled'] ?? '0',
                    'submission_file_maxfiles' => $pluginsettings['assignsubmission_file_maxfiles'] ?? '0',
                    'submission_file_maxsizebytes' => $pluginsettings['assignsubmission_file_maxsizebytes'] ?? '0',
                    'submission_file_filetypes' => $pluginsettings['assignsubmission_file_filetypes'] ?? '',
                    'feedback_comments_enabled' => $pluginsettings['assignfeedback_comments_enabled'] ?? '0',
                    'feedback_editpdf_enabled' => $pluginsettings['assignfeedback_editpdf_enabled'] ?? '0',
                    'feedback_file_enabled' => $pluginsettings['assignfeedback_file_enabled'] ?? '0',
                    'feedback_file_maxfiles' => $pluginsettings['assignfeedback_file_maxfiles'] ?? '0',
                    'feedback_file_maxsizebytes' => $pluginsettings['assignfeedback_file_maxsizebytes'] ?? '0',
                    'feedback_file_filetypes' => $pluginsettings['assignfeedback_file_filetypes'] ?? '',
                    'feedback_offline_enabled' => $pluginsettings['assignfeedback_offline_enabled'] ?? '0',
                ]));
            }
            return $details;
        }

        if ($modname === 'url') {
            $url = $DB->get_record('url', ['id' => $instanceid], 'name, intro, externalurl', IGNORE_MISSING);
            if ($url) {
                $details['name'] = (string) $url->name;
                $details['content'] = self::content_field((string) $url->intro, $fullcontent);
                $details['settings'] = self::settings([
                    'externalurl' => (string) $url->externalurl,
                ]);
            }
            return $details;
        }

        $quiz = $DB->get_record(
            'quiz',
            ['id' => $instanceid],
            'id, name, intro, preferredbehaviour, attempts, grademethod, timelimit, grade',
            IGNORE_MISSING
        );
        if ($quiz) {
            $gradeitem = $DB->get_record('grade_items', [
                'itemmodule' => 'quiz',
                'iteminstance' => $quiz->id,
            ], 'gradepass, grademax', IGNORE_MISSING);
            $details['name'] = (string) $quiz->name;
            $details['content'] = self::content_field((string) $quiz->intro, $fullcontent);
            $details['settings'] = self::settings([
                'preferredbehaviour' => (string) $quiz->preferredbehaviour,
                'attempts' => (string) ((int) $quiz->attempts),
                'grademethod' => (string) ((int) $quiz->grademethod),
                'timelimit' => (string) ((int) $quiz->timelimit),
                'grade' => (string) ((float) $quiz->grade),
                'gradepass' => $gradeitem ? (string) ((float) $gradeitem->gradepass) : '',
                'grademax' => $gradeitem ? (string) ((float) $gradeitem->grademax) : '',
            ]);
            $details['quizslots'] = self::quiz_slots((int) $quiz->id);
        }
        return $details;
    }

    private static function quiz_slots(int $quizid): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT qs.id AS slotid, qs.slot, qr.questionbankentryid, qbe.questioncategoryid
               FROM {quiz_slots} qs
          LEFT JOIN {question_references} qr
                 ON qr.itemid = qs.id
                AND qr.component = :component
                AND qr.questionarea = :area
          LEFT JOIN {question_bank_entries} qbe
                 ON qbe.id = qr.questionbankentryid
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot',
            ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]
        );

        $slots = [];
        foreach ($rows as $row) {
            $latest = null;
            if (!empty($row->questionbankentryid)) {
                $latest = $DB->get_record_sql(
                    'SELECT qv.questionid, qv.version, q.name, q.qtype
                       FROM {question_versions} qv
                       JOIN {question} q ON q.id = qv.questionid
                      WHERE qv.questionbankentryid = ?
                   ORDER BY qv.version DESC',
                    [$row->questionbankentryid],
                    IGNORE_MULTIPLE
                );
            }
            $slots[] = [
                'slot' => (int) $row->slot,
                'categoryid' => empty($row->questioncategoryid) ? 0 : (int) $row->questioncategoryid,
                'questionbankentryid' => empty($row->questionbankentryid) ? 0 : (int) $row->questionbankentryid,
                'questionid' => $latest ? (int) $latest->questionid : 0,
                'version' => $latest ? (int) $latest->version : 0,
                'questionname' => $latest ? (string) $latest->name : '',
                'qtype' => $latest ? (string) $latest->qtype : '',
            ];
        }
        return $slots;
    }

    private static function content_field(string $html, bool $fullcontent): array {
        return [
            'html' => $fullcontent ? $html : '',
            'preview' => self::preview($html, $fullcontent),
            'truncated' => !$fullcontent && trim($html) !== '',
        ];
    }

    private static function preview(string $html, bool $fullcontent): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        if ($fullcontent || strlen($text) <= 280) {
            return $text;
        }
        return substr($text, 0, 277) . '...';
    }

    private static function settings(array $settings): array {
        $pairs = [];
        foreach ($settings as $name => $value) {
            $pairs[] = [
                'name' => (string) $name,
                'value' => (string) $value,
            ];
        }
        return $pairs;
    }

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
                            'visible' => new external_value(PARAM_INT, 'Visible flag: 1 = sichtbar/stealth, 0 = verborgen'),
                            'visibleoncoursepage' => new external_value(PARAM_INT, 'Kursseiten-Sichtbarkeit: 1 = auf Kursseite angezeigt, 0 = Stealth (erreichbar aber nicht in Kursliste)'),
                            'availability_status' => new external_value(PARAM_TEXT, 'Abgeleiteter Status: shown (visible=1, visibleoncoursepage=1), stealth (visible=1, visibleoncoursepage=0), hidden (visible=0)'),
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
