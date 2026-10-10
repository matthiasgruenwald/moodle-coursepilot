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

/**
 * Reference finder (Spec 0026 module 6, ADR 0028): who points at a course module.
 * Read-only: nothing is resolved or changed. Text links are out of scope.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class cm_references {
    /** Reference kind: availability condition on another activity. */
    public const KIND_ACTIVITY_AVAILABILITY = 'activity_availability';
    /** Reference kind: availability condition on a section. */
    public const KIND_SECTION_AVAILABILITY = 'section_availability';
    /** Reference kind: course completion criterion on the activity. */
    public const KIND_COURSE_COMPLETION = 'course_completion';

    /**
     * Provides references to.
     *
     * @param int $cmid
     * @return array<int, array{kind: string, location_id: int, location: string}> location_id is the
     *         cmid (activity), section id (section) or course id (course completion).
     */
    public static function references_to(int $cmid): array {
        global $DB;

        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course', MUST_EXIST);
        $found = [];

        $activities = $DB->get_records_select(
            'course_modules',
            'course = ? AND id <> ? AND availability IS NOT NULL',
            [$cm->course, $cmid],
            'id',
            'id, availability'
        );
        foreach ($activities as $other) {
            if (self::tree_points_at((string) $other->availability, $cmid)) {
                $found[] = [
                    'kind' => self::KIND_ACTIVITY_AVAILABILITY,
                    'location_id' => (int) $other->id,
                    'location' => 'cmid ' . $other->id,
                ];
            }
        }

        $sections = $DB->get_records_select(
            'course_sections',
            'course = ? AND availability IS NOT NULL',
            [$cm->course],
            'section',
            'id, section, availability'
        );
        foreach ($sections as $section) {
            if (self::tree_points_at((string) $section->availability, $cmid)) {
                $found[] = [
                    'kind' => self::KIND_SECTION_AVAILABILITY,
                    'location_id' => (int) $section->id,
                    'location' => 'section ' . $section->section,
                ];
            }
        }

        $hascriterion = $DB->record_exists('course_completion_criteria', [
            'course' => $cm->course,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'moduleinstance' => $cmid,
        ]);
        if ($hascriterion) {
            $found[] = [
                'kind' => self::KIND_COURSE_COMPLETION,
                'location_id' => (int) $cm->course,
                'location' => 'course ' . $cm->course,
            ];
        }

        return $found;
    }

    /**
     * Shared tree walk: every completion condition node of an availability tree
     * (nested groups included), in document order.
     *
     * @param mixed[] $tree Decoded availability JSON.
     * @return mixed[][] Condition nodes with type "completion".
     */
    private static function completion_conditions(array $tree): array {
        $nodes = [];
        if (($tree['type'] ?? null) === 'completion') {
            $nodes[] = $tree;
        }
        foreach ($tree['c'] ?? [] as $child) {
            if (is_array($child)) {
                $nodes = array_merge($nodes, self::completion_conditions($child));
            }
        }
        return $nodes;
    }

    /**
     * Every completion condition of a tree as "cm:e".
     *
     * @param mixed[] $tree
     * @return string[]
     */
    public static function completion_pairs(array $tree): array {
        $pairs = [];
        foreach (self::completion_conditions($tree) as $node) {
            if (isset($node['cm'], $node['e'])) {
                $pairs[] = (int) $node['cm'] . ':' . (int) $node['e'];
            }
        }
        return $pairs;
    }

    /**
     * Completion condition whose cm Moodle could not translate on restore (cm 0).
     *
     * @param mixed[] $node
     * @return bool
     */
    public static function is_dangling_completion(array $node): bool {
        return ($node['type'] ?? null) === 'completion' && (int) ($node['cm'] ?? -1) === 0;
    }

    /**
     * Provides tree points at.
     *
     * @param string $availability The availability.
     * @param int $cmid The cmid.
     * @return bool
     */
    private static function tree_points_at(string $availability, int $cmid): bool {
        $tree = json_decode($availability, true);
        if (!is_array($tree)) {
            return false;
        }
        foreach (self::completion_conditions($tree) as $node) {
            if ((int) ($node['cm'] ?? -1) === $cmid) {
                return true;
            }
        }
        return false;
    }
}
