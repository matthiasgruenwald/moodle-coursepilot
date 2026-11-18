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
 * "reference_limit", "unreadable_folder", "unreadable_file", "locked_file" (personal data, switch off),
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

    /** Highest number of references returned. */
    public const MAX_REFERENCES = 200;

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
        $clock = self::clock($clock);
        $end = $clock() + $timelimit;
        $area = context_files::area();
        $queue = self::initial_queue($folder);
        $state = ['files' => 0, 'references' => [], 'capped' => false, 'reasons' => [], 'unchecked' => []];
        $note = self::recorder($state);
        $folders = 0;
        $scan = ['port' => $port, 'area' => $area, 'moved' => $moved, 'clock' => $clock, 'end' => $end, 'maxfiles' => $maxfiles];

        while ($queue !== []) {
            [$current, $depth, $descend] = array_shift($queue);
            $stop = self::stop_reason($folders, $maxfolders, $clock() >= $end);
            if ($stop !== '') {
                self::note_all($note, $stop, $current, $queue);
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
                self::note_all($note, 'storage_unavailable', $current, $queue);
                break;
            } catch (\moodle_exception $e) {
                $note('unreadable_folder', $current);
                continue;
            }
            self::scan_entries($scan, $entries, [$current, $depth, $descend], $queue, $state, $note);
        }
        return [
            'references' => $state['references'],
            'reasons' => array_keys($state['reasons']),
            'files_checked' => $state['files'],
            'folders_checked' => $folders,
            'unchecked' => $state['unchecked'],
        ];
    }

    /**
     * Builds the function that records a reason and the place it applies to in the state.
     *
     * @param mixed[] $state Result so far, extended by the function.
     * @return \Closure
     */
    private static function recorder(array &$state): \Closure {
        return static function (string $reason, string $place) use (&$state): void {
            $state['reasons'][$reason] = true;
            if (count($state['unchecked']) < self::MAX_UNCHECKED) {
                $state['unchecked'][] = $place === '' ? '(root)' : $place;
            }
        };
    }

    /**
     * The given clock or the real one.
     *
     * @param \Closure|null $clock
     * @return \Closure Returns the current time in seconds.
     */
    private static function clock(?\Closure $clock): \Closure {
        return $clock ?? static fn (): float => microtime(true);
    }

    /**
     * The levels to read first: every level above the moved files (not descending) and their own level.
     *
     * @param string $folder Level the files were moved from, '' for the root.
     * @return array<int, array{0: string, 1: int, 2: bool}> Folder, depth, whether to descend.
     */
    private static function initial_queue(string $folder): array {
        $parts = $folder === '' ? [] : explode('/', $folder);
        $queue = [];
        foreach (array_keys($parts) as $level) {
            $queue[] = [implode('/', array_slice($parts, 0, $level)), 0, false];
        }
        $queue[] = [$folder, 0, true];
        return $queue;
    }

    /**
     * Why the search has to stop before reading the next folder.
     *
     * @param int $folders Folders read so far.
     * @param int $maxfolders Highest number of folders.
     * @param bool $timeup Whether the time limit is reached.
     * @return string "folder_limit", "time_limit" or "" to go on.
     */
    private static function stop_reason(int $folders, int $maxfolders, bool $timeup): string {
        if ($folders >= $maxfolders) {
            return 'folder_limit';
        }
        return $timeup ? 'time_limit' : '';
    }

    /**
     * Notes the current and all queued places as not checked.
     *
     * @param \Closure $note Records a reason and the place it applies to.
     * @param string $reason
     * @param string $current Folder that was about to be read.
     * @param mixed[] $queue Folders still waiting.
     */
    private static function note_all(\Closure $note, string $reason, string $current, array $queue): void {
        foreach (array_merge([[$current]], $queue) as [$left]) {
            $note($reason, $left);
        }
    }

    /**
     * Goes through one listing: queues sub folders and reads index files.
     *
     * @param mixed[] $scan
     *        Fixed parts of the search.
     * @param mixed[] $entries Listing.
     * @param mixed[] $level Folder, depth and whether to descend.
     * @param mixed[] $queue Folders still to read, extended.
     * @param mixed[] $state Result so far, extended.
     * @param \Closure $note Records a reason and the place it applies to.
     */
    private static function scan_entries(
        array $scan,
        array $entries,
        array $level,
        array &$queue,
        array &$state,
        \Closure $note
    ): void {
        [$current, $depth, $descend] = $level;
        foreach ($entries as $entry) {
            $path = $current === '' ? $entry['name'] : $current . '/' . $entry['name'];
            if ($entry['type'] === 'folder') {
                if ($descend) {
                    self::enqueue_folder($entry['name'], $path, $depth, $queue, $note);
                }
            } else if (in_array(strtolower($entry['name']), self::INDEX_NAMES, true)) {
                self::scan_index($scan, $path, $entry['size'], $state, $note);
            }
        }
    }

    /**
     * Queues a sub folder unless it is the archive or too deep.
     *
     * @param string $name Folder name.
     * @param string $path Folder path.
     * @param int $depth Depth of the folder above.
     * @param mixed[] $queue Folders still to read, extended.
     * @param \Closure $note Records a reason and the place it applies to.
     */
    private static function enqueue_folder(string $name, string $path, int $depth, array &$queue, \Closure $note): void {
        if (strtolower($name) === 'archive') {
            return;
        }
        if ($depth + 1 > self::MAX_DEPTH) {
            $note('depth_limit', $path);
            return;
        }
        $queue[] = [$path, $depth + 1, true];
    }

    /**
     * Reads one index file and collects its references, within the limits.
     *
     * @param mixed[] $scan
     * @param string $path Path of the index file.
     * @param int $size Size from the listing.
     * @param mixed[] $state Result so far, extended.
     * @param \Closure $note Records a reason and the place it applies to.
     */
    private static function scan_index(array $scan, string $path, int $size, array &$state, \Closure $note): void {
        $limit = self::index_limit($state, $scan['maxfiles'], $scan['clock']() >= $scan['end']);
        if ($limit !== '') {
            $note($limit, $path);
            return;
        }
        $state['files']++;
        $text = self::read_index($scan['port'], $scan['area'], $path, $size, $note);
        if ($text === null) {
            return;
        }
        array_push($state['references'], ...context_references::find($path, $text, $scan['moved']));
        if (count($state['references']) > self::MAX_REFERENCES) {
            $state['references'] = array_slice($state['references'], 0, self::MAX_REFERENCES);
            $state['capped'] = true;
            $note('reference_limit', $path);
        }
    }

    /**
     * Which limit forbids reading one more index file.
     *
     * @param mixed[] $state Result so far.
     * @param int $maxfiles Highest number of index files.
     * @param bool $timeup Whether the time limit is reached.
     * @return string The reason, "" when the file may be read.
     */
    private static function index_limit(array $state, int $maxfiles, bool $timeup): string {
        if ($state['files'] >= $maxfiles) {
            return 'file_limit';
        }
        if ($timeup) {
            return 'time_limit';
        }
        return $state['capped'] ? 'reference_limit' : '';
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
            // Listed but gone on read: not checked, never silently skipped.
            $note('unreadable_file', $path);
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
