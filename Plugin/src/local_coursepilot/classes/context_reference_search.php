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
 * Bounded search for references to moved files in the index files of a level, of all levels above it
 * up to the root and of all levels below it without `archive/` (Spec 0030, ADR 0036 items 3 and 4).
 * Read-only and never throwing: an outage, a limit, an unreadable or locked index file makes the
 * result incomplete with a reason, never "nothing found". Matching is {@see context_references}.
 *
 * Reasons: "time_limit", "folder_limit", "file_limit", "depth_limit", "size_limit",
 * "unreadable_folder", "unreadable_file", "locked_file" (personal data, switch off),
 * "storage_unavailable".
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_reference_search {
    /** Deepest folder level below the searched level. */
    public const MAX_DEPTH = 10;

    /** Highest number of folders read. */
    public const MAX_FOLDERS = 300;

    /** Highest number of index files read. */
    public const MAX_FILES = 100;

    /** Largest index file read, in bytes. */
    public const MAX_BYTES = 1048576;

    /** Seconds before the search stops. */
    public const TIME_LIMIT = 8.0;

    /** Number of unchecked places named in the result. */
    private const MAX_UNCHECKED = 10;

    /** Lower-case names of the index files of a level. */
    private const INDEX_NAMES = ['index.md', 'context.md'];

    /**
     * Searches the index files around one level.
     *
     * @param storage_port $port Adapter of the location the files were moved in.
     * @param string $folder The level the files were moved from, '' for the root.
     * @param string[] $moved Moved files by their old path, relative to the context root.
     * @param \Closure|null $clock Returns the current time in seconds; defaults to microtime(true).
     * @param float $timelimit Seconds before the search stops.
     * @param int $maxfolders Highest number of folders read.
     * @param int $maxfiles Highest number of index files read.
     * @return array{references: array<int, array{file: string, line: int, path: string, moved: string}>,
     *         reasons: string[], files_checked: int, folders_checked: int, unchecked: string[]}
     */
    public static function run(
        storage_port $port,
        string $folder,
        array $moved,
        ?\Closure $clock = null,
        float $timelimit = self::TIME_LIMIT,
        int $maxfolders = self::MAX_FOLDERS,
        int $maxfiles = self::MAX_FILES
    ): array {
        $clock ??= static fn (): float => microtime(true);
        $end = $clock() + $timelimit;
        $area = context_files::area();
        $parts = $folder === '' ? [] : explode('/', $folder);
        $queue = [];
        foreach (array_keys($parts) as $level) {
            $queue[] = [implode('/', array_slice($parts, 0, $level)), 0, false];
        }
        $queue[] = [$folder, 0, true];

        $references = [];
        $reasons = [];
        $unchecked = [];
        $folders = 0;
        $files = 0;
        $note = static function (string $reason, string $place) use (&$reasons, &$unchecked): void {
            $reasons[$reason] = true;
            if (count($unchecked) < self::MAX_UNCHECKED) {
                $unchecked[] = $place === '' ? '(root)' : $place;
            }
        };

        while ($queue !== []) {
            [$current, $depth, $descend] = array_shift($queue);
            $stop = $folders >= $maxfolders ? 'folder_limit' : ($clock() >= $end ? 'time_limit' : '');
            if ($stop !== '') {
                foreach (array_merge([[$current]], $queue) as [$left]) {
                    $note($stop, $left);
                }
                break;
            }
            $folders++;
            try {
                $entries = $port->list($area, $current);
            } catch (storage_failure $e) {
                if (self::is_folder_error($e)) {
                    $note('unreadable_folder', $current);
                    continue;
                }
                foreach (array_merge([[$current]], $queue) as [$left]) {
                    $note('storage_unavailable', $left);
                }
                break;
            } catch (\moodle_exception $e) {
                $note('unreadable_folder', $current);
                continue;
            }
            foreach ($entries as $entry) {
                $path = $current === '' ? $entry['name'] : $current . '/' . $entry['name'];
                if ($entry['type'] === 'folder') {
                    if (!$descend || strtolower($entry['name']) === 'archive') {
                        continue;
                    }
                    if ($depth + 1 > self::MAX_DEPTH) {
                        $note('depth_limit', $path);
                        continue;
                    }
                    $queue[] = [$path, $depth + 1, true];
                    continue;
                }
                if (!in_array(strtolower($entry['name']), self::INDEX_NAMES, true)) {
                    continue;
                }
                if ($files >= $maxfiles) {
                    $note('file_limit', $path);
                    continue;
                }
                if ($clock() >= $end) {
                    $note('time_limit', $path);
                    continue;
                }
                $files++;
                $text = self::read_index($port, $area, $path, $entry['size'], $note);
                if ($text !== null) {
                    array_push($references, ...context_references::find($path, $text, $moved));
                }
            }
        }
        return [
            'references' => $references,
            'reasons' => array_keys($reasons),
            'files_checked' => $files,
            'folders_checked' => $folders,
            'unchecked' => $unchecked,
        ];
    }

    /**
     * Reads one index file; a file that cannot be used is noted and skipped.
     *
     * @param storage_port $port
     * @param storage_area $area
     * @param string $path Path of the index file.
     * @param int $size Size from the listing.
     * @param \Closure $note Records a reason and the place it applies to.
     * @return string|null Content, null when it is missing or not usable.
     */
    private static function read_index(storage_port $port, storage_area $area, string $path, int $size, \Closure $note): ?string {
        if ($size > self::MAX_BYTES) {
            $note('size_limit', $path);
            return null;
        }
        try {
            $file = $port->read($area, $path);
        } catch (storage_failure | \moodle_exception $e) {
            $note('unreadable_file', $path);
            return null;
        }
        if ($file === null) {
            return null;
        }
        if (strlen($file['content']) > self::MAX_BYTES) {
            $note('size_limit', $path);
            return null;
        }
        if (personal_data::is_marked($file['content']) && !personal_data::allowed()) {
            $note('locked_file', $path);
            return null;
        }
        return $file['content'];
    }

    /**
     * Whether a failure names one folder rather than the whole location.
     *
     * @param storage_failure $e
     * @return bool
     */
    private static function is_folder_error(storage_failure $e): bool {
        return in_array($e->errorclass, conflict_scan::FOLDER_ERRORS, true);
    }
}
