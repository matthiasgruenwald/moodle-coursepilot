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

use local_coursepilot\tests\tree_storage_port;
use local_coursepilot\webdav\webdav_error;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Bounded reference search of the archive tool (#785, ADR 0036 items 3 and 4) against a fake port
 * and an injected clock.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(context_reference_search::class)]
final class context_reference_search_test extends \advanced_testcase {
    /** @var string[] The moved file of most tests. */
    private const MOVED = ['a/plan.md'];

    /**
     * A storage failure of the given class.
     *
     * @param string $class
     * @return storage_failure
     */
    private function failure(string $class): storage_failure {
        return new storage_failure($class, new webdav_error($class));
    }

    /**
     * Files of the "a" level with index files around it.
     *
     * @return array{0: mixed[], 1: mixed[]} Tree and contents.
     */
    private function sample(): array {
        return [
            ['' => ['index.md', 'a/', 'b/'], 'a' => ['index.md', 'plan.md', 'sub/', 'archive/'], 'a/sub' => ['INDEX.MD'],
                'a/archive' => ['index.md'], 'b' => ['index.md']],
            ['index.md' => 'x', 'a/index.md' => 'plan.md', 'a/sub/INDEX.MD' => '[p](../plan.md)',
                'a/archive/index.md' => 'plan.md', 'b/index.md' => '[p](a/plan.md)'],
        ];
    }

    public function test_searches_the_level_above_and_below_but_not_archive_or_sibling_levels(): void {
        [$tree, $contents] = $this->sample();
        // The root index points to a/plan.md from above; the sibling "b" is not on the path.
        $contents['index.md'] = '- [Plan](a/plan.md)';

        $result = context_reference_search::run(new tree_storage_port($tree, null, $contents), 'a', self::MOVED);

        $this->assertSame([], $result['reasons']);
        $this->assertSame(
            [['index.md', 1], ['a/index.md', 1], ['a/sub/INDEX.MD', 1]],
            array_map(static fn (array $r): array => [$r['file'], $r['line']], $result['references'])
        );
        $this->assertSame(3, $result['files_checked']);
        $this->assertSame(3, $result['folders_checked']);
        $this->assertSame([], $result['unchecked']);
    }

    public function test_searches_from_the_root_level_downwards(): void {
        [$tree, $contents] = $this->sample();

        $result = context_reference_search::run(new tree_storage_port($tree, null, $contents), '', ['a/plan.md']);

        $this->assertSame(['a/index.md', 'b/index.md', 'a/sub/INDEX.MD'], array_column($result['references'], 'file'));
    }

    public function test_an_unreadable_folder_is_named_and_the_rest_is_still_searched(): void {
        $tree = ['' => ['a/'], 'a' => ['index.md', 'bad/'], 'a/bad' => $this->failure(webdav_error::BLOCKED)];

        $result = context_reference_search::run(
            new tree_storage_port($tree, null, ['a/index.md' => 'plan.md']),
            'a',
            self::MOVED
        );

        $this->assertCount(1, $result['references']);
        $this->assertSame(['unreadable_folder'], $result['reasons']);
        $this->assertSame(['a/bad'], $result['unchecked']);
    }

    public function test_an_outage_stops_the_search_without_throwing_and_keeps_the_findings(): void {
        $tree = [
            '' => ['a/'], 'a' => ['index.md', 'x/', 'y/'], 'a/x' => $this->failure(webdav_error::UNREACHABLE),
            'a/y' => ['index.md'],
        ];

        $result = context_reference_search::run(
            new tree_storage_port($tree, null, ['a/index.md' => 'plan.md', 'a/y/index.md' => '../plan.md']),
            'a',
            self::MOVED
        );

        $this->assertSame(['storage_unavailable'], $result['reasons']);
        $this->assertSame(['a/index.md'], array_column($result['references'], 'file'));
        $this->assertSame(['a/x', 'a/y'], $result['unchecked']);
    }

    public function test_stops_at_the_folder_limit_and_names_the_waiting_folders(): void {
        $tree = ['a' => []];
        for ($i = 0; $i < 40; $i++) {
            $tree['a'][] = "o$i/";
        }

        $result = context_reference_search::run(new tree_storage_port($tree), 'a', self::MOVED, null, 8.0, 10);

        $this->assertSame(['folder_limit'], $result['reasons']);
        $this->assertSame(10, $result['folders_checked']);
        $this->assertCount(10, $result['unchecked']);
        $this->assertStringStartsWith('a/o', $result['unchecked'][0]);
    }

    public function test_stops_at_the_file_limit_but_keeps_what_it_found(): void {
        $tree = ['a' => ['index.md', 'sub/'], 'a/sub' => ['index.md', 'context.md']];
        $contents = ['a/index.md' => 'plan.md', 'a/sub/index.md' => '../plan.md', 'a/sub/context.md' => '../plan.md'];

        $result = context_reference_search::run(
            new tree_storage_port($tree, null, $contents),
            'a',
            self::MOVED,
            null,
            8.0,
            300,
            2
        );

        $this->assertSame(['file_limit'], $result['reasons']);
        $this->assertSame(2, $result['files_checked']);
        $this->assertSame(['a/sub/context.md'], $result['unchecked']);
        $this->assertCount(2, $result['references']);
    }

