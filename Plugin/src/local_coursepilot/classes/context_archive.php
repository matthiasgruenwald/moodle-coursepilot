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
 * Archives direct `.md` files of one folder into `<folder>/archive/` (Spec 0030, ADR 0036): never a
 * journal (ADR 0034), never over an existing target, never deleting. Everything is validated and read
 * before the first move: names and paths under the write rules, journals, the personal-data lock like
 * a write, existing targets. Each move carries the checksum read before ({@see storage_port::move()});
 * a failure after the first move is a partial result, before it an error. An outage records no pending
 * note (ADR 0023 does not apply: a move has no content to supply later). After moving it searches the
 * index files for references ({@see context_reference_search}) and reads every target back.
 *
 * Moodle events (D3): context writes raise no event of their own today (write_context_file,
 * append_context_file, the storage ports); the access log of the dispatcher records every tool call
 * as tool_access_succeeded. A move follows that practice and raises none.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
final class context_archive {
    /** Name of the target folder below the archived folder. */
    public const FOLDER = 'archive';

    /** Highest number of files in one call. */
    public const MAX_FILES = 50;

    /**
     * Archives the files and searches for references to them.
     *
     * @param string $folder Folder relative to the context root, '' for the root.
     * @param string[] $files File names directly inside the folder.
     * @param \Closure|null $clock Clock of the reference search, see {@see context_reference_search::run()}.
     * @return array{moved: array<int, array{from: string, to: string}>, not_moved: string[],
     *         stopped_reason: string, verified: bool, server_enforced: bool, search: array<string, mixed>}
     * @throws \moodle_exception When nothing was moved: invalid input, journal, locked or missing file,
     *         existing target, changed source, unavailable location.
     */
    public static function run(string $folder, array $files, ?\Closure $clock = null): array {
        $area = context_files::area();
        $prefix = storage_anchor::normalise_client_path($area, $folder);
        $prefix = $prefix === '' ? '' : $prefix . '/';
        $paths = self::validated_paths($area, $prefix, $files);

        $location = storage_anchor::effective_location($area);
        if ($location->kind === pointer_location::MOODLE) {
            context_files::require_manage_own_files();
        }
        $port = storage_anchor::port_at($location);
        try {
            $plan = self::plan($port, $area, $paths);
        } catch (storage_failure $e) {
            throw pointer_reader::storage_exception($e);
        }

        [$moved, $failure, $enforced] = self::move_all($port, $area, $plan);
        if ($moved === [] && $failure !== null) {
            throw $failure;
        }
        $unmoved = array_slice($plan, count($moved));
        return [
            'moved' => array_map(static fn (array $m): array => ['from' => $m['from'], 'to' => $m['to']], $moved),
            'not_moved' => array_column($unmoved, 'from'),
            'stopped_reason' => $failure === null ? '' : $failure->getMessage(),
            'verified' => self::read_back_matches($port, $area, $moved),
            'server_enforced' => $enforced,
            'search' => context_reference_search::run(
                $port,
                rtrim($prefix, '/'),
                array_column($moved, 'from'),
                $clock
            ),
        ];
    }

    /**
     * Checks names and paths before anything is read.
     *
     * @param storage_area $area
     * @param string $prefix Folder with trailing slash, or ''.
     * @param string[] $files
     * @return array<int, array{from: string, to: string}>
     * @throws \moodle_exception
     */
    private static function validated_paths(storage_area $area, string $prefix, array $files): array {
        if ($files === []) {
            throw new \moodle_exception('contextarchiveempty', 'local_coursepilot');
        }
        if (count($files) > self::MAX_FILES) {
            throw new \moodle_exception('contextarchivetoomany', 'local_coursepilot', '', self::MAX_FILES);
        }
        $paths = [];
        foreach ($files as $name) {
            if ($name === '' || strpbrk($name, '/\\') !== false) {
                throw new \moodle_exception('contextarchivenotdirect', 'local_coursepilot', '', $name);
            }
            if (isset($paths[$name])) {
                throw new \moodle_exception('contextarchiveduplicate', 'local_coursepilot', '', $name);
            }
            $from = $prefix . $name;
            $to = $prefix . self::FOLDER . '/' . $name;
            storage_anchor::writable_segments($area, $from);
            storage_anchor::writable_segments($area, $to);
            if (context_files::is_journal($from) || context_files::is_journal($to)) {
                throw new \moodle_exception('contextarchivejournal', 'local_coursepilot', '', $from);
            }
            $paths[$name] = ['from' => $from, 'to' => $to];
        }
        return array_values($paths);
    }

    /**
     * Reads every source and target before the first move.
     *
     * @param storage_port $port
     * @param storage_area $area
     * @param mixed[] $paths Entries of from and to path.
     * @return mixed[] The paths with checksum and content of each source.
     * @throws storage_failure Storage is unavailable.
     * @throws \moodle_exception contextarchivemissing, contextfilelocked, contextarchivetargetexists
     */
    private static function plan(storage_port $port, storage_area $area, array $paths): array {
        $plan = [];
        foreach ($paths as $path) {
            $source = $port->read($area, $path['from']);
            if ($source === null) {
                throw new \moodle_exception('contextarchivemissing', 'local_coursepilot', '', $path['from']);
            }
            context_files::guard_existing_locked($source['content'], $path['from']);
            if ($port->read($area, $path['to']) !== null) {
                throw new \moodle_exception('contextarchivetargetexists', 'local_coursepilot', '', $path['to']);
            }
            $plan[] = $path + ['checksum' => $source['checksum'], 'content' => $source['content']];
        }
        return $plan;
    }

    /**
     * Moves in order and stops at the first failure.
     *
     * @param storage_port $port
     * @param storage_area $area
     * @param mixed[] $plan Result of plan().
     * @return mixed[] Moved entries, the failure that stopped it (or null), and whether the server
     *         enforced every source condition.
     */
    private static function move_all(storage_port $port, storage_area $area, array $plan): array {
        $moved = [];
        $enforced = true;
        foreach ($plan as $item) {
            try {
                $result = $port->move($area, $item['from'], $item['to'], $item['checksum']);
            } catch (storage_failure $e) {
                return [$moved, pointer_reader::storage_exception($e), $enforced];
            } catch (storage_conflict_exception $e) {
                return [$moved, new \moodle_exception('contextarchiveconflict', 'local_coursepilot', '', $item['from']), $enforced];
            } catch (\moodle_exception $e) {
                return [$moved, $e, $enforced];
            }
            $enforced = $enforced && $result['server_enforced'];
            $moved[] = $item;
        }
        return [$moved, null, $enforced];
    }

    /**
     * Whether every target reads back with the old bytes and every source is gone (ADR 0034). A read
     * failure is no outage - the files are moved - but an unverified result.
     *
     * @param storage_port $port
     * @param storage_area $area
     * @param mixed[] $moved Moved entries with from, to and the old content.
     * @return bool
     */
    private static function read_back_matches(storage_port $port, storage_area $area, array $moved): bool {
        foreach ($moved as $item) {
            try {
                $target = $port->read($area, $item['to']);
                $source = $port->read($area, $item['from']);
            } catch (storage_failure | \moodle_exception $e) {
                return false;
            }
            if ($target === null || $source !== null || $target['content'] !== $item['content']) {
                return false;
            }
        }
        return true;
    }
}
