<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot;

/**
 * Remote access grant (#579, ADR 0026): a user may connect an AI chat when
 * they are a member of a system cohort the administration selected, or hold
 * `local/coursepilot:useremote` through an existing system role. Coursepilot
 * never creates a cohort or a role. Checked on every call, so removing a
 * member also blocks connections that already exist.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class remote_access {
    /** @var string Capability for the role-based grant. */
    public const CAPABILITY = 'local/coursepilot:useremote';

    /**
     * Tells whether the remote access is granted.
     *
     * @param int|null $userid Defaults to the current user.
     * @return bool
     */
    public static function is_granted(?int $userid = null): bool {
        global $DB, $USER;

        $userid = $userid ?? (int) $USER->id;
        if (has_capability(self::CAPABILITY, \context_system::instance(), $userid)) {
            return true;
        }
        $cohortids = self::selected_cohort_ids();
        if (!$cohortids || !$userid) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $params['contextid'] = \context_system::instance()->id;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {cohort_members} cm
               JOIN {cohort} c ON c.id = cm.cohortid
              WHERE cm.userid = :userid AND c.contextid = :contextid AND c.id $insql",
            $params
        );
    }

    /**
     * For pages: throws when the current user has no remote access grant.
     *
     * @throws \moodle_exception
     */
    public static function require_granted(): void {
        if (!self::is_granted()) {
            throw new \moodle_exception('remoteaccessnotgranted', 'local_coursepilot');
        }
    }

    /**
     * Existing selected system cohorts with their member count, for the
     * settings page (Moodle's capability overview does not show this grant).
     *
     * @return array<int, \stdClass> cohort id => {id, name, members}
     */
    public static function selected_cohort_member_counts(): array {
        global $DB;

        $cohortids = self::selected_cohort_ids();
        if (!$cohortids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED);
        $params['contextid'] = \context_system::instance()->id;
        $records = $DB->get_records_sql(
            "SELECT c.id, c.name, COUNT(cm.id) AS members
               FROM {cohort} c
          LEFT JOIN {cohort_members} cm ON cm.cohortid = c.id
              WHERE c.contextid = :contextid AND c.id $insql
           GROUP BY c.id, c.name",
            $params
        );
        $counts = [];
        foreach ($records as $record) {
            $counts[(int) $record->id] = (object) [
                'id' => (int) $record->id,
                'name' => $record->name,
                'members' => (int) $record->members,
            ];
        }
        return $counts;
    }

    /**
     * Selected ids that no longer point to a system cohort (deleted or moved).
     *
     * @return int[]
     */
    public static function missing_cohort_ids(): array {
        return array_values(array_diff(self::selected_cohort_ids(), array_keys(self::selected_cohort_member_counts())));
    }

    /**
     * Provides selected cohort ids.
     *
     * @return int[]
     */
    private static function selected_cohort_ids(): array {
        $raw = (string) get_config('local_coursepilot', 'remoteaccesscohorts');
        return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)))));
    }
}
