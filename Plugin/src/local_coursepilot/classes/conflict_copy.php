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
 * Recognises sync-client conflict copies by file name (Spec 0030, ADR 0037 item 4).
 * Pure: names only, no storage. Ported from the moocp-contextmanager "Konfliktkopie";
 * copies are only reported, never changed.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class conflict_copy {
    /**
     * Name patterns; group 1 is the stem, optional group 2 the extension of the original.
     */
    private const PATTERNS = [
        // Syncthing: name.sync-conflict-YYYYMMDD-HHMMSS[-DEVICE].ext.
        '/^(.+)\.sync-conflict-\d{8}-\d{6}(?:-[A-Za-z0-9]+)?(\.[^.]+)?$/Du',
        // Nextcloud desktop, older form: name_conflict-[USER-]YYYYMMDD-HHMMSS.ext.
        '/^(.+)_conflict-(?:.+-)?\d{8}-\d{6}(\.[^.]+)?$/Du',
        // Dropbox and newer Nextcloud: name (conflicted copy [USER] YYYY-MM-DD HHMMSS).ext.
        '/^(.+) \([^()]*conflicted copy[^()]*\)(\.[^.]+)?$/Diu',
        // Seafile: name (SFConflict User Time).ext.
        '/^(.+) \(SFConflict [^()]*\)(\.[^.]+)?$/Du',
    ];

    /**
     * Name of the original if $name is a conflict copy.
     *
     * @param string $name File or folder name (no path).
     * @return string|null
     */
    public static function original_name(string $name): ?string {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $name, $m) === 1) {
                return $m[1] . ($m[2] ?? '');
            }
        }
        return null;
    }

    /**
     * Conflict copies among the entries of one folder (subfolders are not entered).
     *
     * @param mixed[] $entries Entries with a "name" key, as listed by {@see context_area::list()}.
     * @param string $folder Folder relative to the context root, empty for the root.
     * @return array<int, array{copy: string, original: string, original_exists: bool}>
     *         Paths relative to the context root.
     */
    public static function in_entries(array $entries, string $folder = ''): array {
        $names = array_flip(array_column($entries, 'name'));
        $join = static fn (string $name): string => $folder === '' ? $name : $folder . '/' . $name;
        $copies = [];
        foreach ($entries as $entry) {
            $original = self::original_name($entry['name']);
            if ($original !== null) {
                $copies[] = [
                    'copy' => $join($entry['name']),
                    'original' => $join($original),
                    'original_exists' => isset($names[$original]),
                ];
            }
        }
        return $copies;
    }
}
