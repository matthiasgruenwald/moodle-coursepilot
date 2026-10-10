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

/**
 * Snapshot store of the change history (#385, Spec 0015 §10.4/§10.8).
 *
 * Deliberately does NOT build on course/modlib.php::get_moduleinfo_data():
 * that function requires can_update_moduleinfo() (moodle/course:manageactivities)
 * and creates a new draft file area for introeditor on every call
 * - a write side effect that an observer which should only read/serialize
 * must not trigger. The field assembly is therefore duplicated here
 * (same approach as {@see \local_coursepilot\external\get_module_settings}),
 * extended by the gradepass/gradecat/outcome fields deliberately left out there,
 * which Spec 0015 §10.4 explicitly requires for the history.
 *
 * Intro and allowed material files are captured without introeditor/draft side effects.
 * Only their metadata is deduplicated in local_coursepilot_cm_file.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class version_writer {
    /** @var string Origin while only the native Moodle write path is observed. */
    public const SOURCE_MOODLE = version_source::MOODLE;

    /** @var string Origin of the retroactively created prior state (#386, Spec 0015 §10.3). */
    public const SOURCE_DISCOVERED = version_source::DISCOVERED;

    /** @var string Origin of a clone (#421, Spec 0017 §7.5) - always version 1, never via capture_on_update(). */
    public const SOURCE_CLONED = version_source::CLONED;

    /** @var string Origin of an activity from activity XML (ADR 0028, #596). */
    public const SOURCE_FROM_XML = version_source::FROM_XML;

    /** @var string Marker state on the old cmid after supersession; sourcecmid = new cmid (#596). */
    public const SOURCE_SUPERSEDED = version_source::SUPERSEDED;

    /**
     * Marker state on the old cmid: "superseded by $newcmid" (#596, Spec 0026).
     * The reference runs through the existing sourcecmid field, no schema change.
     *
     * @param int $oldcmid
     * @param int $newcmid
     * @param int $userid
     * @return int id of the new version
     */
    public static function capture_superseded(int $oldcmid, int $newcmid, int $userid): int {
        return self::capture($oldcmid, $userid, self::SOURCE_SUPERSEDED, $newcmid);
    }

    /**
     * Captures the current state on a change (course_module_updated). If no
     * state exists yet for the cmid - an activity that existed before
     * Coursepilot and for which no course_module_created event was therefore
     * ever observed - a retroactive "discovered" version 1 is created first
     * (#386, Spec 0015 §10.3). The actual prior state (the state before
     * exactly this write) is technically no longer reconstructible at this
     * point - course_module_updated fires after the write, and Moodle
     * delivers no full dump of the old state in the event. The
     * discovered version therefore captures the state current at event time
     * (already written); it is deliberately identical in content to the
     * version 2 created right after - better than no fallback position,
     * and "costs nothing when idle" (Spec 0015 §10.3).
     *
     * @param int $cmid
     * @param int $userid
     * @param string $source
     * @return int id of the newly created (latest) version
     */
    public static function capture_on_update(int $cmid, int $userid, string $source = self::SOURCE_MOODLE): int {
        global $DB;

        // Transaction instead of two free-standing statements: closes the
        // check-then-act gap between record_exists() and the insert for
        // the usual case. A truly concurrent second write
        // on the same cmid would still be a DML error instead of a second
        // silent discovered version - the cmid+version unique index applies.
        // ponytail: no SELECT-FOR-UPDATE lock on a row that does not exist yet;
        // if really needed (bulk editing with parallel requests)
        // add an advisory lock per cmid.
        $transaction = $DB->start_delegated_transaction();

        try {
            if (!$DB->record_exists('local_coursepilot_cm_version', ['cmid' => $cmid])) {
                self::capture($cmid, $userid, self::SOURCE_DISCOVERED);
            }
            $versionid = self::capture($cmid, $userid, $source);
            $transaction->allow_commit();
            return $versionid;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Captures the current state of an activity as a new version.
     *
     * @param int $cmid
     * @param int $userid User under which the write ran (event userid).
     * @param string $source
     * @param int|null $sourcecmid Reference cmid, see {@see version_source}: clone source (cloned) or new cmid (superseded),
     * otherwise null.
     * @return int id of the newly created version
     */
    public static function capture(
        int $cmid,
        int $userid,
        string $source = self::SOURCE_MOODLE,
        ?int $sourcecmid = null
    ): int {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $transaction = $DB->start_delegated_transaction();
        try {
            $nextversion = (int) $DB->get_field_sql(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM {local_coursepilot_cm_version} WHERE cmid = ?',
                [$cm->id]
            );

            $versionid = (int) $DB->insert_record('local_coursepilot_cm_version', (object) [
                'cmid' => $cm->id,
                'courseid' => (int) $cm->course,
                'version' => $nextversion,
                'source' => $source,
                'sourcecmid' => $sourcecmid,
                'userid' => $userid,
                'moduleinfo_json' => json_encode(self::build_moduleinfo($cm), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'coursemodule_json' => json_encode(
                    (array) $DB->get_record('course_modules', ['id' => $cm->id], '*', MUST_EXIST),
                    JSON_UNESCAPED_UNICODE
                ),
                'arrangement_json' => self::build_arrangement_json($cm),
                'timecreated' => time(),
            ]);

            self::capture_files($versionid, $context->id, (string) $cm->modname);

            // Purge old states of this activity after recording the complete new state.
            // The scheduled task also covers activities without further writes.
            retention::purge_expired_for_cm($cm->id);

            $transaction->allow_commit();
            return $versionid;
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Arrangement state (#396, Spec 0015 §10): only for quiz, otherwise null - slots,
     * question references, sections and feedback do not run through a
     * dedicated structure API at all for any other activity type.
     *
     * @param \stdClass $cm
     * @return string|null JSON-encoded arrangement state, null for non-quiz.
     */
    private static function build_arrangement_json(\stdClass $cm): ?string {
        if ($cm->modname !== 'quiz') {
            return null;
        }
        return json_encode(
            \local_coursepilot\quiz\arrangement::capture((int) $cm->instance),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * Replicates the get_moduleinfo_data() field object (instance record, tags,
     * availability, gradepass/gradecat/outcomes) without triggering its
     * form side effects.
     *
     * @param \stdClass $cm
     * @return array
     */
    private static function build_moduleinfo(\stdClass $cm): array {
        global $CFG, $DB;

        $cw = $DB->get_record('course_sections', ['id' => $cm->section], 'section', MUST_EXIST);
        $instance = (array) $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);

        $data = array_merge($instance, [
            'coursemodule' => (int) $cm->id,
            'section' => (int) $cw->section,
            'visible' => (int) $cm->visible,
            'visibleoncoursepage' => (int) $cm->visibleoncoursepage,
            'cmidnumber' => (string) $cm->idnumber,
            'groupmode' => (int) groups_get_activity_groupmode($cm),
            'groupingid' => (int) $cm->groupingid,
            'course' => (int) $cm->course,
            'module' => (int) $cm->module,
            'modulename' => (string) $cm->modname,
            'instance' => (int) $cm->instance,
            'completion' => (int) $cm->completion,
            'completionview' => (int) $cm->completionview,
            'completionexpected' => (int) $cm->completionexpected,
            'completionusegrade' => $cm->completiongradeitemnumber === null ? 0 : 1,
            'completionpassgrade' => (int) $cm->completionpassgrade,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber,
            'showdescription' => (int) $cm->showdescription,
            'downloadcontent' => $cm->downloadcontent,
            'lang' => (string) $cm->lang,
            'tags' => \core_tag_tag::get_item_tags_array('core', 'course_modules', $cm->id),
        ]);

        if (!empty($CFG->enableavailability)) {
            // Raw conditions as in get_moduleinfo_data(); profile masking
            // (ADR 0011) is the job of the future view tools, not of
            // storage - "the diff is computed on viewing" (Spec 0015 §10.1).
            $data['availabilityconditionsjson'] = (string) ($cm->availability ?? '');
        }

        self::add_grade_fields($data, $cm);

        return $data;
    }

    /**
     * gradepass/gradecat/outcome fields as in course/modlib.php::get_moduleinfo_data()
     * (lines 848-885), deliberately kept outside of get_module_settings.
     *
     * @param array $data
     * @param \stdClass $cm
     * @return void
     */
    private static function add_grade_fields(array &$data, \stdClass $cm): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');

        $component = 'mod_' . $cm->modname;
        $items = \grade_item::fetch_all([
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance,
            'courseid' => $cm->course,
        ]);

        if (!$items) {
            return;
        }

        foreach ($items as $item) {
            if (!empty($item->outcomeid)) {
                $data['outcome_' . $item->outcomeid] = 1;
            } else if (isset($item->gradepass)) {
                $fieldname = \core_grades\component_gradeitems::get_field_name_for_itemnumber(
                    $component,
                    $item->itemnumber,
                    'gradepass'
                );
                $data[$fieldname] = format_float($item->gradepass, $item->get_decimals());
            }
        }

        $gradecat = [];
        foreach ($items as $item) {
            if (!isset($gradecat[$item->itemnumber])) {
                $gradecat[$item->itemnumber] = $item->categoryid;
            } else if ($gradecat[$item->itemnumber] != $item->categoryid) {
                $gradecat[$item->itemnumber] = false; // Mixed categories - do not set.
            }
        }
        foreach ($gradecat as $itemnumber => $cat) {
            if ($cat !== false) {
                $fieldname = \core_grades\component_gradeitems::get_field_name_for_itemnumber(
                    $component,
                    $itemnumber,
                    'gradecat'
                );
                $data[$fieldname] = $cat;
            }
        }
    }

    /**
     * Capture metadata only for positively allowed teaching-design file areas.
     * Submission and unknown areas never enter history, including gap rows.
     *
     * @param int $versionid
     * @param int $contextid
     * @param string $modname
     * @return void
     */
    private static function capture_files(int $versionid, int $contextid, string $modname): void {
        global $DB;

        $files = $DB->get_records_select('files', 'contextid = ? AND filename <> ?', [$contextid, '.']);
        foreach ($files as $file) {
            if (!file_policy::allows($modname, $file->component, $file->filearea)) {
                continue;
            }
            $fileid = self::dedup_file($file);
            $DB->insert_record('local_coursepilot_cm_version_file', (object) [
                'versionid' => $versionid,
                'fileid' => $fileid,
                'gap' => 0,
            ], false);
        }
    }

    /**
     * Creates file metadata only if this combination of pathnamehash
     * and contenthash is not yet known - dedup across multiple states.
     * Deduplicating on the pathnamehash alone would, for a file whose content
     * changed at the same path (e.g. a replaced intro image), hand the
     * outdated metadata (size, mimetype, contenthash) to newer
     * states - the contenthash must therefore be part of the
     * dedup key.
     *
     * @param \stdClass $file
     * @return int
     */
    private static function dedup_file(\stdClass $file): int {
        global $DB;

        // Share the metadata row lock with retention before deciding whether to reuse it.
        // Moodle transactions use READ COMMITTED: after waiting, the read sees a
        // cleanup deletion and recreates metadata instead of linking a vanished row.
        $DB->execute('UPDATE {local_coursepilot_cm_file} SET id = id
                       WHERE pathnamehash = ? AND contenthash = ?', [$file->pathnamehash, $file->contenthash]);
        $existing = $DB->get_record('local_coursepilot_cm_file', [
            'pathnamehash' => $file->pathnamehash,
            'contenthash' => $file->contenthash,
        ], 'id');
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) $DB->insert_record('local_coursepilot_cm_file', (object) [
            'pathnamehash' => $file->pathnamehash,
            'contenthash' => $file->contenthash,
            'component' => $file->component,
            'filearea' => $file->filearea,
            'itemid' => $file->itemid,
            'filepath' => $file->filepath,
            'filename' => $file->filename,
            'filesize' => $file->filesize,
            'mimetype' => (string) ($file->mimetype ?? ''),
            'timemodified' => $file->timemodified,
        ]);
    }
}
