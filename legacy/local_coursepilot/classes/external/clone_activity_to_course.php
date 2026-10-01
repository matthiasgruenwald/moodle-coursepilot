<?php
// This file is part of Moodle - http://moodle.org/

namespace local_coursepilot\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

use context_course;
use context_module;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use invalid_parameter_exception;

/**
 * Clones a single activity across courses (Spec 0013 KP-010, cross-course
 * path). No core webservice covers this case - core_courseformat_update_course
 * (cm_duplicate) only duplicates within the same course. This adapter drives
 * backup_controller/restore_controller directly, the same primitives Moodle's
 * own duplicate_module() (course/lib.php) uses for the intra-course case:
 * single-activity backup (MODE_IMPORT) immediately restored into the target
 * course (TARGET_CURRENT_ADDING). The new cmid is recovered the same way
 * core does it - matching the restored restore_activity_task's recorded old
 * context id against the source module's context id.
 */
class clone_activity_to_course extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module ID of the source activity'),
            'targetcourseid' => new external_value(PARAM_INT, 'Target course ID (must differ from the source course)'),
            'targetsectionnum' => new external_value(
                PARAM_INT,
                'Target section number in the target course; -1 = leave where the restore places it',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    public static function execute(int $cmid, int $targetcourseid, int $targetsectionnum = -1): array {
        global $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
            'targetcourseid' => $targetcourseid,
            'targetsectionnum' => $targetsectionnum,
        ]);

        [$cm, $oldcmcontextid] = self::authorize($params);

        [$backupid, $backupbasepath] = self::run_backup($cm, (int) $USER->id);
        try {
            $newcmid = self::run_restore($backupid, $params['targetcourseid'], (int) $USER->id, $oldcmcontextid);
        } finally {
            if (empty($CFG->keeptempdirectoriesonbackup)) {
                fulldelete($backupbasepath);
            }
        }

        $newcm = get_coursemodule_from_id(null, $newcmid, $params['targetcourseid'], false, MUST_EXIST);
        $newsectionnum = self::place_in_section($newcm, $params['targetcourseid'], $params['targetsectionnum']);

        rebuild_course_cache($params['targetcourseid'], true);

        return [
            'cmid' => (int) $newcmid,
            'courseid' => (int) $params['targetcourseid'],
            'sectionnum' => $newsectionnum,
        ];
    }

    /**
     * Resolves the source cm, rejects same-course/unsupported requests, and
     * enforces every capability check. Both course contexts are validated
     * and gated with the Coursepilot use-capability (local/coursepilot:use),
     * like every other Coursepilot webservice (see test/webservice-trainer-
     * scope-contract.test.js). On top, Spec 0013 (KP-010) requires explicit
     * backup/restore capabilities: moodle/backup:backuptargetimport in the
     * SOURCE COURSE context (not the module context - capability
     * inheritance differs), moodle/restore:restoretargetimport and
     * moodle/course:manageactivities in the target course context.
     * Returns [cm, old module context id] - the latter is needed later to
     * match the restored activity task (see run_restore()).
     */
    private static function authorize(array $params): array {
        $cm = get_coursemodule_from_id(null, $params['cmid'], 0, false, MUST_EXIST);
        $sourcecourseid = (int) $cm->course;

        if ($params['targetcourseid'] === $sourcecourseid) {
            throw new invalid_parameter_exception(
                'targetcourseid muss vom Quellkurs abweichen - fuer den Intra-Kurs-Fall ' .
                'moodle_clone_activity ohne courseid bzw. mit gleichem courseid verwenden.'
            );
        }

        if (!plugin_supports('mod', $cm->modname, FEATURE_BACKUP_MOODLE2)) {
            throw new invalid_parameter_exception(
                "Aktivitaetstyp '{$cm->modname}' unterstuetzt keinen Aktivitaets-Export (kein FEATURE_BACKUP_MOODLE2) " .
                'und kann daher nicht kursuebergreifend geklont werden.'
            );
        }

        $sourcecoursecontext = context_course::instance($sourcecourseid);
        self::validate_context($sourcecoursecontext);
        require_capability('local/coursepilot:use', $sourcecoursecontext);

        $targetcontext = context_course::instance($params['targetcourseid']);
        self::validate_context($targetcontext);
        require_capability('local/coursepilot:use', $targetcontext);

        require_capability('moodle/backup:backuptargetimport', $sourcecoursecontext);
        require_capability('moodle/restore:restoretargetimport', $targetcontext);
        require_capability('moodle/course:manageactivities', $targetcontext);

        $cmcontext = context_module::instance($cm->id);

        return [$cm, (int) $cmcontext->id];
    }

    /**
     * Single-activity export from the source course. Returns [backupid,
     * backupbasepath] - the caller owns cleanup of backupbasepath.
     */
    private static function run_backup(\stdClass $cm, int $userid): array {
        $bc = new \backup_controller(
            \backup::TYPE_1ACTIVITY,
            $cm->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $userid
        );
        $backupid = $bc->get_backupid();
        $backupbasepath = $bc->get_plan()->get_basepath();
        $bc->execute_plan();
        $bc->destroy();

        return [$backupid, $backupbasepath];
    }

    /**
     * Imports the backup into the target course and returns the new cmid.
     * Matches the restored restore_activity_task against the source
     * module's old context id - the same technique core's own
     * duplicate_module() (course/lib.php) uses for the intra-course case.
     */
    private static function run_restore(string $backupid, int $targetcourseid, int $userid, int $oldcmcontextid): int {
        $rc = new \restore_controller(
            $backupid,
            $targetcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $userid,
            \backup::TARGET_CURRENT_ADDING
        );

        if (!$rc->execute_precheck()) {
            $precheckresults = $rc->get_precheck_results();
            $rc->destroy();
            if (is_array($precheckresults) && !empty($precheckresults['errors'])) {
                throw new \moodle_exception('backupprecheckerrors', 'backup', '', $precheckresults);
            }
            throw new \moodle_exception(
                'generalexceptionmessage',
                'error',
                '',
                'Restore-Vorpruefung fuer den kursuebergreifenden Klon ist fehlgeschlagen.'
            );
        }

        $rc->execute_plan();

        $newcmid = null;
        foreach ($rc->get_plan()->get_tasks() as $task) {
            if (is_subclass_of($task, 'restore_activity_task') && $task->get_old_contextid() == $oldcmcontextid) {
                $newcmid = $task->get_moduleid();
                break;
            }
        }

        $rc->destroy();

        if (!$newcmid) {
            throw new \moodle_exception(
                'generalexceptionmessage',
                'error',
                '',
                'Neue Aktivitaet nach dem kursuebergreifenden Import nicht auffindbar.'
            );
        }

        return (int) $newcmid;
    }

    /**
     * Determines the section the restore placed the clone in and, if a
     * different targetsectionnum was requested, moves it there.
     */
    private static function place_in_section(\stdClass $newcm, int $targetcourseid, int $targetsectionnum): int {
        global $DB;

        $newsectionnum = (int) $DB->get_field('course_sections', 'section', ['id' => $newcm->section], MUST_EXIST);

        if ($targetsectionnum < 0 || $targetsectionnum === $newsectionnum) {
            return $newsectionnum;
        }

        $targetsection = $DB->get_record('course_sections', [
            'course' => $targetcourseid,
            'section' => $targetsectionnum,
        ], '*', MUST_EXIST);
        moveto_module($newcm, $targetsection);

        return (int) $targetsection->section;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'cmid' => new external_value(PARAM_INT, 'New course module ID in the target course'),
            'courseid' => new external_value(PARAM_INT, 'Target course ID'),
            'sectionnum' => new external_value(PARAM_INT, 'Section number in the target course where the clone was placed'),
        ]);
    }
}
