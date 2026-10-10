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

namespace local_coursepilot\catalog;

use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/grade/grading/lib.php');

/**
 * Field catalog for mod_assign (Spec 0015 §2.2/§4.1, ticket #382). The
 * stress test of the two-tier design: ~35 instance columns, 13 plugin pseudo-fields,
 * plus the shared block (§2.3) and the 34 constants without a callable
 * value set (mod/assign/locallib.php, lines 30-92 - every "define()" there
 * except ASSIGN_MARKER_FILTER_NO_MARKER, which is a filter-UI marker for the
 * grading table, not a field value of an instance).
 *
 * Pitfalls from the existing code:
 * - **Pseudo-fields instead of enable columns:** mod_assign has no own assign
 *   table columns for the submission/feedback plugins - every plugin keeps
 *   its state in {assign_plugin_config}. The form path expects one field
 *   "{subtype}_{type}_enabled" per plugin
 *   (assign_update_plugin_instance(), mod/assign/locallib.php:1359-1373):
 *   `if (!empty($formdata->$enabledname)) { enable() } else { disable() }`.
 *   A MISSING field disables the plugin just like an explicit 0 - the
 *   most dangerous case of this catalog, because it arises by omission.
 *   If ALL submission plugins end up disabled, Moodle caches
 *   is_any_submission_plugin_enabled() as "nosubmissions=1"
 *   (add_instance()/update_instance():843/1629) - the assignment then accepts
 *   no submissions at all.
 * - **"nosubmissions" is a pure cache output**, not an input field: Moodle
 *   computes it itself from the enable pseudo-fields (see above) right after
 *   every add_instance()/update_instance() - a patch on it would be overwritten
 *   on the next save. Blocklist.
 * - **"revealidentities"** is a real column, but reachable through NO
 *   form path: neither add_instance() nor update_instance()
 *   takes it from $formdata - it is set exclusively via the
 *   "Reveal identities" action (mod/assign/locallib.php, method
 *   reveal_identities()). Unwritable via the vehicle, hence blocklist
 *   instead of a silent no-op.
 * - **"completionsubmit"** is a completion field AND a real column
 *   at once: update_instance() only writes it if "completionunlocked"
 *   is set (mod/assign/locallib.php:1569-1571) - otherwise silently discarded,
 *   and without "completionunlocked" a write deletes the learners'
 *   completion data according to Spec 0015
 *   §8. Therefore, like the generic completion fields (course_modules, see
 *   {@see shared_block::BLOCKLIST}), it is on the blocklist - it is written
 *   via `set_completion` in the two-beat flow (ticket #461; unlocked there as a
 *   module-specific completion field for "assign" and "choice").
 * - **"teamsubmissiongroupingid"** lists only groupings of the
 *   own course in the form (mod/assign/mod_form.php:195:
 *   `groups_get_all_groupings($assignment->get_course()->id)`), but neither
 *   add_instance() nor update_instance() re-checks this on write -
 *   a grouping ID from a foreign course would be accepted unchallenged.
 *   Therefore noted in the field itself, like choice::optionid.
 * - **"activity"/"activityformat"** come in the form as one editor field
 *   "activityeditor" (text+format+draftitem), but are kept here like
 *   "intro"/"introformat" as two flat fields - the same
 *   simplification as in all other catalogs of this spec (§3.2: the
 *   flat get_moduleinfo_data() field object, no editor-array contract).
 * - **"introattachments"** ("Additional files") is cataloged as of Spec 0018/#429
 *   and - unlike resource/folder - NOT blocked: the
 *   block from Spec 0015 §4.3 applied to the case "Coursepilot has no storage
 *   location for binary files yet", which is gone with the material folder (Spec 0018 §2).
 *   Resolving the material-folder paths into a
 *   file-manager draft is done by update_module_settings before the
 *   update_moduleinfo() call.
 * - **"introimages"** (issue #433) does NOT attach material files, but puts them
 *   into the draft file area of the intro itself (component=mod_assign,
 *   filearea=intro) - update_moduleinfo() reads "intro" exclusively from
 *   $moduleinfo->introeditor['text'] (course/modlib.php:675-680), a plain
 *   ->intro patch would otherwise fizzle silently (see update_module_settings).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class assign implements module_catalog {
    /**
     * Provides modname.
     *
     * @return string
     */
    public static function modname(): string {
        return 'assign';
    }

    /**
     * The commonly set fields for the short form of describe_module_fields
     * (Spec 0015 §3.1, ticket #382: "the twelve fields a teacher
     * names each time, not markinganonymous"). Not an interface contract - an optional
     * hook that describe_module_fields queries via is_callable(); catalog classes
     * without this method return all their fields unchanged in the short form
     * (with six to twelve fields like label/choice/forum, "all" is already
     * the short form).
     *
     * @return string[]
     */
    public static function common_field_names(): array {
        return [
            'name',
            'intro',
            'duedate',
            'cutoffdate',
            'allowsubmissionsfromdate',
            'grade',
            'teamsubmission',
            'submissiondrafts',
            'maxattempts',
            'attemptreopenmethod',
            'blindmarking',
            'sendnotifications',
        ];
    }

    /**
     * Provides fields.
     *
     * @return array
     */
    public static function fields(): array {
        return [
            new field(
                'name',
                'PARAM_TEXT',
                'Display name of the assignment.',
                true,
                null,
                null,
                null,
                'mod/assign/mod_form.php:50-57 (PARAM_TEXT or PARAM_CLEANHTML depending on $CFG->formatstringstriptags)'
            ),
            new field(
                'intro',
                'PARAM_RAW',
                'Description text (intro) of the assignment.',
                true,
                null,
                null,
                null,
                'mod/assign/db/install.xml (assign.intro, NOTNULL without DB default)'
            ),
            new field(
                'introformat',
                'PARAM_INT',
                'Text format of the intro.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/assign/db/install.xml (assign.introformat)'
            ),
            new field(
                'activity',
                'PARAM_RAW',
                'Additional activity text (separate editor block below the intro), e.g. for '
                    . 'work assignments. Empty = no additional text.',
                false,
                null,
                null,
                null,
                'mod/assign/mod_form.php:62-66 (Editor "activityeditor"); column '
                    . 'mod/assign/db/install.xml (assign.activity, NOTNULL=false)'
            ),
            new field(
                'activityformat',
                'PARAM_INT',
                'Text format of the activity text.',
                false,
                FORMAT_HTML,
                null,
                'format_text_menu()',
                'lib/weblib.php:464 (format_text_menu()); column mod/assign/db/install.xml (assign.activityformat)'
            ),
            new field(
                'alwaysshowdescription',
                'PARAM_BOOL',
                'Show the intro before "allowsubmissionsfromdate" instead of only after it.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:123-126 (checkbox); column '
                    . 'mod/assign/db/install.xml (assign.alwaysshowdescription)'
            ),
            new field(
                'submissiondrafts',
                'PARAM_BOOL',
                'Submissions count as drafts until the learner explicitly submits.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:132-137 (selectyesno); column '
                    . 'mod/assign/db/install.xml (assign.submissiondrafts)'
            ),
            new field(
                'requiresubmissionstatement',
                'PARAM_BOOL',
                'Learners must accept an authorship statement before submitting.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:139-144 (selectyesno); column '
                    . 'mod/assign/db/install.xml (assign.requiresubmissionstatement)'
            ),
            new field(
                'maxattempts',
                'PARAM_INT',
                'Maximum number of submission attempts. -1 = unlimited.',
                false,
                1,
                null,
                null,
                'mod/assign/mod_form.php:146-149 (Select 1-30 plus ASSIGN_UNLIMITED_ATTEMPTS); '
                    . 'mod/assign/locallib.php:61 (ASSIGN_UNLIMITED_ATTEMPTS = -1); column '
                    . 'mod/assign/db/install.xml (assign.maxattempts, DEFAULT=1)'
            ),
            new field(
                'attemptreopenmethod',
                'PARAM_ALPHA',
                'How a new attempt is opened after the first one: manually, automatically (after each '
                    . 'grading) or until pass. Only visible if "maxattempts" != 1.',
                false,
                'untilpass',
                ['manual', 'automatic', 'untilpass'],
                null,
                'mod/assign/locallib.php:55-58 (ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL/AUTOMATIC/UNTILPASS); '
                    . 'mod/assign/mod_form.php:151-170 (choicedropdown); column '
                    . 'mod/assign/db/install.xml (assign.attemptreopenmethod, DEFAULT=untilpass)'
            ),
            new field(
                'duedate',
                'PARAM_INT',
                'Unix timestamp: due date, informational only (not a lock time, that is "cutoffdate"). '
                    . 'Creates a calendar event (see side effects).',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:102-105 (date_time_selector, optional); column '
                    . 'mod/assign/db/install.xml (assign.duedate)'
            ),
            new field(
                'cutoffdate',
                'PARAM_INT',
                'Unix timestamp: from here on Moodle accepts no more submissions, not even late ones. '
                    . '0 = no cut-off.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:107-109 (date_time_selector, optional); column '
                    . 'mod/assign/db/install.xml (assign.cutoffdate)'
            ),
            new field(
                'allowsubmissionsfromdate',
                'PARAM_INT',
                'Unix timestamp: submissions are only accepted from here on. 0 = immediately.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:81-84 (date_time_selector, optional); column '
                    . 'mod/assign/db/install.xml (assign.allowsubmissionsfromdate)'
            ),
            new field(
                'gradingduedate',
                'PARAM_INT',
                'Unix timestamp: expected grading date, informational only. Creates a calendar event '
                    . '(see side effects).',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:111-113 (date_time_selector, optional); column '
                    . 'mod/assign/db/install.xml (assign.gradingduedate)'
            ),
            new field(
                'timelimit',
                'PARAM_INT',
                'Working time in seconds from the start of an attempt, provided the admin has enabled time limits '
                    . '($CFG->assign->enabletimelimit). 0 = no time limit.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:115-121 (duration, optional, only with the admin setting enabled); '
                    . 'column mod/assign/db/install.xml (assign.timelimit)'
            ),
            new field(
                'grade',
                'PARAM_INT',
                'Grade type: positive = maximum points, negative = ID of a custom scale, '
                    . '0 = no grading.',
                false,
                0,
                null,
                null,
                'course/moodleform_mod.php (standard_grading_coursemodule_elements(), modgrade element); '
                    . 'mod/assign/mod_form.php:225 (call); column mod/assign/db/install.xml (assign.grade)'
            ),
            new field(
                'gradepenalty',
                'PARAM_BOOL',
                'Enable late penalties. Only visible if the penalty feature is active server-wide '
                    . '(core_grades\\penalty_manager::is_penalty_enabled_for_module()).',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:253-272 (selectyesno, conditionally visible); column '
                    . 'mod/assign/db/install.xml (assign.gradepenalty)'
            ),
            new field(
                'sendnotifications',
                'PARAM_BOOL',
                'Notify teachers by email about new submissions. Side effect: see side effects.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:212-214 (selectyesno); column '
                    . 'mod/assign/db/install.xml (assign.sendnotifications)'
            ),
            new field(
                'sendlatenotifications',
                'PARAM_BOOL',
                'Also notify about late submissions. Only effective with "sendnotifications"=0 - with '
                    . '1 all notifications are on anyway.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:216-219 (selectyesno, disabledIf sendnotifications eq 1); column '
                    . 'mod/assign/db/install.xml (assign.sendlatenotifications)'
            ),
            new field(
                'sendstudentnotifications',
                'PARAM_BOOL',
                'Default for the "Notify learners" checkbox when grading.',
                false,
                1,
                [0, 1],
                null,
                'mod/assign/mod_form.php:221-223 (selectyesno); column '
                    . 'mod/assign/db/install.xml (assign.sendstudentnotifications, DEFAULT=1)'
            ),
            new field(
                'teamsubmission',
                'PARAM_BOOL',
                'Learners submit in groups instead of individually.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:174-179 (selectyesno); column '
                    . 'mod/assign/db/install.xml (assign.teamsubmission)'
            ),
            new field(
                'requireallteammemberssubmit',
                'PARAM_BOOL',
                'A group submission only counts as submitted once all members have agreed. Only '
                    . 'visible with "teamsubmission"=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:189-193 (selectyesno, hideIf teamsubmission eq 0); column '
                    . 'mod/assign/db/install.xml (assign.requireallteammemberssubmit)'
            ),
            new field(
                'teamsubmissiongroupingid',
                'PARAM_INT',
                'Grouping whose groups are used for group submissions (0 = all groups of the course). '
                    . 'WARNING: only grouping IDs of the same course are valid - the form lists only '
                    . 'those, but Moodle does not re-check this itself on write.',
                false,
                0,
                null,
                null,
                'mod/assign/mod_form.php:195-205 (groups_get_all_groupings($assignment->get_course()->id)); column '
                    . 'mod/assign/db/install.xml (assign.teamsubmissiongroupingid)'
            ),
            new field(
                'preventsubmissionnotingroup',
                'PARAM_BOOL',
                'Refuse submission if the learner is in no group. Only visible with '
                    . '"teamsubmission"=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:181-187 (selectyesno, hideIf teamsubmission eq 0); column '
                    . 'mod/assign/db/install.xml (assign.preventsubmissionnotingroup)'
            ),
            new field(
                'blindmarking',
                'PARAM_BOOL',
                'Anonymous grading: hide learners\' names from the teacher until they are revealed.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:226-231 (selectyesno); column mod/assign/db/install.xml (assign.blindmarking)'
            ),
            new field(
                'hidegrader',
                'PARAM_BOOL',
                'Reverse anonymity: hide the grader\'s name from the learners.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:233-235 (selectyesno); column mod/assign/db/install.xml (assign.hidegrader)'
            ),
            new field(
                'markingworkflow',
                'PARAM_BOOL',
                'Use a multi-stage grading workflow (in marking/ready for review/released, ...).',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:237-239 (selectyesno); column mod/assign/db/install.xml (assign.markingworkflow)'
            ),
            new field(
                'markingallocation',
                'PARAM_BOOL',
                'Allocate graders per submission. Only visible with "markingworkflow"=1; forced to 0 '
                    . 'on save if "markingworkflow"=0.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:241-244 (selectyesno, hideIf markingworkflow eq 0); '
                    . 'mod/assign/locallib.php:791-794/1587-1590 (enforcement); column '
                    . 'mod/assign/db/install.xml (assign.markingallocation)'
            ),
            new field(
                'markinganonymous',
                'PARAM_BOOL',
                'Graders do not see who is allocated to whom when allocating. Only visible with '
                    . '"markingworkflow"=1 and "blindmarking"=1; forced to 0 on save if one '
                    . 'of the two conditions is missing.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:246-250 (selectyesno, hideIf markingworkflow/blindmarking eq 0); '
                    . 'mod/assign/locallib.php:795-802/1591-1595 (enforcement); column '
                    . 'mod/assign/db/install.xml (assign.markinganonymous)'
            ),
            new field(
                'submissionattachments',
                'PARAM_BOOL',
                'Add the list of attached files to the submission summary on the grading page.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/mod_form.php:73-74 (advcheckbox); column '
                    . 'mod/assign/db/install.xml (assign.submissionattachments)'
            ),
        ];
    }

    /**
     * Provides state.
     *
     * @param int $instanceid The instanceid.
     * @param int $cmid The cmid.
     * @param bool $fullcontent The fullcontent.
     * @return array
     */
    public static function state(int $instanceid, int $cmid, bool $fullcontent): array {
        global $DB;

        $details = module_state::empty($fullcontent);
        $assign = $DB->get_record(
            'assign',
            ['id' => $instanceid],
            'id, name, intro, allowsubmissionsfromdate, duedate, cutoffdate, gradingduedate, completionsubmit, grade, '
                . 'submissiondrafts, requiresubmissionstatement, maxattempts, attemptreopenmethod, teamsubmission, '
                . 'requireallteammemberssubmit, teamsubmissiongroupingid, sendnotifications, sendlatenotifications, '
                . 'sendstudentnotifications, blindmarking, markingworkflow, markingallocation',
            IGNORE_MISSING
        );
        if (!$assign) {
            return $details;
        }

        $gradingmethod = \get_grading_manager(context_module::instance($cmid), 'mod_assign', 'submissions')->get_active_method();
        $gradeitem = $DB->get_record(
            'grade_items',
            ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id],
            'gradepass, categoryid',
            IGNORE_MISSING
        );
        $details['name'] = (string) $assign->name;
        $details['content'] = module_state::content_field((string) $assign->intro, $fullcontent);
        $pluginsettings = [];
        foreach ($DB->get_records('assign_plugin_config', ['assignment' => $assign->id]) as $config) {
            $pluginsettings[self::plugin_config_field($config)] = (string) $config->value;
        }
        $files = [];
        foreach (
            get_file_storage()->get_area_files(
                context_module::instance($cmid)->id,
                'mod_assign',
                'introattachment',
                0,
                'filepath, filename',
                false
            ) as $file
        ) {
            $files[] = [
                'filename' => $file->get_filename(),
                'filepath' => $file->get_filepath(),
                'filesize' => $file->get_filesize(),
                'mimetype' => $file->get_mimetype(),
            ];
        }
        $details['settings'] = module_state::settings(array_merge([
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
        return $details;
    }

    /**
     * Translates a row from assign_plugin_config to the form field name
     * of the catalog - four exceptions carry a different name in the form than
     * "{subtype}_{plugin}_{name}" (mod/assign/locallib.php: save_settings()
     * of the respective plugin class).
     *
     * @param \stdClass $config
     * @return string
     */
    private static function plugin_config_field(\stdClass $config): string {
        $field = $config->subtype . '_' . $config->plugin . '_' . $config->name;
        return [
            'assignsubmission_onlinetext_wordlimitenabled' => 'assignsubmission_onlinetext_wordlimit_enabled',
            'assignsubmission_file_maxfilesubmissions' => 'assignsubmission_file_maxfiles',
            'assignsubmission_file_maxsubmissionsizebytes' => 'assignsubmission_file_maxsizebytes',
            'assignsubmission_file_filetypeslist' => 'assignsubmission_file_filetypes',
        ][$field] ?? $field;
    }

    /**
     * Writes options.
     *
     * @return array
     */
    public static function write_options(): array {
        return [
            'editor_content' => ['activityeditor' => ['activity', 'activityformat']],
            'material_reference_fields' => ['introattachments' => ['component' => 'mod_assign', 'filearea' => 'introattachment']],
            'intro_image_field' => 'introimages',
            'admin_default_fields' => [
                'assignsubmission_file_enabled' => 'assignsubmission_file',
                'assignsubmission_onlinetext_enabled' => 'assignsubmission_onlinetext',
                'assignfeedback_comments_enabled' => 'assignfeedback_comments',
                'assignfeedback_editpdf_enabled' => 'assignfeedback_editpdf',
                'assignfeedback_file_enabled' => 'assignfeedback_file',
                'assignfeedback_offline_enabled' => 'assignfeedback_offline',
            ],
            'date_order_rules' => [
                ['reference' => 'allowsubmissionsfromdate', 'field' => 'duedate', 'mode' => 'must_be_after'],
                ['reference' => 'duedate', 'field' => 'cutoffdate', 'mode' => 'not_before'],
                ['reference' => 'allowsubmissionsfromdate', 'field' => 'cutoffdate', 'mode' => 'not_before'],
                ['reference' => 'allowsubmissionsfromdate', 'field' => 'gradingduedate', 'mode' => 'must_be_after'],
            ],
        ];
    }

    /**
     * Provides pseudofields.
     *
     * @return array
     */
    public static function pseudofields(): array {
        return [
            new field(
                'activityeditor',
                'array{text: string, format: int, itemid: int}',
                'Editor array for the additional activity text. The flat contract uses "activity" and '
                    . '"activityformat"; this field serves the native form path.',
                false,
                null,
                null,
                null,
                'mod/assign/mod_form.php:62-66 (Editor "activityeditor")'
            ),
            new field(
                'introimages',
                'string[] (material folder paths, image extensions only)',
                'Subject images that are embedded IN the description text (Spec 0018 §4.2/§5, issue '
                    . '#433 - closes the path from #430/#431: view preview, choose crop, embed '
                    . 'here). Each path must already exist as a material file and carry an extension from the '
                    . 'narrower embedding whitelist (png/jpg/jpeg/gif/svg/webp) - any other extension (e.g. '
                    . 'pdf) fails with a clear message. The patch carries the reference itself: the "intro" text '
                    . 'must contain an image element whose source begins with "@@PLUGINFILE@@/" plus the file name '
                    . '(Moodle\'s draft placeholder prefix), with an alt text that the AI '
                    . 'writes itself (glossary: alt text as AI quality routine). Without an accompanying "intro" patch '
                    . 'the file is only available in the file area, but unlinked.',
                false,
                null,
                null,
                null,
                'course/modlib.php:675-680 (update_moduleinfo(): file_save_draft_area_files() from '
                    . '$moduleinfo->introeditor resolves @@PLUGINFILE@@ against the draft file area)'
            ),
            new field(
                'introattachments',
                'string[] (material folder paths)',
                'Additional files of the assignment ("Additional files"/introattachments field) - list of '
                    . 'paths relative to the material folder (Spec 0018 §4.2/§7: the block from Spec 0015 §4.3 is gone '
                    . 'for assign). Each path is resolved server-side to an existing material file and '
                    . 'taken over unchanged; already existing attachments are kept. Not a chat attachment '
                    . 'directly - the file must first be in the material folder via upload_material_file.',
                false,
                null,
                null,
                null,
                'mod/assign/locallib.php:1648-1650 (save_intro_draft_files(), isset guard); '
                    . 'mod/assign/locallib.php:82 (ASSIGN_INTROATTACHMENT_FILEAREA="introattachment")'
            ),
            new field(
                'assignsubmission_file_enabled',
                'PARAM_BOOL',
                'Enable the submission type "File". Not a DB field: if it is missing, Moodle silently disables this '
                    . 'submission type (see class doc). With NO active submission type "nosubmissions"=1 is set - '
                    . 'the assignment then accepts no submissions at all. The form default is '
                    . 'admin-configurable (site administration), hence no fixed default here.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance(), "{subtype}_{type}_enabled"); '
                    . 'mod/assign/locallib.php:1713-1716 (default from get_config(\'assignsubmission_file\', \'default\')); '
                    . 'mod/assign/submission/file/settings.php:28 (admin setting "default")'
            ),
            new field(
                'assignsubmission_file_maxfiles',
                'PARAM_INT',
                'Maximum number of files per submission. Only effective with "assignsubmission_file_enabled"=1.',
                false,
                20,
                null,
                null,
                'mod/assign/submission/file/locallib.php:88 (Select), :129 (save_settings())'
            ),
            new field(
                'assignsubmission_file_maxsizebytes',
                'PARAM_INT',
                'Maximum file size per file in bytes. The selectable values are a subset depending on course and '
                    . 'server limit, not a fixed list. Only effective with '
                    . '"assignsubmission_file_enabled"=1.',
                false,
                0,
                null,
                'get_max_upload_sizes()',
                'lib/moodlelib.php:6453 (get_max_upload_sizes()); '
                    . 'mod/assign/submission/file/locallib.php:106 (Select), :130 (save_settings())'
            ),
            new field(
                'assignsubmission_file_filetypes',
                'PARAM_RAW',
                'Allowed file extensions/types, comma-separated (empty = all). Only effective with '
                    . '"assignsubmission_file_enabled"=1.',
                false,
                '',
                null,
                null,
                'mod/assign/submission/file/locallib.php:116 (filetypes element), :132-134 (save_settings())'
            ),
            new field(
                'assignsubmission_onlinetext_enabled',
                'PARAM_BOOL',
                'Enable the submission type "Online text". Not a DB field, same risk as '
                    . '"assignsubmission_file_enabled" (see class doc). The form default is '
                    . 'admin-configurable, hence no fixed default here.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (default from get_config(\'assignsubmission_onlinetext\', \'default\')); '
                    . 'mod/assign/submission/onlinetext/settings.php:26 (admin setting "default")'
            ),
            new field(
                'assignsubmission_onlinetext_wordlimit',
                'PARAM_INT',
                'Maximum word count of the online text. Only effective if additionally '
                    . '"assignsubmission_onlinetext_wordlimit_enabled"=1 is set.',
                false,
                0,
                null,
                null,
                'mod/assign/submission/onlinetext/locallib.php:97 (text field in the word-limit group)'
            ),
            new field(
                'assignsubmission_onlinetext_wordlimit_enabled',
                'PARAM_BOOL',
                'Apply the word limit to the online text at all. Without this field '
                    . '"assignsubmission_onlinetext_wordlimit" has no effect (combination rule).',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/submission/onlinetext/locallib.php:98-99 (checkbox in the word-limit group)'
            ),
            new field(
                'assignsubmission_comments_enabled',
                'PARAM_BOOL',
                'Enable the submission type "Comments" (discussion about the submission). Not a DB field, same risk as '
                    . '"assignsubmission_file_enabled" (see class doc). Unlike the other '
                    . 'submission plugins, this one has no admin setting of its own - the form value follows '
                    . 'solely whether the plugin is active server-wide.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/submission/comments/locallib.php:187-188 (is_configurable() === false, '
                    . 'no settings.php)'
            ),
            new field(
                'assignfeedback_comments_enabled',
                'PARAM_BOOL',
                'Enable the feedback type "Feedback comments". Not a DB field; its absence disables only this '
                    . 'feedback type, not the submission itself. The form default is admin-configurable, '
                    . 'hence no fixed default here.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (default from get_config(\'assignfeedback_comments\', \'default\')); '
                    . 'mod/assign/feedback/comments/settings.php:26 (admin setting "default")'
            ),
            new field(
                'assignfeedback_comments_commentinline',
                'PARAM_BOOL',
                'Insert the feedback comment directly into the submission instead of showing it alongside. Only effective with '
                    . '"assignfeedback_comments_enabled"=1.',
                false,
                0,
                [0, 1],
                null,
                'mod/assign/feedback/comments/locallib.php:246-263 (get_settings()/save_settings())'
            ),
            new field(
                'assignfeedback_editpdf_enabled',
                'PARAM_BOOL',
                'Enable the feedback type "Annotate PDF". Not a DB field, same pattern '
                    . 'as "assignfeedback_comments_enabled" - form default admin-configurable.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (default from get_config(\'assignfeedback_editpdf\', \'default\')); '
                    . 'mod/assign/feedback/editpdf/settings.php:29 (admin setting "default")'
            ),
            new field(
                'assignfeedback_file_enabled',
                'PARAM_BOOL',
                'Enable the feedback type "Feedback files" (returning files to the learners). Not a DB field, '
                    . 'same pattern as "assignfeedback_comments_enabled" - form default '
                    . 'admin-configurable.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (default from get_config(\'assignfeedback_file\', \'default\')); '
                    . 'mod/assign/feedback/file/settings.php:26 (admin setting "default")'
            ),
            new field(
                'assignfeedback_offline_enabled',
                'PARAM_BOOL',
                'Enable the feedback type "Offline grading worksheet" (export/import as a table). Not a DB field, '
                    . 'same pattern as "assignfeedback_comments_enabled" - form default '
                    . 'admin-configurable.',
                false,
                null,
                [0, 1],
                null,
                'mod/assign/locallib.php:1359-1373 (update_plugin_instance()); '
                    . 'mod/assign/locallib.php:1713-1716 (default from get_config(\'assignfeedback_offline\', \'default\')); '
                    . 'mod/assign/feedback/offline/settings.php:26 (admin setting "default")'
            ),
        ];
    }

    /**
     * Provides blocklist.
     *
     * @return array
     */
    public static function blocklist(): array {
        global $CFG;

        $fields = ['nosubmissions', 'revealidentities', 'completionsubmit'];
        // Marker changes require allocation checks and an explicit grade-recalculation
        // action from Moodle's form. Keep these settings in that native UI.
        if ((int) $CFG->branch >= 502) {
            $fields = array_merge($fields, ['markercount', 'multimarkmethod', 'multimarkrounding']);
        }
        if ((int) $CFG->branch >= 503) {
            $fields[] = 'optionalmarkercount';
        }
        return $fields;
    }

    /**
     * Provides combination rules.
     *
     * @return array
     */
    public static function combination_rules(): array {
        return [
            '"duedate" must be after "allowsubmissionsfromdate" if both are set '
                . '(mod/assign/mod_form.php: validation()).',
            '"cutoffdate" must not be before "duedate" if both are set (validation()).',
            '"cutoffdate" must not be before "allowsubmissionsfromdate" if both are set '
                . '(validation()).',
            '"gradingduedate" must be after "allowsubmissionsfromdate" if both are set '
                . '(validation()).',
            '"gradingduedate" must be after "duedate" if both are set (validation()).',
            '"attemptreopenmethod"="untilpass" cannot be combined with "blindmarking"=1 as soon as more than '
                . 'one attempt is allowed ("maxattempts" > 1 or unlimited) (validation()).',
        ];
    }

    /**
     * Provides side effects.
     *
     * @return array
     */
    public static function side_effects(): array {
        return [
            '"sendnotifications"=1 sends an email to all teachers of the assignment '
                . 'on every new submission from then on (mod/assign/locallib.php: email_graders()).',
            '"duedate"/"gradingduedate" each create or update a calendar event '
                . '(mod/assign/locallib.php: update_calendar()); "cutoffdate"/"allowsubmissionsfromdate" do '
                . 'not.',
        ];
    }

    /**
     * Provides bundles.
     *
     * @return array
     */
    public static function bundles(): array {
        return [
            'standard' => [
                'submissiondrafts' => 0,
                'requiresubmissionstatement' => 0,
                'teamsubmission' => 0,
                'blindmarking' => 0,
                'markingworkflow' => 0,
                'sendnotifications' => 1,
                'assignsubmission_file_enabled' => 1,
                'assignsubmission_onlinetext_enabled' => 0,
                'assignfeedback_comments_enabled' => 1,
            ],
            'exercise' => [
                'grade' => 0,
                'submissiondrafts' => 0,
                'requiresubmissionstatement' => 0,
                'sendnotifications' => 0,
                'maxattempts' => -1, // ASSIGN_UNLIMITED_ATTEMPTS.
                'attemptreopenmethod' => 'untilpass',
                'assignsubmission_onlinetext_enabled' => 1,
                'assignsubmission_file_enabled' => 0,
                'assignfeedback_comments_enabled' => 1,
            ],
        ];
    }

    /**
     * Writes route.
     *
     * @return ?string
     */
    public static function write_route(): ?string {
        return null;
    }

    /**
     * Provides checked constants.
     *
     * @return array
     */
    public static function checked_constants(): array {
        // The 34 constants from mod/assign/locallib.php without a callable
        // value set (Spec 0015 §11, ticket #382/#399). Exactly one exception:
        // ASSIGN_MARKER_FILTER_NO_MARKER is a filter-UI marker of the
        // grading table, not a field value of an instance - hence deliberately
        // not counted.
        return [
            'ASSIGN_SUBMISSION_STATUS_NEW',
            'ASSIGN_SUBMISSION_STATUS_REOPENED',
            'ASSIGN_SUBMISSION_STATUS_DRAFT',
            'ASSIGN_SUBMISSION_STATUS_SUBMITTED',
            'ASSIGN_FILTER_NONE',
            'ASSIGN_FILTER_SUBMITTED',
            'ASSIGN_FILTER_NOT_SUBMITTED',
            'ASSIGN_FILTER_SINGLE_USER',
            'ASSIGN_FILTER_REQUIRE_GRADING',
            'ASSIGN_FILTER_GRADED',
            'ASSIGN_FILTER_GRANTED_EXTENSION',
            'ASSIGN_FILTER_DRAFT',
            'ASSIGN_ATTEMPT_REOPEN_METHOD_MANUAL',
            'ASSIGN_ATTEMPT_REOPEN_METHOD_AUTOMATIC',
            'ASSIGN_ATTEMPT_REOPEN_METHOD_UNTILPASS',
            'ASSIGN_UNLIMITED_ATTEMPTS',
            'ASSIGN_GRADE_NOT_SET',
            'ASSIGN_GRADING_STATUS_GRADED',
            'ASSIGN_GRADING_STATUS_NOT_GRADED',
            'ASSIGN_MARKING_WORKFLOW_STATE_NOTMARKED',
            'ASSIGN_MARKING_WORKFLOW_STATE_INMARKING',
            'ASSIGN_MARKING_WORKFLOW_STATE_READYFORREVIEW',
            'ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW',
            'ASSIGN_MARKING_WORKFLOW_STATE_READYFORRELEASE',
            'ASSIGN_MARKING_WORKFLOW_STATE_RELEASED',
            'ASSIGN_MAX_EVENT_LENGTH',
            'ASSIGN_INTROATTACHMENT_FILEAREA',
            'ASSIGN_ACTIVITYATTACHMENT_FILEAREA',
            'ASSIGN_EVENT_TYPE_DUE',
            'ASSIGN_EVENT_TYPE_GRADINGDUE',
            'ASSIGN_EVENT_TYPE_OPEN',
            'ASSIGN_EVENT_TYPE_CLOSE',
            'ASSIGN_EVENT_TYPE_EXTENSION',
        ];
    }

    /**
     * Provides learner locks.
     *
     * @return array
     */
    public static function learner_locks(): array {
        // Baseline from #582, verified against Moodle 5.0.8
        // (mod/assign/locallib.php: submissions_open(), is_blind_marking(),
        // get_marking_workflow_states_for_current_user()).
        return [
            'submissiondrafts' => ['op' => 'equals', 'value' => 1,
                'reason' => 'Learners must press "Submit"; the submission is then locked until the teacher reverts it to draft.'],
            'attemptreopenmethod' => ['op' => 'equals', 'value' => 'manual',
                'reason' => 'A new attempt only opens when the teacher reopens it by hand.'],
            'cutoffdate' => ['op' => 'nonzero',
                'reason' => 'After the cut-off date no submission is possible unless the teacher grants an extension.'],
            'timelimit' => ['op' => 'nonzero',
                'reason' => 'When the time limit runs out the learner cannot continue unless the teacher grants an extension.'],
            'requireallteammemberssubmit' => ['op' => 'equals', 'value' => 1,
                'reason' => 'A group submission only counts once every member has submitted; one missing member blocks the group.'],
            'preventsubmissionnotingroup' => ['op' => 'equals', 'value' => 1,
                'reason' => 'Learners outside a group cannot submit until the teacher adds them to one.'],
            'markingworkflow' => ['op' => 'equals', 'value' => 1,
                'reason' => 'Grades and feedback stay hidden from learners until the teacher releases them.'],
            'blindmarking' => ['op' => 'equals', 'value' => 1,
                'reason' => 'Grades and feedback stay hidden from learners until the teacher reveals identities.'],
        ];
    }

    /**
     * The teacher grades - unless an instance has no grading
     * (grade = 0, e.g. bundle "exercise").
     *
     * @param int $instanceid The instanceid.
     */
    public static function grade_origin(int $instanceid = 0): string {
        global $DB;

        if ($instanceid > 0 && (int) $DB->get_field('assign', 'grade', ['id' => $instanceid]) === 0) {
            return learner_locks::GRADE_NONE;
        }
        return learner_locks::GRADE_TEACHER;
    }

    /**
     * Provides reviewed up to major.
     *
     * @return int
     */
    public static function reviewed_up_to_major(): int {
        return self::LAST_JOINT_REVIEW_MAJOR;
    }
}
