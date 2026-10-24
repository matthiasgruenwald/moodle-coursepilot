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

/**
 * Storage contract tests for the private files adapter.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */

namespace local_coursepilot;

/**
 * Value-object defaults of the storage layer (#672 mutation contracts).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(storage_area::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(storage_failure::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(storage_conflict_exception::class)]
final class storage_value_objects_test extends \basic_testcase {
    /**
     * Write folder names use the strict upload allowlist unless an area opts out.
     */
    public function test_storage_area_defaults_to_strict_write_folders(): void {
        $area = new storage_area(
            rootsetting: 'root',
            defaultroot: 'default',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
            },
        );

        $this->assertTrue($area->strictwritefolders);
        $this->assertFalse($area->externalfallback);
        $this->assertNull($area->pointerkey);
    }

    /**
     * A storage failure keeps the cause message and chain and carries no numeric code.
     */
    public function test_storage_failure_keeps_cause_and_has_code_zero(): void {
        $cause = new \RuntimeException('Disk gone', 42);
        $failure = new storage_failure('conflict', $cause);

        $this->assertSame('conflict', $failure->errorclass);
        $this->assertSame('Disk gone', $failure->getMessage());
        $this->assertSame(0, $failure->getCode());
        $this->assertSame($cause, $failure->getPrevious());
    }

    /**
     * The conflict exception names the affected path in its message and error code.
     */
    public function test_storage_conflict_exception_names_the_path(): void {
        $conflict = new storage_conflict_exception('plan/woche1.md');

        $this->assertSame('storageconflict', $conflict->errorcode);
        $this->assertStringContainsString('plan/woche1.md', $conflict->getMessage());
    }
}
