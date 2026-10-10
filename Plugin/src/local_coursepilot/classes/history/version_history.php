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
 * Read interface of the change history (#394, Spec 0015 §10.6):
 * list_activity_versions (one-line summary per version against its predecessor) and
 * compare_activity_versions (full diff of two freely chosen states).
 * Both compute server-side from the full states that
 * {@see version_writer} creates - there is no stored diff chain.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class version_history {
    /**
     * All versions of an activity, ascending, each with a one-line summary
     * against its predecessor (Spec 0015 §10.6, acceptance criterion 1+2).
     *
     * @param int $cmid
     * @param string $lang UI language; tool callers keep the English default.
     * @return array{cmid: int, modname: string, versions: array, gap_notice: string}
     */
    public static function list_versions(int $cmid, string $lang = 'en'): array {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $records = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cmid], 'version ASC'));

        $rows = [];
        $previous = null;
        foreach ($records as $record) {
            $rows[] = self::describe_version($record, $previous, $lang);
            $previous = $record;
        }

        return [
            'cmid' => $cmid,
            'modname' => (string) $cm->modname,
            'versions' => $rows,
            'gap_notice' => get_string_manager()->get_string('historygapnotice', 'local_coursepilot', null, $lang),
        ];
    }

    /**
     * Full diff of two freely chosen states, not only adjacent ones
     * (Spec 0015 §10.6, acceptance criterion 3).
     *
     * @param int $cmid
     * @param int $fromversion
     * @param int $toversion
     * @return array
     * @throws \moodle_exception versionnotfound
     */
    public static function compare(int $cmid, int $fromversion, int $toversion): array {
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $fromstate = self::load_version($cmid, $fromversion);
        $tostate = self::load_version($cmid, $toversion);

        return [
            'cmid' => $cmid,
            'modname' => (string) $cm->modname,
            'before' => self::describe_meta($fromstate),
            'after' => self::describe_meta($tostate),
            'changes' => self::diff_fields(self::public_state($fromstate), self::public_state($tostate)),
            'files' => self::diff_files((int) $fromstate->id, (int) $tostate->id),
            'gap_notice' => get_string_manager()->get_string('historygapnotice', 'local_coursepilot', null, 'en'),
        ];
    }

    /**
     * Full target state of a version as a diffable array - the same
     * merge (moduleinfo_json over coursemodule_json) as for
     * compare(), public for {@see \local_coursepilot\external\restore_activity_version}
     * (#395: "no separate write mechanism" - restore builds a patch for
     * update_module_settings/set_completion from it, just as compare()
     * builds a diff from it).
     *
     * @param int $cmid
     * @param int $version
     * @return array
     * @throws \moodle_exception versionnotfound
     */
    public static function state_at(int $cmid, int $version): array {
        return self::state(self::load_version($cmid, $version));
    }

    /**
     * File metadata of a state - for the file restoration step
     * of {@see \local_coursepilot\external\restore_activity_version} (Spec
     * 0018 §9.1, issue #432): which files (component/filearea/filename/
     * contenthash) belonged to this state, and can the respective row be
     * written back (gap=0, see {@see version_writer::capture_files()})?
     *
     * @param int $cmid
     * @param int $version
     * @return \stdClass[] Per row: component, filearea, filename, contenthash, gap.
     * @throws \moodle_exception versionnotfound
     */
    public static function files_at(int $cmid, int $version): array {
        $record = self::load_version($cmid, $version);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        return self::allowed_files((int) $record->id, (string) $cm->modname);
    }

    /**
     * Read old rows through the same allowlist used by capture; also used by the privacy export.
     *
     * @param int $versionid
     * @param string $modname
     * @return \stdClass[] Per row: id, fileid, component, filearea, filename, contenthash, gap.
     */
    public static function allowed_files(int $versionid, string $modname): array {
        global $DB;
        $files = $DB->get_records_sql(
            'SELECT vf.id, vf.fileid, f.component, f.filearea, f.filename, f.contenthash, vf.gap
               FROM {local_coursepilot_cm_version_file} vf
               JOIN {local_coursepilot_cm_file} f ON f.id = vf.fileid
              WHERE vf.versionid = ?',
            [$versionid]
        );
        return array_values(array_filter($files, static fn(\stdClass $file): bool =>
            file_policy::allows($modname, $file->component, $file->filearea)));
    }

    /**
     * Arrangement state (#396, Spec 0015 §10) of a state - null for
     * non-quiz activities and for states created before #396
     * (no arrangement_json recorded). Public for
     * {@see \local_coursepilot\external\restore_activity_version}, analogous to
     * {@see self::state_at()}.
     *
     * @param int $cmid
     * @param int $version
     * @return array|null
     * @throws \moodle_exception versionnotfound
     */
    public static function arrangement_at(int $cmid, int $version): ?array {
        $record = self::load_version($cmid, $version);
        if ($record->arrangement_json === null) {
            return null;
        }
        return json_decode($record->arrangement_json, true);
    }

    /**
     * Activities of a course that have at least one captured history
     * state - basis of the activity list on history.php (#397).
     * Pure existence query on the own table, followed by the normal
     * Moodle route for the activity data (get_fast_modinfo) - this keeps
     * history storage separate from the interface (Spec 0015
     * §10.6, acceptance criterion 7).
     *
     * @param int $courseid
     * @return array<int, array{cmid: int, name: string, modname: string}>
     */
    public static function course_activities(int $courseid): array {
        global $DB;

        $cmids = array_keys($DB->get_records_sql(
            'SELECT DISTINCT cmid FROM {local_coursepilot_cm_version} WHERE courseid = ?',
            [$courseid]
        ));
        if (!$cmids) {
            return [];
        }

        $modinfo = get_fast_modinfo($courseid);
        $activities = [];
        foreach ($cmids as $cmid) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\moodle_exception $e) {
                // Activity deleted in the meantime - history rows remain,
                // but there is nothing left to display (#387 course cascade
                // only applies to the whole course, not to single activities).
                continue;
            }
            $activities[] = ['cmid' => (int) $cmid, 'name' => $cm->name, 'modname' => $cm->modname];
        }
        usort($activities, static fn(array $a, array $b): int => $a['name'] <=> $b['name']);
        return $activities;
    }

    /**
     * Loads version.
     *
     * @param int $cmid
     * @param int $version
     * @return \stdClass
     * @throws \moodle_exception versionnotfound
     */
    private static function load_version(int $cmid, int $version): \stdClass {
        global $DB;

        $record = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cmid, 'version' => $version]);
        if (!$record) {
            throw new \moodle_exception('versionnotfound', 'local_coursepilot', '', [
                'cmid' => $cmid,
                'version' => $version,
            ]);
        }
        return $record;
    }

    /**
     * Describes version.
     *
     * @param \stdClass $record
     * @param \stdClass|null $previous
     * @param string $lang
     * @return array
     */
    private static function describe_version(\stdClass $record, ?\stdClass $previous, string $lang): array {
        $meta = self::describe_meta($record, $lang);
        $meta['summary_line'] = self::summary_line($previous, $record, $meta, $lang);
        return $meta;
    }

    /**
     * Metadata of a state without the one-line summary - basis for both
     * list_versions and the before/after blocks of compare().
     *
     * @param \stdClass $record
     * @param string $lang
     * @return array{version: int, source: string, discovered: bool, source_cmid: int|null, userid: int, user: string, timestamp: int}
     */
    private static function describe_meta(\stdClass $record, string $lang = 'en'): array {
        $source = version_source::from_record($record);
        return [
            'version' => (int) $record->version,
            'source' => $source->key,
            'discovered' => $source->is_discovered(),
            'source_cmid' => $source->refcmid,
            'userid' => (int) $record->userid,
            'user' => self::fullname((int) $record->userid, $lang),
            'timestamp' => (int) $record->timecreated,
        ];
    }

    /**
     * Provides summary line.
     *
     * @param \stdClass|null $previous
     * @param \stdClass $record
     * @param array $meta
     * @param string $lang
     * @return string
     */
    private static function summary_line(?\stdClass $previous, \stdClass $record, array $meta, string $lang): string {
        // Numeric dates avoid locale-dependent month/day names in the English tool contract.
        $meta['time'] = userdate($meta['timestamp'], '%Y-%m-%d %H:%M', 99, false, false);

        $source = version_source::from_record($record);
        if ($previous === null || $source->is_marker()) {
            $meta['source'] = $source->label($lang);
            return get_string_manager()->get_string('historysummarymarker', 'local_coursepilot', (object) $meta, $lang);
        }

        $meta['change'] = self::summarize_change($previous, $record, $lang);
        return get_string_manager()->get_string('historysummarychange', 'local_coursepilot', (object) $meta, $lang);
    }

    /**
     * Localized summary of field and file changes compared with the predecessor.
     *
     * @param \stdClass $before
     * @param \stdClass $after
     * @param string $lang
     * @return string
     */
    private static function summarize_change(\stdClass $before, \stdClass $after, string $lang): string {
        $fields = self::changed_fields(self::public_state($before), self::public_state($after));
        $filechanges = self::diff_files((int) $before->id, (int) $after->id);

        $parts = [];
        if ($fields) {
            $fieldnames = implode(', ', array_slice($fields, 0, 4));
            if (count($fields) > 4) {
                $fieldnames .= get_string_manager()->get_string(
                    'historymorefields',
                    'local_coursepilot',
                    count($fields) - 4,
                    $lang
                );
            }
            $parts[] = get_string_manager()->get_string('historyfieldschanged', 'local_coursepilot', $fieldnames, $lang);
        }

        $added = count(array_filter($filechanges, static fn(array $c): bool => $c['change_type'] === 'added'));
        $removed = count($filechanges) - $added;
        if ($added) {
            $parts[] = get_string_manager()->get_string(
                $added === 1 ? 'historyfileadded' : 'historyfilesadded',
                'local_coursepilot',
                $added,
                $lang
            );
        }
        if ($removed) {
            $parts[] = get_string_manager()->get_string(
                $removed === 1 ? 'historyfileremoved' : 'historyfilesremoved',
                'local_coursepilot',
                $removed,
                $lang
            );
        }

        return $parts ? implode(', ', $parts)
            : get_string_manager()->get_string('historynochange', 'local_coursepilot', null, $lang);
    }

    /**
     * moduleinfo_json and coursemodule_json merged into one diffable state -
     * for overlapping fields moduleinfo_json wins, being the richer
     * source (tags, availability, instance fields).
     *
     * @param \stdClass $record
     * @return array
     */
    private static function state(\stdClass $record): array {
        $coursemodule = json_decode($record->coursemodule_json, true) ?: [];
        $moduleinfo = json_decode($record->moduleinfo_json, true) ?: [];
        return array_merge($coursemodule, $moduleinfo);
    }

    /**
     * Safe comparison projection; raw state_at remains exclusively for native restoration.
     *
     * @param \stdClass $record The record.
     */
    private static function public_state(\stdClass $record): array {
        $state = self::state($record);
        foreach (['availability', 'availabilityconditionsjson'] as $field) {
            if (array_key_exists($field, $state)) {
                $state[$field] = \local_coursepilot\availability_privacy::sanitize((string) ($state[$field] ?? ''));
            }
        }
        return $state;
    }

    /**
     * Names of fields whose value differs between $before and $after -
     * compared loosely (like {@see \local_coursepilot\external\update_module_settings::diff_and_side_effects()}),
     * so that equivalent but differently encoded values do not falsely
     * appear as a change.
     *
     * @param array $before
     * @param array $after
     * @return string[] sorted
     */
    private static function changed_fields(array $before, array $after): array {
        $fields = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $field) {
            if (($before[$field] ?? null) != ($after[$field] ?? null)) {
                $fields[] = $field;
            }
        }
        sort($fields);
        return $fields;
    }

    /**
     * Complete field diff with before/after value per changed field,
     * for compare_activity_versions.
     *
     * @param array $before
     * @param array $after
     * @return array
     */
    private static function diff_fields(array $before, array $after): array {
        $changes = [];
        foreach (self::changed_fields($before, $after) as $field) {
            $changes[] = [
                'field' => $field,
                'before_json' => json_encode($before[$field] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'after_json' => json_encode($after[$field] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }
        return $changes;
    }

    /**
     * File id => file name of the files belonging to a state.
     *
     * @param int $versionid
     * @return array<int, string>
     */
    private static function file_map(int $versionid): array {
        global $DB;

        $cmid = $DB->get_field('local_coursepilot_cm_version', 'cmid', ['id' => $versionid], MUST_EXIST);
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $map = [];
        foreach (self::allowed_files($versionid, (string) $cm->modname) as $file) {
            $map[$file->fileid] = $file->filename;
        }
        return $map;
    }

    /**
     * Files added or dropped between two states - a file whose content
     * changed at the same path appears as one removal and one addition
     * (deduplicated via the contenthash, see
     * {@see version_writer::dedup_file()}).
     *
     * @param int $beforeversionid
     * @param int $afterversionid
     * @return array
     */
    private static function diff_files(int $beforeversionid, int $afterversionid): array {
        $before = self::file_map($beforeversionid);
        $after = self::file_map($afterversionid);

        $changes = [];
        foreach ($before as $fileid => $filename) {
            if (!array_key_exists($fileid, $after)) {
                $changes[] = ['change_type' => 'removed', 'filename' => $filename];
            }
        }
        foreach ($after as $fileid => $filename) {
            if (!array_key_exists($fileid, $before)) {
                $changes[] = ['change_type' => 'added', 'filename' => $filename];
            }
        }
        return $changes;
    }

    /**
     * Provides fullname.
     *
     * @param int $userid
     * @param string $lang
     * @return string
     */
    private static function fullname(int $userid, string $lang): string {
        global $DB;

        // Full row instead of a narrow field selection: fullname() complains
        // via debugging() when e.g. the alternate name fields are missing,
        // even if they remain unused for display.
        $user = $DB->get_record('user', ['id' => $userid]);
        return $user ? fullname($user)
            : get_string_manager()->get_string('historyunknownuser', 'local_coursepilot', $userid, $lang);
    }
}
