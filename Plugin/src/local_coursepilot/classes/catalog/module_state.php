<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_coursepilot\catalog;

use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/grade/grading/lib.php');

/**
 * Gemeinsame Implementierung des Katalog-Lesevertrags.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class module_state {

    /**
     * @param string $modname
     * @param int $instanceid
     * @param int $cmid
     * @param bool $fullcontent
     * @return array{name: string, content: array, settings: array, quizslots: array}
     */
    public static function for_modname(string $modname, int $instanceid, int $cmid, bool $fullcontent): array {
        if ($modname === 'quiz') {
            return self::quiz($instanceid, $cmid, $fullcontent);
        }

        global $DB;

        $details = self::empty($fullcontent);
        if (!in_array($modname, ['page', 'label', 'assign', 'url'], true)) {
            $record = $DB->get_record($modname, ['id' => $instanceid], 'name', IGNORE_MISSING);
            $details['name'] = $record ? (string) $record->name : '';
            return $details;
        }

        if ($modname === 'page') {
            $page = $DB->get_record('page', ['id' => $instanceid], 'name, intro, content', IGNORE_MISSING);
            if ($page) {
                $details['name'] = (string) $page->name;
                $details['content'] = self::content_field((string) $page->content, $fullcontent);
                $details['settings'] = self::settings(['intro' => self::preview((string) $page->intro, $fullcontent)]);
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

        if ($modname === 'url') {
            $url = $DB->get_record('url', ['id' => $instanceid], 'name, intro, externalurl', IGNORE_MISSING);
            if ($url) {
                $details['name'] = (string) $url->name;
                $details['content'] = self::content_field((string) $url->intro, $fullcontent);
                $details['settings'] = self::settings(['externalurl' => (string) $url->externalurl]);
            }
            return $details;
        }

        $assign = $DB->get_record('assign', ['id' => $instanceid], 'id, name, intro, allowsubmissionsfromdate, duedate, cutoffdate, gradingduedate, completionsubmit, grade, submissiondrafts, requiresubmissionstatement, maxattempts, attemptreopenmethod, teamsubmission, requireallteammemberssubmit, teamsubmissiongroupingid, sendnotifications, sendlatenotifications, sendstudentnotifications, blindmarking, markingworkflow, markingallocation', IGNORE_MISSING);
        if (!$assign) {
            return $details;
        }

        $gradingmethod = \get_grading_manager(context_module::instance($cmid), 'mod_assign', 'submissions')->get_active_method();
        $gradeitem = $DB->get_record('grade_items', ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id], 'gradepass, categoryid', IGNORE_MISSING);
        $details['name'] = (string) $assign->name;
        $details['content'] = self::content_field((string) $assign->intro, $fullcontent);
        $pluginsettings = [];
        foreach ($DB->get_records('assign_plugin_config', ['assignment' => $assign->id]) as $config) {
            $pluginsettings[self::plugin_config_field($config)] = (string) $config->value;
        }
        $files = [];
        foreach (get_file_storage()->get_area_files(context_module::instance($cmid)->id, 'mod_assign', 'introattachment', 0, 'filepath, filename', false) as $file) {
            $files[] = ['filename' => $file->get_filename(), 'filepath' => $file->get_filepath(), 'filesize' => $file->get_filesize(), 'mimetype' => $file->get_mimetype()];
        }
        $details['settings'] = self::settings(array_merge([
            'duedate' => (string) ((int) $assign->duedate), 'allowsubmissionsfromdate' => (string) ((int) $assign->allowsubmissionsfromdate),
            'cutoffdate' => (string) ((int) $assign->cutoffdate), 'gradingduedate' => (string) ((int) $assign->gradingduedate),
            'completionsubmit' => (string) ((int) $assign->completionsubmit), 'grade' => (string) ((int) $assign->grade),
            'gradepass' => (string) ((float) ($gradeitem->gradepass ?? 0)), 'submissiondrafts' => (string) ((int) $assign->submissiondrafts),
            'maxattempts' => (string) ((int) $assign->maxattempts), 'attemptreopenmethod' => (string) $assign->attemptreopenmethod,
            'requiresubmissionstatement' => (string) ((int) $assign->requiresubmissionstatement), 'teamsubmission' => (string) ((int) $assign->teamsubmission),
            'requireallteammemberssubmit' => (string) ((int) $assign->requireallteammemberssubmit), 'teamsubmissiongroupingid' => (string) ((int) $assign->teamsubmissiongroupingid),
            'sendnotifications' => (string) ((int) $assign->sendnotifications), 'sendlatenotifications' => (string) ((int) $assign->sendlatenotifications),
            'sendstudentnotifications' => (string) ((int) $assign->sendstudentnotifications), 'blindmarking' => (string) ((int) $assign->blindmarking),
            'markingworkflow' => (string) ((int) $assign->markingworkflow), 'markingallocation' => (string) ((int) $assign->markingallocation),
            'gradecat' => (string) ((int) ($gradeitem->categoryid ?? 0)), 'gradingmethod' => (string) ($gradingmethod ?: 'none'), 'additionalfiles' => json_encode($files),
        ], [
            'onlinetext_enabled' => $pluginsettings['assignsubmission_onlinetext_enabled'] ?? '0', 'onlinetext_wordlimit_enabled' => $pluginsettings['assignsubmission_onlinetext_wordlimit_enabled'] ?? '0',
            'onlinetext_wordlimit' => $pluginsettings['assignsubmission_onlinetext_wordlimit'] ?? '0', 'submission_file_enabled' => $pluginsettings['assignsubmission_file_enabled'] ?? '0',
            'submission_file_maxfiles' => $pluginsettings['assignsubmission_file_maxfiles'] ?? '0', 'submission_file_maxsizebytes' => $pluginsettings['assignsubmission_file_maxsizebytes'] ?? '0',
            'submission_file_filetypes' => $pluginsettings['assignsubmission_file_filetypes'] ?? '', 'feedback_comments_enabled' => $pluginsettings['assignfeedback_comments_enabled'] ?? '0',
            'feedback_editpdf_enabled' => $pluginsettings['assignfeedback_editpdf_enabled'] ?? '0', 'feedback_file_enabled' => $pluginsettings['assignfeedback_file_enabled'] ?? '0',
            'feedback_file_maxfiles' => $pluginsettings['assignfeedback_file_maxfiles'] ?? '0', 'feedback_file_maxsizebytes' => $pluginsettings['assignfeedback_file_maxsizebytes'] ?? '0',
            'feedback_file_filetypes' => $pluginsettings['assignfeedback_file_filetypes'] ?? '', 'feedback_offline_enabled' => $pluginsettings['assignfeedback_offline_enabled'] ?? '0',
        ]));
        return $details;
    }

    /**
     * Nicht katalogisierte Moodle-Module bleiben lesbar, sind aber nicht
     * schreibbar ueber Coursepilot.
     */
    public static function unknown(string $modname, int $instanceid, bool $fullcontent): array {
        global $DB;

        $details = self::empty($fullcontent);
        $record = $DB->get_record($modname, ['id' => $instanceid], 'name', IGNORE_MISSING);
        $details['name'] = $record ? (string) $record->name : '';
        return $details;
    }

    /**
     * ADR 0016: quiz has a separate state because grades and slots are not
     * generic form fields.
     */
    public static function quiz(int $instanceid, int $cmid, bool $fullcontent): array {
        global $DB;

        $details = self::empty($fullcontent);
        $quiz = $DB->get_record('quiz', ['id' => $instanceid], 'id, name, intro, preferredbehaviour, attempts, grademethod, timelimit, grade', IGNORE_MISSING);
        if (!$quiz) {
            return $details;
        }
        $gradeitem = $DB->get_record('grade_items', ['itemmodule' => 'quiz', 'iteminstance' => $quiz->id], 'gradepass, grademax', IGNORE_MISSING);
        $details['name'] = (string) $quiz->name;
        $details['content'] = self::content_field((string) $quiz->intro, $fullcontent);
        $details['settings'] = self::settings([
            'preferredbehaviour' => (string) $quiz->preferredbehaviour, 'attempts' => (string) ((int) $quiz->attempts),
            'grademethod' => (string) ((int) $quiz->grademethod), 'timelimit' => (string) ((int) $quiz->timelimit),
            'grade' => (string) ((float) $quiz->grade), 'gradepass' => $gradeitem ? (string) ((float) $gradeitem->gradepass) : '',
            'grademax' => $gradeitem ? (string) ((float) $gradeitem->grademax) : '',
        ]);
        $details['quizslots'] = self::quiz_slots((int) $quiz->id);
        return $details;
    }

    private static function empty(bool $fullcontent): array {
        return ['name' => '', 'content' => self::content_field('', $fullcontent), 'settings' => [], 'quizslots' => []];
    }

    private static function quiz_slots(int $quizid): array {
        global $DB;
        $rows = $DB->get_records_sql('SELECT qs.id AS slotid, qs.slot, qr.questionbankentryid, qbe.questioncategoryid FROM {quiz_slots} qs LEFT JOIN {question_references} qr ON qr.itemid = qs.id AND qr.component = :component AND qr.questionarea = :area LEFT JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid WHERE qs.quizid = :quizid ORDER BY qs.slot', ['component' => 'mod_quiz', 'area' => 'slot', 'quizid' => $quizid]);
        $slots = [];
        foreach ($rows as $row) {
            $latest = empty($row->questionbankentryid) ? null : $DB->get_record_sql('SELECT qv.questionid, qv.version, q.name, q.qtype FROM {question_versions} qv JOIN {question} q ON q.id = qv.questionid WHERE qv.questionbankentryid = ? ORDER BY qv.version DESC', [$row->questionbankentryid], IGNORE_MULTIPLE);
            $slots[] = ['slot' => (int) $row->slot, 'categoryid' => empty($row->questioncategoryid) ? 0 : (int) $row->questioncategoryid, 'questionbankentryid' => empty($row->questionbankentryid) ? 0 : (int) $row->questionbankentryid, 'questionid' => $latest ? (int) $latest->questionid : 0, 'version' => $latest ? (int) $latest->version : 0, 'questionname' => $latest ? (string) $latest->name : '', 'qtype' => $latest ? (string) $latest->qtype : ''];
        }
        return $slots;
    }

    private static function plugin_config_field(\stdClass $config): string {
        $field = $config->subtype . '_' . $config->plugin . '_' . $config->name;
        return ['assignsubmission_onlinetext_wordlimitenabled' => 'assignsubmission_onlinetext_wordlimit_enabled', 'assignsubmission_file_maxfilesubmissions' => 'assignsubmission_file_maxfiles', 'assignsubmission_file_maxsubmissionsizebytes' => 'assignsubmission_file_maxsizebytes', 'assignsubmission_file_filetypeslist' => 'assignsubmission_file_filetypes'][$field] ?? $field;
    }

    private static function content_field(string $html, bool $fullcontent): array {
        return ['html' => $fullcontent ? $html : '', 'preview' => self::preview($html, $fullcontent), 'truncated' => !$fullcontent && trim($html) !== ''];
    }

    private static function preview(string $html, bool $fullcontent): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        return $fullcontent || strlen($text) <= 280 ? $text : substr($text, 0, 277) . '...';
    }

    private static function settings(array $settings): array {
        $pairs = [];
        foreach ($settings as $name => $value) {
            $pairs[] = ['name' => (string) $name, 'value' => (string) $value];
        }
        return $pairs;
    }
}
