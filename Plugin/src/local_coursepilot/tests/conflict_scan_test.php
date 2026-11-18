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
 * Whole-area conflict copy search (#783, Spec 0030) against a fake storage port.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(conflict_scan::class)]
final class conflict_scan_test extends \advanced_testcase {
    /**
     * A fake port over a tree (see {@see tree_storage_port}).
     *
     * @param mixed[] $tree
     * @param \Closure|null $onlist
     * @return storage_port
     */
    private function port(array $tree, ?\Closure $onlist = null): storage_port {
        return new tree_storage_port($tree, $onlist);
    }

    /**
     * A storage failure of the given class.
     *
     * @param string $class
     * @return storage_failure
     */
    private function failure(string $class): storage_failure {
        return new storage_failure($class, new webdav_error($class));
    }

    public function test_finds_copies_in_subfolders_relative_to_the_root(): void {
        $port = $this->port([
            '' => ['index.md', 'klassen/'],
            'klassen' => ['7b/', 'x (conflicted copy).md', 'x.md'],
            'klassen/7b' => ['plan.sync-conflict-20261117-093000-ABC1234.md'],
        ]);
        $result = conflict_scan::run($port);
        $this->assertSame([
            ['copy' => 'klassen/x (conflicted copy).md', 'original' => 'klassen/x.md', 'original_exists' => true],
            [
                'copy' => 'klassen/7b/plan.sync-conflict-20261117-093000-ABC1234.md',
                'original' => 'klassen/7b/plan.md',
                'original_exists' => false,
            ],
        ], $result['copies']);
        $this->assertTrue($result['checked']);
        $this->assertTrue($result['complete']);
        $this->assertSame('', $result['reason']);
        $this->assertSame(3, $result['folders_checked']);
        $this->assertSame(0, $result['folders_waiting']);
    }

    public function test_an_unreadable_folder_is_named_and_skipped(): void {
        $port = $this->port([
            '' => ['bad/', 'good/'],
            'bad' => $this->failure(webdav_error::BLOCKED),
            'good' => ['a (conflicted copy).md'],
        ]);
        $result = conflict_scan::run($port);
        $this->assertSame(['bad'], $result['unreadable']);
        $this->assertSame(['good/a (conflicted copy).md'], array_column($result['copies'], 'copy'));
        $this->assertTrue($result['checked']);
        $this->assertFalse($result['complete']);
        $this->assertSame('unreadable_folder', $result['reason']);
    }

    public function test_an_outage_is_not_checked_with_a_reason_and_does_not_throw(): void {
        $port = $this->port([
            '' => ['a/', 'b/'],
            'a' => ['x (conflicted copy).md'],
            'b' => $this->failure(webdav_error::UNREACHABLE),
        ]);
        $result = conflict_scan::run($port);
        $this->assertFalse($result['checked']);
        $this->assertFalse($result['complete']);
        $this->assertSame('storage_unavailable', $result['reason']);
        $this->assertSame([], $result['copies']);
    }

    public function test_stops_at_500_folders_and_counts_the_waiting_ones(): void {
        $tree = ['' => []];
        for ($i = 0; $i < 510; $i++) {
            $tree[''][] = "f$i/";
        }
        $result = conflict_scan::run($this->port($tree));
        $this->assertSame('folder_limit', $result['reason']);
        $this->assertFalse($result['complete']);
        $this->assertSame(500, $result['folders_checked']);
        $this->assertSame(11, $result['folders_waiting']);
    }

    public function test_does_not_go_deeper_than_ten_levels(): void {
        $tree = [];
        $path = '';
        for ($depth = 0; $depth <= 12; $depth++) {
            $tree[$path] = ['d/', "c$depth (conflicted copy).md"];
            $path = $path === '' ? 'd' : "$path/d";
        }
        $result = conflict_scan::run($this->port($tree));
        $this->assertSame('depth_limit', $result['reason']);
        $this->assertFalse($result['complete']);
        $this->assertSame(11, $result['folders_checked']);
        $this->assertCount(11, $result['copies']);
    }

    public function test_stops_when_the_injected_clock_runs_out(): void {
        $now = 100.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $port = $this->port(['' => ['a/', 'b/', 'c/']], static function () use (&$now): void {
            $now += 3.0;
        });
        $result = conflict_scan::run($port, 8.0, $clock);
        $this->assertSame('time_limit', $result['reason']);
        $this->assertFalse($result['complete']);
        $this->assertSame(3, $result['folders_checked']);
        $this->assertSame(1, $result['folders_waiting']);
    }

    public function test_run_current_scans_the_current_location(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $fs = get_file_storage();
        foreach (['plan.md', 'plan (conflicted copy).md'] as $name) {
            $fs->create_file_from_string([
                'contextid' => \context_user::instance($user->id)->id,
                'component' => context_files::COMPONENT, 'filearea' => context_files::FILEAREA,
                'itemid' => context_files::ITEMID, 'filepath' => '/coursepilot/', 'filename' => $name,
            ], 'x');
        }
        $result = conflict_scan::run_current();
        $this->assertTrue($result['complete']);
        $this->assertSame(
            [['copy' => 'plan (conflicted copy).md', 'original' => 'plan.md', 'original_exists' => true]],
            $result['copies']
        );
    }
}
