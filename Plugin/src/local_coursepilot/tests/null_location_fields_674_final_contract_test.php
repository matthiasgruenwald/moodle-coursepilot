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

defined('MOODLE_INTERNAL') || die();

/**
 * Locations without a stored path or folder are handled without PHP deprecations (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(private_files_storage_port::class)]
#[CoversClass(storage_anchor::class)]
final class null_location_fields_674_final_contract_test extends \advanced_testcase {
    /**
     * Storage area with an unrestricted file name check.
     *
     * @return storage_area
     */
    private function area(): storage_area {
        return new storage_area(
            rootsetting: 'storageportcontracttestroot',
            defaultroot: 'storageport-contract-test',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
            },
        );
    }

    /**
     * Runs a call and returns its result with every PHP deprecation it raised.
     *
     * @param callable $call
     * @return array{0: mixed, 1: string[]}
     */
    private function with_deprecations(callable $call): array {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;
            return true;
        }, E_DEPRECATED);
        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }
        return [$result, $deprecations];
    }

    /**
     * A location without a Private Files path lists the root without passing null to string functions.
     */
    public function test_a_private_files_location_without_a_path_resolves_the_root_cleanly(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $port = new private_files_storage_port(pointer_location::external(1, 'x', []));

        [$entries, $deprecations] = $this->with_deprecations(fn() => $port->list($this->area(), ''));

        $this->assertSame([], $deprecations);
        $this->assertSame([], $entries);
    }

    /**
     * A Moodle location has no folder inside an external instance, so the relative path is just the client path.
     */
    public function test_a_moodle_location_has_no_external_base_folder(): void {
        $this->resetAfterTest();

        [$path, $deprecations] = $this->with_deprecations(
            fn() => storage_anchor::external_relative_path($this->area(), pointer_location::moodle('/x/'), 'notes/a.md')
        );

        $this->assertSame([], $deprecations);
        $this->assertSame('notes/a.md', $path);
    }
}
