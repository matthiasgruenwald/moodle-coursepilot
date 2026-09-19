<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/grade/grading/lib.php');

/** Builds the complete moduleinfo snapshot used for assignment changes. */
class assign_settings {
    public static function create_moduleinfo(array $params): \stdClass {
        global $DB;

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'assign';
        $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => 'assign'], MUST_EXIST);
        $moduleinfo->course = $params['courseid'];
        $moduleinfo->section = $params['sectionnum'];
        $moduleinfo->name = $params['name'];
        $moduleinfo->visible = $params['visible'];
        $moduleinfo->intro = $params['description'];
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->allowsubmissionsfromdate = $params['allowsubmissionsfromdate'];
        $moduleinfo->duedate = $params['duedate'];
        $moduleinfo->cutoffdate = $params['cutoffdate'];
        $moduleinfo->gradingduedate = $params['gradingduedate'];
        $moduleinfo->submissiondrafts = $params['mode'] === 'übung' ? 0 : 1;
        $moduleinfo->requiresubmissionstatement = $params['requiresubmissionstatement'];
        $moduleinfo->sendnotifications = $params['sendnotifications'];
        $moduleinfo->sendlatenotifications = $params['sendlatenotifications'];
        $moduleinfo->sendstudentnotifications = $params['sendstudentnotifications'];
        $moduleinfo->assignsubmission_onlinetext_enabled = $params['onlinetext_enabled'];
        $moduleinfo->assignsubmission_onlinetext_wordlimit_enabled = $params['onlinetext_wordlimit_enabled'];
        $moduleinfo->assignsubmission_onlinetext_wordlimit = $params['onlinetext_wordlimit'];
        $moduleinfo->assignsubmission_file_enabled = $params['submission_file_enabled'] < 0 ? ($params['maxfiles'] > 0 ? 1 : 0) : $params['submission_file_enabled'];
        $moduleinfo->assignsubmission_file_maxfiles = $params['submission_file_maxfiles'];
        $moduleinfo->assignsubmission_file_maxsizebytes = $params['submission_file_maxsizebytes'];
        $moduleinfo->assignsubmission_file_filetypes = $params['submission_file_filetypes'];
        $moduleinfo->assignfeedback_comments_enabled = $params['feedback_comments_enabled'];
        $moduleinfo->assignfeedback_editpdf_enabled = $params['feedback_editpdf_enabled'];
        $moduleinfo->assignfeedback_file_enabled = $params['feedback_file_enabled'];
        $moduleinfo->assignfeedback_file_maxfiles = $params['feedback_file_maxfiles'];
        $moduleinfo->assignfeedback_file_maxsizebytes = $params['feedback_file_maxsizebytes'];
        $moduleinfo->assignfeedback_file_filetypes = $params['feedback_file_filetypes'];
        $moduleinfo->assignfeedback_offline_enabled = $params['feedback_offline_enabled'];
        $moduleinfo->grade = $params['mode'] === 'übung' ? 0 : 100;
        $moduleinfo->gradepass = 0;
        $moduleinfo->gradecat = $params['gradecat'];
        $moduleinfo->teamsubmission = $params['teamsubmission'];
        $moduleinfo->requireallteammemberssubmit = $params['requireallteammemberssubmit'];
        $moduleinfo->teamsubmissiongroupingid = $params['teamsubmissiongroupingid'];
        $moduleinfo->blindmarking = $params['blindmarking'];
        $moduleinfo->attemptreopenmethod = 'manual';
        $moduleinfo->maxattempts = -1;
        $moduleinfo->markingworkflow = $params['markingworkflow'];
        $moduleinfo->markingallocation = $params['markingallocation'];
        $moduleinfo->advancedgradingmethod_submissions = $params['gradingmethod'] === 'none' ? '' : $params['gradingmethod'];
        $moduleinfo->cmidnumber = '';

        return self::patch($moduleinfo, $params);
    }

    /** Loads all core and subplugin settings, so a partial patch cannot erase them. */
    public static function snapshot(\stdClass $cm, \stdClass $course): \stdClass {
        global $DB;

        // Moodle prepares the CM fields and introeditor draft required by update_moduleinfo().
        [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $moduleinfo->id,
            'courseid' => $course->id,
        ], 'gradepass, categoryid', IGNORE_MISSING);
        $moduleinfo->gradepass = (float) ($gradeitem->gradepass ?? 0);
        $moduleinfo->gradecat = (int) ($gradeitem->categoryid ?? 0);
        foreach ($DB->get_records('assign_plugin_config', ['assignment' => $moduleinfo->id]) as $config) {
            $field = self::plugin_config_field($config);
            $moduleinfo->{$field} = $config->value;
        }
        $moduleinfo->advancedgradingmethod_submissions = \get_grading_manager(
            \context_module::instance($cm->id), 'mod_assign', 'submissions'
        )->get_active_method();
        return $moduleinfo;
    }

    /** Applies only explicitly supplied values; sentinels keep the snapshot value. */
    public static function patch(\stdClass $moduleinfo, array $params): \stdClass {
        if (($params['name'] ?? '') !== '') {
            $moduleinfo->name = $params['name'];
        }
        if (($params['description'] ?? '') !== '') {
            $moduleinfo->intro = $params['description'];
            $moduleinfo->introformat = FORMAT_HTML;
            // update_moduleinfo() reads the description from introeditor, not from intro/introformat.
            // Without this sync, the stale draft from snapshot() overwrites the new text.
            if (isset($moduleinfo->introeditor) && is_array($moduleinfo->introeditor)) {
                $moduleinfo->introeditor['text'] = $params['description'];
                $moduleinfo->introeditor['format'] = FORMAT_HTML;
            }
        }
        if (($params['duedate'] ?? -1) >= 0) {
            $moduleinfo->duedate = $params['duedate'];
        }
        if (($params['visible'] ?? -1) >= 0) {
            $moduleinfo->visible = $params['visible'];
        }
        if (($params['grade'] ?? -1) !== -1) {
            $moduleinfo->grade = $params['grade'];
        }
        if (($params['gradepass'] ?? -1) >= 0) {
            $moduleinfo->gradepass = $params['gradepass'];
        }
        if (($params['submissiondrafts'] ?? -1) >= 0) {
            $moduleinfo->submissiondrafts = $params['submissiondrafts'];
        }
        if (($params['maxattempts'] ?? -2) >= -1) {
            $moduleinfo->maxattempts = $params['maxattempts'];
        }
        if (($params['attemptreopenmethod'] ?? '') !== '') {
            $moduleinfo->attemptreopenmethod = $params['attemptreopenmethod'];
        }
        foreach (['allowsubmissionsfromdate', 'cutoffdate', 'gradingduedate', 'requiresubmissionstatement',
            'teamsubmission', 'requireallteammemberssubmit', 'teamsubmissiongroupingid', 'sendnotifications',
            'sendlatenotifications', 'sendstudentnotifications', 'blindmarking', 'markingworkflow',
            'markingallocation', 'gradecat'] as $field) {
            if (($params[$field] ?? -1) >= 0) {
                $moduleinfo->{$field} = $params[$field];
            }
        }
        if (($params['gradingmethod'] ?? '') !== '') {
            $moduleinfo->advancedgradingmethod_submissions = $params['gradingmethod'] === 'none' ? '' : $params['gradingmethod'];
        }
        foreach (self::plugin_fields() as $field) {
            $value = $params[$field] ?? (str_ends_with($field, '_filetypes') ? '' : -1);
            if ((is_string($value) && $value !== '') || (!is_string($value) && $value >= 0)) {
                $moduleinfo->{self::moduleinfo_field($field)} = $value;
            }
        }
        return $moduleinfo;
    }

    /** Moodle's bundled configurable submission and feedback plugin fields. */
    public static function plugin_fields(): array {
        return [
            'onlinetext_enabled', 'onlinetext_wordlimit_enabled', 'onlinetext_wordlimit',
            'submission_file_enabled', 'submission_file_maxfiles', 'submission_file_maxsizebytes', 'submission_file_filetypes',
            'feedback_comments_enabled', 'feedback_editpdf_enabled',
            'feedback_file_enabled', 'feedback_file_maxfiles', 'feedback_file_maxsizebytes', 'feedback_file_filetypes',
            'feedback_offline_enabled',
        ];
    }

    private static function moduleinfo_field(string $field): string {
        if (str_starts_with($field, 'onlinetext_')) {
            return 'assignsubmission_' . $field;
        }
        return 'assign' . $field;
    }

    /** Maps Moodle's persisted plugin-config names to their module-form fields. */
    public static function plugin_config_field(\stdClass $config): string {
        $field = $config->subtype . '_' . $config->plugin . '_' . $config->name;
        return [
            'assignsubmission_onlinetext_wordlimitenabled' => 'assignsubmission_onlinetext_wordlimit_enabled',
            'assignsubmission_file_maxfilesubmissions' => 'assignsubmission_file_maxfiles',
            'assignsubmission_file_maxsubmissionsizebytes' => 'assignsubmission_file_maxsizebytes',
            'assignsubmission_file_filetypeslist' => 'assignsubmission_file_filetypes',
        ][$field] ?? $field;
    }

    private static function plugin_config_name(string $field): string {
        return [
            'onlinetext_wordlimit_enabled' => 'wordlimitenabled',
            'submission_file_maxfiles' => 'maxfilesubmissions',
            'submission_file_maxsizebytes' => 'maxsubmissionsizebytes',
            'submission_file_filetypes' => 'filetypeslist',
        ][$field] ?? preg_replace('/^(onlinetext_|submission_file_|feedback_(comments_|editpdf_|file_|offline_))/', '', $field);
    }

    /** Validates core assignment dependencies and referenced Moodle objects. */
    public static function validate_core_settings(\stdClass $moduleinfo, array $params, int $courseid): void {
        global $DB;

        $from = (int) $moduleinfo->allowsubmissionsfromdate;
        $due = (int) $moduleinfo->duedate;
        $cutoff = (int) $moduleinfo->cutoffdate;
        if ($from && $due && $from > $due || $due && $cutoff && $due > $cutoff) {
            throw new \invalid_parameter_exception('Ungültige Terminfolge: Abgabebeginn, Abgabetermin und letzter Abgabetermin müssen zeitlich aufeinander folgen.');
        }
        foreach (['requiresubmissionstatement', 'teamsubmission', 'requireallteammemberssubmit', 'sendnotifications',
            'sendlatenotifications', 'sendstudentnotifications', 'blindmarking', 'markingworkflow', 'markingallocation'] as $field) {
            if (!in_array((int) $moduleinfo->{$field}, [0, 1], true)) {
                throw new \invalid_parameter_exception('Die Einstellung ' . $field . ' muss 0 oder 1 sein.');
            }
        }
        if (!empty($moduleinfo->requireallteammemberssubmit) && empty($moduleinfo->teamsubmission)) {
            throw new \invalid_parameter_exception('Alle Gruppenmitglieder können nur bei aktivierter Gruppenabgabe zur Abgabe verpflichtet werden.');
        }
        if ((int) $moduleinfo->teamsubmissiongroupingid > 0) {
            if (empty($moduleinfo->teamsubmission) || !$DB->record_exists('groupings', ['id' => $moduleinfo->teamsubmissiongroupingid, 'courseid' => $courseid])) {
                throw new \invalid_parameter_exception('Gruppenabgabe braucht eine vorhandene Kurs-Gruppierung.');
            }
        }
        if (!empty($moduleinfo->markingallocation) && empty($moduleinfo->markingworkflow)) {
            throw new \invalid_parameter_exception('Bewertungszuordnung setzt den Bewertungsworkflow voraus.');
        }
        if (($params['gradecat'] ?? -1) > 0 && !$DB->record_exists('grade_categories', ['id' => $moduleinfo->gradecat, 'courseid' => $courseid])) {
            throw new \invalid_parameter_exception('Die Bewertungskategorie gehört nicht zu diesem Kurs.');
        }
        if (($params['grade'] ?? -1) < -1 && !$DB->record_exists_select('scale', 'id = ? AND (courseid = 0 OR courseid = ?)', [-$moduleinfo->grade, $courseid])) {
            throw new \invalid_parameter_exception('Die ausgewählte Skala ist nicht im Kurs oder systemweit vorhanden.');
        }
        $method = (string) ($moduleinfo->advancedgradingmethod_submissions ?? '');
        if ($method !== '' && !array_key_exists($method, \grading_manager::available_methods())) {
            throw new \invalid_parameter_exception('Die Bewertungsmethode ist in Moodle nicht verfügbar.');
        }
    }

    /** Refuses settings Moodle freezes once learner work exists. */
    public static function validate_frozen_core_changes(\stdClass $moduleinfo, array $params): void {
        global $DB;

        $frozen = ['teamsubmission', 'requireallteammemberssubmit', 'teamsubmissiongroupingid', 'blindmarking'];
        $changesfrozen = false;
        foreach ($frozen as $field) {
            if (($params[$field] ?? -1) >= 0 && (int) $params[$field] !== (int) $moduleinfo->{$field}) {
                $changesfrozen = true;
            }
        }
        if (($params['gradingmethod'] ?? '') !== ''
            && ($params['gradingmethod'] === 'none' ? '' : $params['gradingmethod']) !== ($moduleinfo->advancedgradingmethod_submissions ?? '')) {
            $changesfrozen = true;
        }
        if (empty($moduleinfo->id) || !$changesfrozen) {
            return;
        }
        if ($DB->record_exists('assign_submission', ['assignment' => $moduleinfo->id]) || $DB->record_exists('assign_grades', ['assignment' => $moduleinfo->id])) {
            throw new \invalid_parameter_exception('Diese Einstellung ist eingefroren, weil bereits Abgaben oder Bewertungen vorhanden sind.');
        }
    }

    /** Validates Moodle's dependent submission and attempt settings. */
    public static function validate_attempt_settings(\stdClass $moduleinfo, array $params): void {
        $submissiondrafts = ($params['submissiondrafts'] ?? -1) >= 0
            ? $params['submissiondrafts']
            : (int) $moduleinfo->submissiondrafts;
        $maxattempts = ($params['maxattempts'] ?? -2) >= -1
            ? $params['maxattempts']
            : (int) $moduleinfo->maxattempts;
        $attemptreopenmethod = ($params['attemptreopenmethod'] ?? '') !== ''
            ? $params['attemptreopenmethod']
            : ($moduleinfo->attemptreopenmethod ?? '');

        if (!in_array($submissiondrafts, [0, 1], true)) {
            throw new \invalid_parameter_exception('Die endgültige Abgabe muss 0 oder 1 sein.');
        }
        if (($params['maxattempts'] ?? -2) !== -2 && ($maxattempts < -1 || $maxattempts === 0 || $maxattempts > 30)) {
            throw new \invalid_parameter_exception('Die maximale Versuchszahl muss -1 (unbegrenzt) oder 1 bis 30 sein.');
        }
        if (($params['attemptreopenmethod'] ?? '') !== '' && !in_array($params['attemptreopenmethod'], ['manual', 'automatic', 'untilpass'], true)) {
            throw new \invalid_parameter_exception('Die Wiedereröffnung muss manual, automatic oder untilpass sein.');
        }
        if (($params['gradepass'] ?? -1) >= 0
            && ((int) $moduleinfo->grade <= 0 || (float) $params['gradepass'] > (float) $moduleinfo->grade)) {
            throw new \invalid_parameter_exception('Die Bestehensgrenze braucht eine Bewertung und darf diese nicht überschreiten.');
        }

        $changesattemptflow = ($params['maxattempts'] ?? -2) >= -1
            || ($params['attemptreopenmethod'] ?? '') !== '';
        if ($changesattemptflow && $submissiondrafts !== 1) {
            throw new \invalid_parameter_exception('Eine Wiedereröffnung setzt die endgültige Abgabe voraus.');
        }
        if ($changesattemptflow && $maxattempts !== -1 && $maxattempts < 2) {
            throw new \invalid_parameter_exception('Eine Wiedereröffnung braucht mindestens zwei Versuche oder unbegrenzte Versuche.');
        }
        $checksuntilpass = $attemptreopenmethod === 'untilpass' && ($changesattemptflow
            || ($params['grade'] ?? -1) >= 0 || ($params['gradepass'] ?? -1) >= 0);
        if ($checksuntilpass
            && ((int) $moduleinfo->grade <= 0 || (float) ($moduleinfo->gradepass ?? 0) <= 0)) {
            throw new \invalid_parameter_exception('untilpass braucht eine Bewertung mit Bestehensgrenze.');
        }
        if ($checksuntilpass && !empty($moduleinfo->blindmarking)) {
            throw new \invalid_parameter_exception('untilpass ist mit anonymer Bewertung nicht zulässig.');
        }
    }

    /** Moodle freezes final-submission mode once learner data exists. */
    public static function validate_submissiondrafts_change(\stdClass $moduleinfo, array $params): void {
        global $DB;

        if (($params['submissiondrafts'] ?? -1) < 0
            || (int) $params['submissiondrafts'] === (int) $moduleinfo->submissiondrafts
            || empty($moduleinfo->id)) {
            return;
        }
        if ($DB->record_exists('assign_submission', ['assignment' => $moduleinfo->id])
            || $DB->record_exists('assign_grades', ['assignment' => $moduleinfo->id])) {
            throw new \invalid_parameter_exception('Die endgültige Abgabe ist eingefroren, weil bereits Abgaben oder Bewertungen vorhanden sind.');
        }
    }

    public static function result(int $cmid): array {
        global $DB;

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $assign = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $gradeitem = $DB->get_record('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $assign->id,
            'courseid' => $cm->course,
        ], 'gradepass, categoryid', IGNORE_MISSING);
        $gradingmethod = \get_grading_manager(\context_module::instance($cmid), 'mod_assign', 'submissions')->get_active_method();
        $plugins = [];
        foreach (self::plugin_fields() as $field) {
            $value = (string) ($DB->get_field('assign_plugin_config', 'value', [
                'assignment' => $assign->id,
                'subtype' => str_starts_with($field, 'feedback_') ? 'assignfeedback' : 'assignsubmission',
                'plugin' => str_starts_with($field, 'feedback_') ? substr($field, 9, strpos($field, '_', 9) - 9) : (str_starts_with($field, 'submission_file') ? 'file' : 'onlinetext'),
                'name' => self::plugin_config_name($field),
            ]) ?: '');
            $plugins[$field] = str_ends_with($field, '_filetypes') ? $value : (int) $value;
        }
        return array_merge([
            'name' => (string) $assign->name,
            'grade' => (int) $assign->grade,
            'gradepass' => (float) ($gradeitem->gradepass ?? 0),
            'submissiondrafts' => (int) $assign->submissiondrafts,
            'maxattempts' => (int) $assign->maxattempts,
            'attemptreopenmethod' => (string) $assign->attemptreopenmethod,
            'visible' => (int) $cm->visible,
            'allowsubmissionsfromdate' => (int) $assign->allowsubmissionsfromdate,
            'cutoffdate' => (int) $assign->cutoffdate,
            'gradingduedate' => (int) $assign->gradingduedate,
            'requiresubmissionstatement' => (int) $assign->requiresubmissionstatement,
            'teamsubmission' => (int) $assign->teamsubmission,
            'requireallteammemberssubmit' => (int) $assign->requireallteammemberssubmit,
            'teamsubmissiongroupingid' => (int) $assign->teamsubmissiongroupingid,
            'sendnotifications' => (int) $assign->sendnotifications,
            'sendlatenotifications' => (int) $assign->sendlatenotifications,
            'sendstudentnotifications' => (int) $assign->sendstudentnotifications,
            'blindmarking' => (int) $assign->blindmarking,
            'markingworkflow' => (int) $assign->markingworkflow,
            'markingallocation' => (int) $assign->markingallocation,
            'gradecat' => (int) ($gradeitem->categoryid ?? 0),
            'gradingmethod' => (string) ($gradingmethod ?: 'none'),
        ], $plugins);
    }
}
