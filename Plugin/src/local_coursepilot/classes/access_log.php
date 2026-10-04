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

use local_coursepilot\event\tool_access_failed;
use local_coursepilot\event\tool_access_succeeded;

/**
 * Logging of Coursepilot accesses via the Moodle event API,
 * with four configurable levels (#339), so the native log reports
 * do not overflow with many users.
 *
 * Only caller: dispatcher.php, at the two existing response
 * funnel points (error() for all error responses, handle_tools_call() for
 * tool success/failure) - ponytail: no observer/hook mechanism, there
 * is exactly one call site per result type.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class access_log {

    /** @var int No logging. */
    public const LEVEL_NONE = 0;

    /** @var int Writes and errors (#388: constant name stays, meaning shifts). */
    public const LEVEL_ERRORS = 1;

    /** @var int Additionally reads (default). */
    public const LEVEL_READS = 2;

    /** @var int Everything. */
    public const LEVEL_ALL = 3;

    /**
     * The configured log level, with "reads and errors" as the
     * default for a fresh installation (config value never set).
     *
     * @return int
     */
    public static function current_level(): int {
        $level = get_config('local_coursepilot', 'loglevel');
        if ($level === false || $level === '') {
            return self::LEVEL_READS;
        }
        return (int) $level;
    }

    /**
     * Logs a successful tool call, if the level requires it.
     *
     * Ticket #388 (first writing tool) shifts the meaning of the
     * levels: 1 now logs writes (in addition to errors),
     * only 2 also logs reads - otherwise writes would be unlogged in the
     * default "errors only", although they are precisely the ones that
     * should be logged most.
     *
     * @param string $toolname
     * @param bool $iswrite true for a writing tool (tool_registry::is_write()).
     * @param string|null $path File path, if the tool touched one
     *        (spec 0018 §9.2) - e.g. context or material folder path from the
     *        tool response. Null if the tool knows no file path.
     * @param int|null $userid Overrides the logged user (#501):
     *        the workbench download endpoint runs without Moodle login/$USER
     *        (the ticket is the proof of authorization) and must state the
     *        ticket owner explicitly, instead of relying on the
     *        event default $USER->id. Null (default) lets
     *        core\event\base apply its default - unchanged
     *        behavior for every previous caller (dispatcher.php).
     * @return void
     */
    public static function log_success(string $toolname, bool $iswrite = false, ?string $path = null, ?int $userid = null): void {
        $threshold = $iswrite ? self::LEVEL_ERRORS : self::LEVEL_READS;
        if (self::current_level() < $threshold) {
            return;
        }
        $data = ['other' => ['toolname' => $toolname, 'path' => $path]];
        if ($userid !== null) {
            $data['userid'] = $userid;
        }
        tool_access_succeeded::create($data)->trigger();
    }

    /**
     * Logs a failed access, if the level requires it
     * (>= LEVEL_ERRORS).
     *
     * @param string $reason Short, secret-free error description.
     * @param string|null $toolname
     * @param string|null $path File path, if the failed access touched one
     *        and it was still known (#501: a workbench
     *        download ticket may already have lost the path if only
     *        a later check fails - see
     *        {@see \local_coursepilot\workbench_ticket_redemption_failed}).
     *        Null if no path is known.
     * @param int|null $userid See {@see log_success()}.
     * @param string|null $detail Internal diagnostic hint, stored in the event
     *        only at level "Everything".
     *
     * @return void
     */
    public static function log_failure(
        string $reason,
        ?string $toolname = null,
        ?string $path = null,
        ?int $userid = null,
        ?string $detail = null
    ): void {
        if (self::current_level() < self::LEVEL_ERRORS) {
            return;
        }
        $data = ['other' => ['reason' => $reason, 'toolname' => $toolname, 'path' => $path]];
        if (self::current_level() >= self::LEVEL_ALL && $detail !== null) {
            $data['other']['detail'] = $detail;
        }
        if ($userid !== null) {
            $data['userid'] = $userid;
        }
        tool_access_failed::create($data)->trigger();
    }
}
