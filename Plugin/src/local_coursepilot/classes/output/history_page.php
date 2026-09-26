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

/**
 * Template data for history.php (#552, Spec 0023 Teil 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot\output;

use local_coursepilot\history\version_history;

/**
 * Prepares display-neutral state for the history.php templates.
 */
final class history_page {

    /**
     * Versionsliste einer Aktivitaet (?cmid=).
     *
     * @param int $cmid
     * @param string $activityname
     * @param bool $canrestore
     * @param \moodle_url $listurl
     * @return array<string, mixed>
     */
    public static function versions_data(
        int $cmid,
        string $activityname,
        bool $canrestore,
        \moodle_url $listurl
    ): array {
        $data = version_history::list_versions($cmid);
        $newest = $data['versions'] ? end($data['versions'])['version'] : null;

        $rows = [];
        foreach ($data['versions'] as $row) {
            $canrestorerow = $canrestore && $row['version'] !== $newest;
            $rows[] = [
                'version' => $row['version'],
                'nutzer' => $row['user'],
                'zeitpunkt' => userdate($row['timestamp']),
                'einzeiler' => $row['summary_line'],
                'canrestore' => $canrestorerow,
                'restoreurl' => $canrestorerow
                    ? (new \moodle_url('/local/coursepilot/history.php', [
                        'cmid' => $cmid,
                        'restore' => $row['version'],
                    ]))->out(false)
                    : null,
            ];
        }

        return [
            'activityname' => format_string($activityname),
            'isquiz' => $data['modname'] === 'quiz',
            'rows' => $rows,
            'hinweisluecken' => $data['gap_notice'],
            'listurl' => $listurl->out(false),
        ];
    }

    /**
     * Aktivitaetenliste eines Kurses (?id=).
     *
     * @param int $courseid
     * @return array<string, mixed>
     */
    public static function activities_data(int $courseid): array {
        $activities = version_history::course_activities($courseid);

        $rows = [];
        foreach ($activities as $activity) {
            $rows[] = [
                'name' => $activity['name'],
                'modname' => $activity['modname'],
                'viewurl' => (new \moodle_url('/local/coursepilot/history.php', [
                    'cmid' => $activity['cmid'],
                ]))->out(false),
            ];
        }

        return ['empty' => $rows === [], 'rows' => $rows];
    }
}
