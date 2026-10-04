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

namespace local_coursepilot\history;

defined('MOODLE_INTERNAL') || die();

/**
 * History retention and the shared history deletion contract (#387, #640).
 *
 * Course and activity deletion cascade immediately. The retention period
 * (default 365 days, minimum one day, no unlimited value) is enforced by the
 * scheduled task {@see \local_coursepilot\task\purge_history} in bounded,
 * indexed batches - independent of further writes - and opportunistically on
 * each write of the same activity. The same task sweeps historical file
 * metadata against {@see file_policy} and removes orphaned metadata.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class retention {

    /** @var int Default in days (Spec 0015 §10.7). */
    public const DEFAULT_DAYS = 365;

    /** @var int Lower bound: "no retention period" is excluded (Spec 0015 §10.7). */
    public const MIN_DAYS = 1;

    /** @var int Rows per batch of the scheduled task. */
    public const BATCH_SIZE = 500;

    /** @var int Batches per phase and task run; the next run continues. */
    public const MAX_BATCHES = 20;

    /** @var string Config key: last cm_file id visited by the metadata sweep. */
    private const CURSOR = 'historyfilecleanupcursor';

    /**
     * The configured deletion period in days, clamped to at least one day
     * against a raw/tampered config value (0, negative, empty) - so
     * "no retention period" stays excluded even if the stored value itself
     * were invalid.
     *
     * @return int
     */
    public static function days(): int {
        $configured = (int) get_config('local_coursepilot', 'historyretentiondays');
        return max(self::MIN_DAYS, $configured);
    }

    /**
     * Deletes expired states of one activity on its next write; the scheduled
     * task covers activities that are never written again.
     *
     * @param int $cmid
     * @return void
     */
    public static function purge_expired_for_cm(int $cmid): void {
        global $DB;

        $cutoff = time() - self::days() * DAYSECS;
        $versionids = $DB->get_fieldset_select(
            'local_coursepilot_cm_version',
            'id',
            'cmid = ? AND timecreated < ?',
            [$cmid, $cutoff]
        );
        self::delete_versions($versionids);
    }

    /**
     * Deletes the entire history of an activity - activity cascade
     * (course_module_deleted, #387). Unconditional, independent of the retention period.
     *
     * @param int $cmid
     * @return void
     */
    public static function purge_cm(int $cmid): void {
        global $DB;

        $versionids = $DB->get_fieldset_select('local_coursepilot_cm_version', 'id', 'cmid = ?', [$cmid]);
        self::delete_versions($versionids);
    }

    /**
     * Deletes the states the given users wrote on one activity (privacy deletion, #641).
     *
     * @param int $cmid
     * @param int[] $userids
     * @return void
     */
    public static function purge_cm_for_users(int $cmid, array $userids): void {
        global $DB;

        if (!$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_values($userids));
        self::delete_versions($DB->get_fieldset_select('local_coursepilot_cm_version', 'id',
            "cmid = ? AND userid $insql", array_merge([$cmid], $inparams)));
    }

    /**
     * Deletes the entire history of a course - course cascade (course_deleted,
     * #387). Unconditional, independent of the retention period.
     *
     * course_modules is already deleted at this point (Moodle core deletes
     * the course contents before the event) - the mapping must therefore go
     * through the courseid recorded by {@see version_writer::capture()},
     * not through a join against course_modules.
     *
     * @param int $courseid
     * @return void
     */
    public static function purge_course(int $courseid): void {
        global $DB;

        $versionids = $DB->get_fieldset_select('local_coursepilot_cm_version', 'id', 'courseid = ?', [$courseid]);
        self::delete_versions($versionids);
    }

    /**
     * Scheduled enforcement: expire old states and sweep file metadata, each in
     * at most $maxbatches batches of $batchsize rows. Safe to repeat or interrupt.
     *
     * @param int $batchsize
     * @param int $maxbatches
     * @return void
     */
    public static function enforce(int $batchsize = self::BATCH_SIZE, int $maxbatches = self::MAX_BATCHES): void {
        for ($i = 0; $i < $maxbatches && self::purge_expired_batch($batchsize) === $batchsize; $i++) {
            // Next batch.
        }
        for ($i = 0; $i < $maxbatches && self::sweep_file_batch($batchsize) === $batchsize; $i++) {
            // Next batch.
        }
    }

    /**
     * Deletes up to $limit expired states across all activities (index timecreated).
     *
     * @param int $limit
     * @return int Number of deleted states.
     */
    private static function purge_expired_batch(int $limit): int {
        global $DB;

        $cutoff = time() - self::days() * DAYSECS;
        $versionids = array_keys($DB->get_records_select(
            'local_coursepilot_cm_version', 'timecreated < ?', [$cutoff], '', 'id', 0, $limit));
        self::delete_versions($versionids);
        return count($versionids);
    }

    /**
     * Visits the next $limit cm_file rows after the stored cursor: drops links the
     * file policy rejects for the state's activity type, then rows left without links.
     * At the end of the table the cursor wraps, so the sweep repeats on later runs.
     *
     * @param int $limit
     * @return int Number of visited rows; fewer than $limit means the sweep wrapped.
     */
    private static function sweep_file_batch(int $limit): int {
        global $DB;

        $cursor = (int) get_config('local_coursepilot', self::CURSOR);
        $fileids = array_keys($DB->get_records_select(
            'local_coursepilot_cm_file', 'id > ?', [$cursor], 'id ASC', 'id', 0, $limit));
        if ($fileids) {
            [$insql, $params] = $DB->get_in_or_equal($fileids);
            // Activities whose module is gone are skipped: without a module type
            // there is no evidence the metadata is disallowed. Retention expires them.
            $links = $DB->get_records_sql(
                "SELECT vf.id, f.component, f.filearea, m.name AS modname
                   FROM {local_coursepilot_cm_version_file} vf
                   JOIN {local_coursepilot_cm_file} f ON f.id = vf.fileid
                   JOIN {local_coursepilot_cm_version} v ON v.id = vf.versionid
                   JOIN {course_modules} cm ON cm.id = v.cmid
                   JOIN {modules} m ON m.id = cm.module
                  WHERE vf.fileid $insql",
                $params
            );
            $rejected = array_keys(array_filter($links, static fn(\stdClass $link): bool =>
                !file_policy::allows($link->modname, $link->component, $link->filearea)));
            $transaction = $DB->start_delegated_transaction();
            if ($rejected) {
                [$linksql, $linkparams] = $DB->get_in_or_equal($rejected);
                $DB->delete_records_select('local_coursepilot_cm_version_file', "id $linksql", $linkparams);
            }
            self::delete_orphan_files($fileids);
            $transaction->allow_commit();
        }
        set_config(self::CURSOR, count($fileids) < $limit ? 0 : (int) end($fileids), 'local_coursepilot');
        return count($fileids);
    }

    /**
     * Shared history deletion contract: deletes the given states, their file
     * links and every file metadata row no longer referenced by another state.
     * Used by retention, activity/course cascades and privacy deletion.
     *
     * @param array $versionids
     * @return void
     */
    public static function delete_versions(array $versionids): void {
        global $DB;

        if (!$versionids) {
            return;
        }

        [$insql, $inparams] = $DB->get_in_or_equal(array_values($versionids));
        $transaction = $DB->start_delegated_transaction();
        $fileids = $DB->get_fieldset_select('local_coursepilot_cm_version_file', 'DISTINCT fileid', "versionid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_cm_version_file', "versionid $insql", $inparams);
        $DB->delete_records_select('local_coursepilot_cm_version', "id $insql", $inparams);
        self::delete_orphan_files($fileids);
        $transaction->allow_commit();
    }

    /**
     * Deletes those of the given cm_file rows that no state links to any more.
     *
     * @param array $fileids
     * @return void
     */
    private static function delete_orphan_files(array $fileids): void {
        global $DB;

        if (!$fileids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal(array_values($fileids));
        // Capture takes the same row lock until its reference is inserted. Decide
        // orphan status only after any concurrent capture has committed its link.
        $DB->execute("UPDATE {local_coursepilot_cm_file} SET id = id WHERE id $insql", $inparams);
        $referenced = $DB->get_fieldset_select('local_coursepilot_cm_version_file', 'DISTINCT fileid', "fileid $insql", $inparams);
        $orphans = array_diff($fileids, $referenced);
        if ($orphans) {
            [$orphansql, $orphanparams] = $DB->get_in_or_equal(array_values($orphans));
            $DB->delete_records_select('local_coursepilot_cm_file', "id $orphansql", $orphanparams);
        }
    }
}
