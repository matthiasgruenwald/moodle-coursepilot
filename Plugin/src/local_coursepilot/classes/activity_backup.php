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

namespace local_coursepilot;

use invalid_parameter_exception;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Single-activity backup (Spec 0026 module 1, ADR 0028).
 *
 * - {@see self::export()}: the activity XML (`<mod>.xml`) of an activity, no user data
 *   (MODE_IMPORT forces users=0).
 * - {@see self::backup()} + {@see self::restore()}: real single-activity backup, restored
 *   into a course (clone path, same primitives as Moodle's own duplicate_module()).
 * - {@see self::restore()} also takes an activity XML as source; the backup scaffold around
 *   it is built here (version data from $CFG and backup::VERSION).
 *
 * Restore is not atomic: this class removes the temp directory and any half-made activity
 * itself when a restore fails.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class activity_backup {
    /** Placeholder module id inside a synthesized backup. */
    private const SYNTH_CMID = 900001;

    /**
     * Activity XML of an existing activity, without user data.
     *
     * @param \stdClass $cm course module record (needs id and modname)
     * @return string
     * @throws moodle_exception activitybackupfailed
     */
    public static function export(\stdClass $cm): string {
        $backupid = self::backup($cm);
        try {
            $xml = @file_get_contents(self::backup_path($backupid) . "/activities/{$cm->modname}_{$cm->id}/{$cm->modname}.xml");
        } finally {
            self::discard_tempdir($backupid);
        }
        if ($xml === false) {
            throw new moodle_exception('activitybackupfailed', 'local_coursepilot');
        }
        return $xml;
    }

    /**
     * Real single-activity backup (MODE_IMPORT) in the backup temp directory.
     * The caller hands the id to {@see self::restore()}, which removes the directory.
     *
     * @param \stdClass $cm course module record (needs id)
     * @return string backup id
     */
    public static function backup(\stdClass $cm): string {
        global $USER;
        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cm->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            (int) $USER->id
        );
        $backupid = $bc->get_backupid();
        try {
            $bc->execute_plan();
        } catch (\Throwable $e) {
            self::discard_tempdir($backupid);
            throw $e;
        } finally {
            $bc->destroy();
        }
        return $backupid;
    }

    /**
     * Restores one activity into a course.
     *
     * @param int $courseid target course
     * @param int|null $sectionnum target section for an activity XML (required there);
     *        a real backup keeps the section it was taken from
     * @param string $source activity XML (starts with "<") or backup id from {@see self::backup()}
     * @return int new cmid
     * @throws invalid_parameter_exception source is XML but not an activity XML
     * @throws moodle_exception activityrestorefailed
     */
    public static function restore(int $courseid, ?int $sectionnum, string $source): int {
        global $DB;
        $isxml = str_starts_with(ltrim($source), '<');
        $backupid = $source;
        if ($isxml) {
            $modname = self::modname_of($source);
            $backupid = self::scaffold($source, $modname, $sectionnum ?? 1);
        }
        $before = $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]);
        try {
            return self::run_restore($backupid, $courseid);
        } catch (\Throwable $e) {
            self::remove_new_modules($courseid, $before);
            throw $e;
        } finally {
            self::discard_tempdir($backupid);
        }
    }

    private static function backup_path(string $backupid): string {
        global $CFG;
        return $CFG->tempdir . '/backup/' . $backupid;
    }

    private static function discard_tempdir(string $backupid): void {
        global $CFG;
        if (empty($CFG->keeptempdirectoriesonbackup)) {
            fulldelete(self::backup_path($backupid));
        }
    }

    private static function modname_of(string $xml): string {
        $dom = new \DOMDocument();
        $modname = $dom->loadXML($xml, LIBXML_NONET) ? $dom->documentElement->getAttribute('modulename') : '';
        if ($dom->documentElement?->nodeName !== 'activity' || !preg_match('/^[a-z][a-z0-9]*$/', $modname)
                || !plugin_supports('mod', $modname, FEATURE_BACKUP_MOODLE2)) {
            throw new invalid_parameter_exception('Not an activity XML of a backup-capable activity type.');
        }
        return $modname;
    }

    /**
     * Builds the backup scaffold (the 16 files Moodle expects) around one activity XML.
     *
     * @return string backup id
     */
    private static function scaffold(string $activityxml, string $modname, int $sectionnum): string {
        global $CFG;
        $backupid = 'cp' . random_string(30);
        $base = self::backup_path($backupid);
        $cmid = self::SYNTH_CMID;
        $dir = "activities/{$modname}_{$cmid}";
        $modversion = (int) get_config("mod_$modname", 'version') ?: (int) $CFG->version;
        $now = time();
        $files = [
            'files.xml' => '<files></files>',
            'roles.xml' => '<roles_definition></roles_definition>',
            'completion.xml' => '<course_completion></course_completion>',
            'scales.xml' => '<scales_definition></scales_definition>',
            'outcomes.xml' => '<outcomes_definition></outcomes_definition>',
            'questions.xml' => '<question_categories></question_categories>',
            'groups.xml' => '<groups><groupcustomfields></groupcustomfields><groupings><groupingcustomfields></groupingcustomfields></groupings></groups>',
            "$dir/calendar.xml" => '<events></events>',
            "$dir/inforef.xml" => '<inforef></inforef>',
            "$dir/filters.xml" => '<filters><filter_actives></filter_actives><filter_configs></filter_configs></filters>',
            "$dir/roles.xml" => '<roles><role_overrides></role_overrides><role_assignments></role_assignments></roles>',
            "$dir/grades.xml" => '<activity_gradebook><grade_items></grade_items><grade_letters></grade_letters></activity_gradebook>',
            "$dir/grade_history.xml" => '<grade_history><grade_grades></grade_grades></grade_history>',
            "$dir/competencies.xml" => '<course_module_competencies><competencies></competencies></course_module_competencies>',
            "$dir/module.xml" => "<module id=\"$cmid\" version=\"$modversion\"><modulename>$modname</modulename>"
                . "<sectionid>1</sectionid><sectionnumber>$sectionnum</sectionnumber><idnumber>\$@NULL@\$</idnumber>"
                . "<added>$now</added><score>0</score><indent>0</indent><visible>1</visible>"
                . '<visibleoncoursepage>1</visibleoncoursepage><visibleold>1</visibleold><groupmode>0</groupmode>'
                . '<groupingid>0</groupingid><completion>0</completion><completiongradeitemnumber>$@NULL@$</completiongradeitemnumber>'
                . '<completionpassgrade>0</completionpassgrade><completionview>0</completionview><completionexpected>0</completionexpected>'
                . '<availability>$@NULL@$</availability><showdescription>0</showdescription><downloadcontent>1</downloadcontent>'
                . '<lang>$@NULL@$</lang><tags></tags></module>',
            "$dir/$modname.xml" => preg_replace('/^<\?xml[^>]*>\s*/', '', ltrim($activityxml)),
            'moodle_backup.xml' => self::moodle_backup_xml($modname, $dir, $now),
        ];
        try {
            foreach ($files as $path => $xml) {
                check_dir_exists(dirname("$base/$path"));
                file_put_contents("$base/$path", '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $xml);
            }
        } catch (\Throwable $e) {
            self::discard_tempdir($backupid);
            throw $e;
        }
        return $backupid;
    }

    private static function moodle_backup_xml(string $modname, string $dir, int $now): string {
        global $CFG;
        $cmid = self::SYNTH_CMID;
        $settings = '';
        foreach (['users' => 0, 'activities' => 1, 'files' => 1, 'filters' => 1, 'calendarevents' => 1, 'groups' => 1,
                'competencies' => 1, 'customfield' => 1, 'contentbankcontent' => 1] as $name => $value) {
            $settings .= "<setting><level>root</level><name>$name</name><value>$value</value></setting>";
        }
        $act = "<activity>{$modname}_{$cmid}</activity>";
        $settings .= "<setting><level>activity</level>$act<name>{$modname}_{$cmid}_included</name><value>1</value></setting>"
            . "<setting><level>activity</level>$act<name>{$modname}_{$cmid}_userinfo</name><value>0</value></setting>";
        $release = s($CFG->release);
        $backuprelease = s(\backup::RELEASE);
        return '<moodle_backup><information><name>synth.mbz</name>'
            . "<moodle_version>{$CFG->version}</moodle_version><moodle_release>$release</moodle_release>"
            . '<backup_version>' . \backup::VERSION . "</backup_version><backup_release>$backuprelease</backup_release>"
            . "<backup_date>$now</backup_date><mnet_remoteusers>0</mnet_remoteusers><include_files>0</include_files>"
            . '<include_file_references_to_external_content>0</include_file_references_to_external_content>'
            . '<original_wwwroot>https://synthetic.invalid</original_wwwroot><original_site_identifier_hash>synthetic</original_site_identifier_hash>'
            . '<original_course_id>1</original_course_id><original_course_format>topics</original_course_format>'
            . '<original_course_fullname>s</original_course_fullname><original_course_shortname>s</original_course_shortname>'
            . '<original_course_startdate>0</original_course_startdate><original_course_enddate>0</original_course_enddate>'
            . '<original_course_contextid>1</original_course_contextid><original_system_contextid>1</original_system_contextid>'
            . '<details><detail backup_id="synthetic"><type>activity</type><format>moodle2</format><interactive></interactive>'
            . '<mode>' . \backup::MODE_IMPORT . '</mode><execution>1</execution><executiontime>0</executiontime></detail></details>'
            . "<contents><activities><activity><moduleid>$cmid</moduleid><sectionid>1</sectionid><modulename>$modname</modulename>"
            . "<title>synth</title><directory>$dir</directory><insubsection></insubsection></activity></activities></contents>"
            . "<settings>$settings</settings></information></moodle_backup>";
    }

    private static function run_restore(string $backupid, int $courseid): int {
        global $USER;
        $rc = new \restore_controller(
            $backupid,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            (int) $USER->id,
            \backup::TARGET_CURRENT_ADDING
        );
        try {
            if (!$rc->execute_precheck()) {
                $results = $rc->get_precheck_results();
                if (is_array($results) && !empty($results['errors'])) {
                    throw new moodle_exception('backupprecheckerrors', 'backup', '', $results);
                }
                throw new moodle_exception('activityrestorefailed', 'local_coursepilot');
            }
            $rc->execute_plan();
            // A single-activity backup holds exactly one activity task.
            foreach ($rc->get_plan()->get_tasks() as $task) {
                if (is_subclass_of($task, 'restore_activity_task') && $task->get_moduleid()) {
                    return (int) $task->get_moduleid();
                }
            }
        } finally {
            $rc->destroy();
        }
        throw new moodle_exception('activityrestorefailed', 'local_coursepilot');
    }

    /**
     * Discards every course module that appeared in the course since $before
     * (a failed restore can leave half-made rows, see {@see course_module_placement::discard_failed()}).
     *
     * @param int[] $before cmids present before the restore
     */
    private static function remove_new_modules(int $courseid, array $before): void {
        global $DB;
        $new = array_diff($DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]), $before);
        foreach ($new as $cmid) {
            course_module_placement::discard_failed((int) $cmid);
        }
        rebuild_course_cache($courseid, true);
    }
}
