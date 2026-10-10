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

use local_coursepilot\personal_data_hosts;

/**
 * Storage-location column for the connections overview (Issue #499,
 * Spec #486 §12): per-target state (open, in Moodle, external host) plus
 * markers for unapproved hosts, pending writes, previous locations and
 * broken pointers. Reads only the pointer and database, without network
 * or target-path access. Read-only, without a reset action.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class connection_storage_location {
    /**
     * Describes the connection storage location.
     *
     * @param int $userid
     * @return array{targets: array<string, string>, markers: string[]}
     *         targets: completed display line per target (e.g. "Context area: external: cloud.example.test").
     *         markers: completed marker lines, empty if none apply.
     */
    public static function describe(int $userid): array {
        $decoded = pointer_scan::raw_pointer_for($userid);

        $targets = [];
        $hostblocked = false;
        $defects = [];
        foreach (pointer_scan::TARGETS as $target) {
            $state = pointer_scan::target_state($userid, $decoded, $target);
            $targets[$target] = get_string('storagelocationtarget' . str_replace('_', '', $target), 'local_coursepilot')
                . ': ' . self::state_label($state);

            if ($state['state'] === pointer_scan::STATE_EXTERNAL && $state['host'] !== null && !personal_data_hosts::allowed($state['host'])) {
                $hostblocked = true;
            }
            if ($state['defect'] !== null) {
                $defects[] = $state['defect'];
            }
        }

        $markers = [];
        if ($hostblocked) {
            $markers[] = get_string('storagelocationmarkernotallowed', 'local_coursepilot');
        }
        if (pointer_scan::has_open_pending($userid)) {
            $markers[] = get_string('storagelocationmarkerpending', 'local_coursepilot');
        }
        if (pointer_scan::has_open_previous_location($decoded)) {
            $markers[] = get_string('storagelocationmarkerpreviouslocation', 'local_coursepilot');
        }
        foreach (array_unique($defects) as $defect) {
            $markers[] = get_string('storagelocationmarkerdefect', 'local_coursepilot', self::defect_label($defect));
        }

        return ['targets' => $targets, 'markers' => $markers];
    }

    /**
     * Provides state label.
     *
     * @param array $state Type: array{state:string,host:?string,defect:?string}.
     * @return string
     */
    private static function state_label(array $state): string {
        return match ($state['state']) {
            pointer_scan::STATE_MOODLE => get_string('storagelocationmoodle', 'local_coursepilot'),
            pointer_scan::STATE_EXTERNAL => get_string('storagelocationexternal', 'local_coursepilot', $state['host']),
            pointer_scan::STATE_BROKEN => get_string('storagelocationdefectinvalid', 'local_coursepilot'),
            default => get_string('storagelocationopen', 'local_coursepilot'),
        };
    }

    /**
     * Provides defect label.
     *
     * @param string $defect One of the pointer_scan DEFECT_* values.
     * @return string
     */
    private static function defect_label(string $defect): string {
        return match ($defect) {
            pointer_scan::DEFECT_INSTANCE_MISSING => get_string('storagelocationdefectinstancemissing', 'local_coursepilot'),
            pointer_scan::DEFECT_FOREIGN_INSTANCE => get_string('storagelocationdefectforeigninstance', 'local_coursepilot'),
            pointer_scan::DEFECT_HTTP => get_string('storagelocationdefecthttp', 'local_coursepilot'),
            default => get_string('storagelocationdefectinvalid', 'local_coursepilot'),
        };
    }
}
