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

namespace local_coursepilot\admin;

use local_coursepilot\context_pointer;
use local_coursepilot\pointer_location;
use local_coursepilot\storage_anchor;

/**
 * Read arbitrary users' context pointers and pending notes without network
 * access or the current-$USER dependency of storage_anchor (Issue #499,
 * Spec #486 §12). Supports the four status checks and the storage-location
 * column in the connections overview.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class pointer_scan {

    /** @var string[] The two pointer targets, as in location_selection::TARGETS. */
    public const TARGETS = context_pointer::TARGETS;

    /** @var string State: no context pointer exists. */
    public const STATE_OPEN = 'open';

    /** @var string State: target is in Moodle Private Files. */
    public const STATE_MOODLE = pointer_location::MOODLE;

    /** @var string State: target is in a WebDAV user instance. */
    public const STATE_EXTERNAL = pointer_location::EXTERNAL;

    /** @var string State: structurally broken pointer that cannot be resolved. */
    public const STATE_BROKEN = 'broken';

    /** @var string Defect: referenced instance no longer exists. */
    public const DEFECT_INSTANCE_MISSING = 'instance_missing';

    /** @var string Defect: instance belongs to another user. */
    public const DEFECT_FOREIGN_INSTANCE = 'foreign_instance';

    /** @var string Defect: instance uses HTTP instead of HTTPS+Basic. */
    public const DEFECT_HTTP = 'http';

    /** @var string Defect: invalid pointer structure. */
    public const DEFECT_INVALID = 'invalid';

    /**
     * Users with a nonempty context pointer file, obtained from the files
     * table without reading each file.
     *
     * @return int[]
     */
    public static function userids_with_pointer(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            'SELECT ctx.instanceid AS id
               FROM {files} f
               JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :level
              WHERE f.component = :component AND f.filearea = :filearea
                AND f.filename = :filename AND f.filesize > 0',
            [
                'level' => CONTEXT_USER,
                'component' => storage_anchor::COMPONENT,
                'filearea' => storage_anchor::FILEAREA,
                'filename' => storage_anchor::POINTER_FILENAME,
            ]
        );
        return array_values(array_map(static fn (\stdClass $row): int => (int) $row->id, $rows));
    }

    /**
     * Raw context pointer for any user, or null if the file is missing or
     * contains no valid JSON object. Same tolerance as storage_anchor::read_raw_pointer(),
     * without depending on the current user.
     *
     * @param int $userid
     * @return array|null
     */
    public static function raw_pointer_for(int $userid): ?array {
        $file = get_file_storage()->get_file(
            \context_user::instance($userid)->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            storage_anchor::anchor_root(),
            storage_anchor::POINTER_FILENAME
        );
        if (!$file) {
            return null;
        }
        $decoded = json_decode($file->get_content(), true);
        return (is_array($decoded) && !array_is_list($decoded)) ? context_pointer::normalise($decoded) : null;
    }

    /**
     * Whether any user has an open pending note. Same tolerance as
     * pending_write_notice, without depending on the current user.
     *
     * @param int $userid
     * @return bool
     */
    public static function has_open_pending(int $userid): bool {
        $file = get_file_storage()->get_file(
            \context_user::instance($userid)->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            storage_anchor::anchor_root(),
            storage_anchor::PENDING_FILENAME
        );
        if (!$file) {
            return false;
        }
        $decoded = json_decode($file->get_content(), true);
        return is_array($decoded) && !array_is_list($decoded) && !empty($decoded);
    }

    /**
     * Whether the decoded context pointer has a previous location
     * (previous_location::current()).
     *
     * @param array|null $decoded
     * @return bool
     */
    public static function has_open_previous_location(?array $decoded): bool {
        return is_array($decoded) && is_array($decoded['previous_location'] ?? null);
    }

    /**
     * Whether either target is external, checked directly in "location"
     * without full resolution. The raw field is sufficient for setup check 1
     * (Spec §12). Structurally broken pointers appear as defects in the
     * connections overview instead of counting as external targets.
     *
     * @param array|null $decoded
     * @return bool
     */
    public static function has_external_target(?array $decoded): bool {
        if ($decoded === null) {
            return false;
        }
        foreach (self::TARGETS as $target) {
            $value = $decoded[$target] ?? null;
            if (is_array($value) && ($value['location'] ?? null) === pointer_location::EXTERNAL) {
                return true;
            }
        }
        return false;
    }

    /**
     * Users whose context pointer names at least one external target: the
     * affected-user count for setup check 1 (Spec §12).
     *
     * @return int[]
     */
    public static function userids_with_external_target(): array {
        $result = [];
        foreach (self::userids_with_pointer() as $userid) {
            if (self::has_external_target(self::raw_pointer_for($userid))) {
                $result[] = $userid;
            }
        }
        return $result;
    }

    /**
     * Resolved target state for the connections overview (Spec #486 §12).
     * Structural defects become states rather than thrown errors. Read only
     * the pointer and database, without network or target-path access.
     *
     * @param int $userid
     * @param array|null $decoded Result of {@see raw_pointer_for()}.
     * @param string $target "context_area" or "material_store".
     * @return array{state: string, host: ?string, defect: ?string}
     *         state: "open"|"moodle"|"external"|"broken".
     */
    public static function target_state(int $userid, ?array $decoded, string $target): array {
        if ($decoded === null) {
            return ['state' => self::STATE_OPEN, 'host' => null, 'defect' => null];
        }

        try {
            $location = context_pointer::resolve_target($decoded, $target);
        } catch (\moodle_exception $e) {
            return ['state' => self::STATE_BROKEN, 'host' => null, 'defect' => self::DEFECT_INVALID];
        }

        if ($location->kind === pointer_location::MOODLE) {
            return ['state' => self::STATE_MOODLE, 'host' => null, 'defect' => null];
        }

        $host = (string) ($location->fingerprint['server'] ?? '');
        return ['state' => self::STATE_EXTERNAL, 'host' => $host, 'defect' => self::external_defect($userid, $location)];
    }

    /**
     * Three external-instance defects (Spec §12): missing instance, foreign
     * owner, or HTTP. Same checks 2/3 as webdav_instance::resolve_owned(),
     * but for any user and without current-user enablement/session checks.
     * Read only repository_instances and repository_instance_config.
     *
     * @param int $userid
     * @param pointer_location $location
     * @return string|null "instance_missing"|"foreign_instance"|"http"|null (no defect).
     */
    private static function external_defect(int $userid, pointer_location $location): ?string {
        global $DB;

        $record = $DB->get_record_sql(
            'SELECT ri.id, ri.contextid
               FROM {repository_instances} ri
               JOIN {repository} r ON r.id = ri.typeid
              WHERE ri.id = :id AND r.type = :type',
            ['id' => $location->instanceid, 'type' => 'webdav']
        );
        if (!$record) {
            return self::DEFECT_INSTANCE_MISSING;
        }
        if ((int) $record->contextid !== \context_user::instance($userid)->id) {
            return self::DEFECT_FOREIGN_INSTANCE;
        }
        $webdavtype = $DB->get_field(
            'repository_instance_config',
            'value',
            ['instanceid' => $location->instanceid, 'name' => 'webdav_type']
        );
        return ((int) $webdavtype === 1) ? null : self::DEFECT_HTTP;
    }
}
