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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Conflict copy name recognition (#783, Spec 0030).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(conflict_copy::class)]
final class conflict_copy_test extends \basic_testcase {
    /**
     * Names that are conflict copies, with the original.
     *
     * @return array<string, array{string, string}>
     */
    public static function copies(): array {
        return [
            'syncthing with device' => ['plan.sync-conflict-20261117-093000-ABC1234.md', 'plan.md'],
            'syncthing without device' => ['plan.sync-conflict-20261117-093000.md', 'plan.md'],
            'syncthing without extension' => ['notes.sync-conflict-20261117-093000-ABC1234', 'notes'],
            'nextcloud old' => ['plan_conflict-20261117-093000.md', 'plan.md'],
            'nextcloud old with user' => ['plan_conflict-anna-20261117-093000.md', 'plan.md'],
            'dropbox' => ['plan (conflicted copy 2026-11-17).md', 'plan.md'],
            'dropbox with user' => ['plan (Anna Muster\'s conflicted copy 2026-11-17 093000).md', 'plan.md'],
            'dropbox case-insensitive' => ['plan (Conflicted Copy).md', 'plan.md'],
            'seafile' => ['plan (SFConflict anna 2026-11-17-09-30-00).md', 'plan.md'],
            'dotted stem' => ['unit.1 (conflicted copy x).md', 'unit.1.md'],
        ];
    }

    #[DataProvider('copies')]
    public function test_recognises_a_copy_and_names_the_original(string $name, string $original): void {
        $this->assertSame($original, conflict_copy::original_name($name));
    }

    public function test_ordinary_names_are_no_copies(): void {
        foreach (['plan.md', 'name 2.md', 'plan (1).md', 'conflict.md', 'plan_conflict.md', 'sync-conflict.md'] as $name) {
            $this->assertNull(conflict_copy::original_name($name), $name);
        }
    }

    public function test_entries_report_whether_the_original_is_there(): void {
        $entries = [
            ['name' => 'plan.md', 'type' => 'file'],
            ['name' => 'plan (conflicted copy).md', 'type' => 'file'],
            ['name' => 'gone (conflicted copy).md', 'type' => 'file'],
            ['name' => 'name 2.md', 'type' => 'file'],
        ];
        $this->assertSame([
            ['copy' => 'plan (conflicted copy).md', 'original' => 'plan.md', 'original_exists' => true],
            ['copy' => 'gone (conflicted copy).md', 'original' => 'gone.md', 'original_exists' => false],
        ], conflict_copy::in_entries($entries));
    }

    public function test_entries_in_a_folder_name_paths_relative_to_the_root(): void {
        $entries = [['name' => 'a.sync-conflict-20261117-093000.md', 'type' => 'file']];
        $this->assertSame(
            [['copy' => 'x/y/a.sync-conflict-20261117-093000.md', 'original' => 'x/y/a.md', 'original_exists' => false]],
            conflict_copy::in_entries($entries, 'x/y')
        );
    }
}
