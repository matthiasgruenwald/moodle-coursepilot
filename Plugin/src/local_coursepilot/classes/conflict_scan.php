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
 * Whole-area search for sync-client conflict copies (Spec 0030, ADR 0037 item 4), consumed by
 * the session start. Breadth first over {@see storage_port::list()}, bounded by folders, depth
 * and time. Never changes anything and never throws: an unreadable folder is named and skipped,
 * an outage ends the search with a reason, keeping what was found; "not checked" only when no folder could be listed.
 *
 * Reasons: "" (complete), "time_limit", "folder_limit", "depth_limit", "unreadable_folder",
 * "storage_unavailable".
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class conflict_scan {
    /** Highest number of folders read. */
    public const MAX_FOLDERS = 500;

    /** Deepest folder level read (root = 0). */
    public const MAX_DEPTH = 10;

    /** Seconds before the search stops. */
    public const TIME_LIMIT = 8.0;

    /** Storage error classes that name one folder, not the whole location. */
    public const FOLDER_ERRORS = [
        \local_coursepilot\webdav\webdav_error::NOT_FOUND,
        \local_coursepilot\webdav\webdav_error::BLOCKED,
        \local_coursepilot\webdav\webdav_error::CONFLICT,
    ];

    /**
     * Searches the whole area.
     *
     * @param storage_port $port Adapter of the current location.
     * @param float $timelimit Seconds before the search stops.
     * @param \Closure|null $clock Returns the current time in seconds; defaults to microtime(true).
     * @return array{checked: bool, complete: bool, reason: string,
     *         copies: array<int, array{copy: string, original: string, original_exists: bool}>,
     *         unreadable: string[], folders_checked: int, folders_waiting: int}
     *         Paths relative to the context root.
     */
    public static function run(storage_port $port, float $timelimit = self::TIME_LIMIT, ?\Closure $clock = null): array {
        $clock ??= static fn (): float => microtime(true);
        $end = $clock() + $timelimit;
        $area = context_files::area();
        $queue = [['', 0]];
        $copies = [];
        $unreadable = [];
        $reason = '';
        $checked = 0;
        while ($queue !== []) {
            $stop = self::stop_reason($checked, $clock() >= $end);
            if ($stop !== '') {
                $reason = $stop;
                break;
            }
            [$folder, $depth] = array_shift($queue);
            $checked++;
            try {
                $entries = $port->list($area, $folder);
            } catch (storage_failure $e) {
                if (!in_array($e->errorclass, self::FOLDER_ERRORS, true)) {
                    return self::outage($copies, $unreadable, $checked, count($queue));
                }
                $unreadable[] = $folder;
                continue;
            }
            array_push($copies, ...conflict_copy::in_entries($entries, $folder));
            $deeper = self::enqueue_folders($entries, $folder, $depth, $queue);
            $reason = $reason === '' ? $deeper : $reason;
        }
        return self::result(true, self::final_reason($reason, $unreadable), $copies, $unreadable, $checked, count($queue));
    }

    /**
     * Why the search has to stop before reading the next folder.
     *
     * @param int $checked Folders read so far.
     * @param bool $timeup Whether the time limit is reached.
     * @return string "folder_limit", "time_limit" or "" to go on.
     */
    private static function stop_reason(int $checked, bool $timeup): string {
        if ($checked >= self::MAX_FOLDERS) {
            return 'folder_limit';
        }
        return $timeup ? 'time_limit' : '';
    }

    /**
     * Queues the sub folders of a listing.
     *
     * @param mixed[] $entries Listing of the folder.
     * @param string $folder The folder listed.
     * @param int $depth Its depth.
     * @param mixed[] $queue Folders still to read, extended.
     * @return string "depth_limit" when a folder was too deep to queue, otherwise "".
     */
    private static function enqueue_folders(array $entries, string $folder, int $depth, array &$queue): string {
        $reason = '';
        foreach ($entries as $entry) {
            if ($entry['type'] !== 'folder') {
                continue;
            }
            if ($depth + 1 > self::MAX_DEPTH) {
                $reason = 'depth_limit';
                continue;
            }
            $queue[] = [$folder === '' ? $entry['name'] : $folder . '/' . $entry['name'], $depth + 1];
        }
        return $reason;
    }

    /**
     * The reason of a search that ran to its end.
     *
     * @param string $reason Reason so far.
     * @param string[] $unreadable Folders that could not be read.
     * @return string
     */
    private static function final_reason(string $reason, array $unreadable): string {
        return ($reason === '' && $unreadable !== []) ? 'unreadable_folder' : $reason;
    }

    /**
     * Result of a search ended by an outage; keeps what was found, "not checked" only when no folder
     * could be listed at all.
     *
     * @param mixed[] $copies
     * @param string[] $unreadable
     * @param int $checked Folders counted including the failed one.
     * @param int $waiting Folders still queued, without the failed one.
     * @return array{checked: bool, complete: bool, reason: string,
     *         copies: array<int, array{copy: string, original: string, original_exists: bool}>,
     *         unreadable: string[], folders_checked: int, folders_waiting: int}
     */
    private static function outage(array $copies, array $unreadable, int $checked, int $waiting): array {
        $listed = $checked - 1 - count($unreadable);
        return self::result($listed > 0, 'storage_unavailable', $copies, $unreadable, $checked - 1, $waiting + 1);
    }

    /**
     * Searches the whole area at the current location; an unreachable location is "not checked".
     *
     * @param \Closure|null $clock See {@see run()}.
     * @return array{checked: bool, complete: bool, reason: string,
     *         copies: array<int, array{copy: string, original: string, original_exists: bool}>,
     *         unreadable: string[], folders_checked: int, folders_waiting: int} See {@see run()}.
     */
    public static function run_current(?\Closure $clock = null): array {
        try {
            $port = storage_anchor::port(context_files::area());
        } catch (\moodle_exception $e) {
            return self::result(false, 'storage_unavailable', [], [], 0, 0);
        }
        return self::run($port, self::TIME_LIMIT, $clock);
    }

    /**
     * Builds the result.
     *
     * @param bool $checked
     * @param string $reason
     * @param mixed[] $copies
     * @param string[] $unreadable
     * @param int $folderschecked
     * @param int $folderswaiting
     * @return array{checked: bool, complete: bool, reason: string,
     *         copies: array<int, array{copy: string, original: string, original_exists: bool}>,
     *         unreadable: string[], folders_checked: int, folders_waiting: int}
     */
    private static function result(
        bool $checked,
        string $reason,
        array $copies,
        array $unreadable,
        int $folderschecked,
        int $folderswaiting
    ): array {
        return [
            'checked' => $checked,
            'complete' => $checked && $reason === '',
            'reason' => $reason,
            'copies' => $copies,
            'unreadable' => $unreadable,
            'folders_checked' => $folderschecked,
            'folders_waiting' => $folderswaiting,
        ];
    }
}