    public function test_stops_at_the_time_limit_with_an_injected_clock(): void {
        $now = 100.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $tree = ['' => ['x/'], 'a' => ['index.md', 'p/'], 'a/p' => ['index.md']];
        $port = new tree_storage_port($tree, static function () use (&$now): void {
            $now += 3.0;
        }, ['a/index.md' => 'plan.md', 'a/p/index.md' => '../plan.md']);

        $result = context_reference_search::run($port, 'a', self::MOVED, $clock, 8.0);

        $this->assertSame(['time_limit'], $result['reasons']);
        $this->assertSame(['a/index.md'], array_column($result['references'], 'file'));
        $this->assertSame(['a/p/index.md'], $result['unchecked']);
    }

    public function test_deeper_folders_than_the_depth_limit_are_not_searched(): void {
        $tree = [];
        $contents = [];
        $path = 'a';
        for ($i = 0; $i <= context_reference_search::MAX_DEPTH + 1; $i++) {
            $tree[$path] = ['index.md', 'd/'];
            $contents[$path . '/index.md'] = '';
            $path .= '/d';
        }
        $result = context_reference_search::run(new tree_storage_port($tree, null, $contents), 'a', self::MOVED);

        $this->assertSame(['depth_limit'], $result['reasons']);
        // The root above the level, the level itself and ten levels below it.
        $this->assertSame(context_reference_search::MAX_DEPTH + 2, $result['folders_checked']);
        $this->assertSame(['a/d/d/d/d/d/d/d/d/d/d/d'], $result['unchecked']);
    }

    public function test_an_index_file_over_one_mebibyte_is_not_read_and_named(): void {
        $big = str_repeat('x', context_reference_search::MAX_BYTES + 1);
        $tree = ['a' => ['index.md', 'sub/'], 'a/sub' => ['index.md']];

        $result = context_reference_search::run(
            new tree_storage_port($tree, null, ['a/index.md' => $big, 'a/sub/index.md' => '../plan.md']),
            'a',
            self::MOVED
        );

        $this->assertSame(['size_limit'], $result['reasons']);
        $this->assertSame(['a/index.md'], $result['unchecked']);
        $this->assertSame(['a/sub/index.md'], array_column($result['references'], 'file'));
    }

    public function test_a_file_that_cannot_be_read_is_named_and_the_search_goes_on(): void {
        $tree = ['a' => ['index.md', 'sub/'], 'a/sub' => ['index.md']];
        $contents = ['a/index.md' => $this->failure(webdav_error::UNCLEAR), 'a/sub/index.md' => '../plan.md'];

        $result = context_reference_search::run(new tree_storage_port($tree, null, $contents), 'a', self::MOVED);

        $this->assertSame(['unreadable_file'], $result['reasons']);
        $this->assertSame(['a/index.md'], $result['unchecked']);
        $this->assertCount(1, $result['references']);
    }

    public function test_a_personal_data_index_with_the_switch_off_is_not_used(): void {
        $this->resetAfterTest();
        set_config('allowpersonaldata', 0, 'local_coursepilot');
        $tree = ['a' => ['index.md']];

        $result = context_reference_search::run(
            new tree_storage_port($tree, null, ['a/index.md' => "---\npersonenbezug: true\n---\nplan.md"]),
            'a',
            self::MOVED
        );

        $this->assertSame(['locked_file'], $result['reasons']);
        $this->assertSame([], $result['references']);
    }

    public function test_references_are_capped_and_the_search_says_so(): void {
        $tree = ['a' => ['index.md', 'sub/'], 'a/sub' => ['index.md']];
        $contents = [
            'a/index.md' => implode("\n", array_fill(0, context_reference_search::MAX_REFERENCES + 5, 'plan.md')),
            'a/sub/index.md' => '../plan.md',
        ];

        $result = context_reference_search::run(new tree_storage_port($tree, null, $contents), 'a', self::MOVED);

        $this->assertCount(context_reference_search::MAX_REFERENCES, $result['references']);
        $this->assertSame(['reference_limit'], $result['reasons']);
        $this->assertSame(['a/index.md', 'a/sub/index.md'], $result['unchecked']);
    }

    public function test_an_index_file_that_vanished_on_read_is_unreadable_not_skipped(): void {
        $tree = ['a' => ['index.md']];

        $result = context_reference_search::run(new tree_storage_port($tree), 'a', self::MOVED);

        $this->assertSame(['unreadable_file'], $result['reasons']);
        $this->assertSame(['a/index.md'], $result['unchecked']);
    }
}
