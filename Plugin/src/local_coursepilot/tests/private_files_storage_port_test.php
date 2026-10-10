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

use local_coursepilot\tests\storage_port_contract_test;

// phpcs:disable moodle.PHPUnit.TestCaseNames.Missing -- The test case is inherited from storage_port_contract_test.
/**
 * Storage contract against the first adapter (Issue #536, Spec 0021):
 * private_files_storage_port runs the shared storage_port_contract_test.
 *
 * The test area has an independent root and .md naming rule, borrowing
 * context-area error keys rather than adding test-only language strings.
 * It has no pointer field, so it cannot enter legacy pointer resolution.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(private_files_storage_port::class)]
final class private_files_storage_port_test extends storage_port_contract_test {
    /**
     * Provides port.
     *
     * @return storage_port
     */
    protected function port(): storage_port {
        return new private_files_storage_port();
    }

    /**
     * Provides area.
     *
     * @return storage_area
     */
    protected function area(): storage_area {
        return new storage_area(
            rootsetting: 'storageportcontracttestroot',
            defaultroot: 'storageport-contract-test',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
                if (!preg_match('/^[A-Za-z0-9_.-]+\.md$/', $filename)) {
                    throw new \moodle_exception('contextfilenotmarkdown', 'local_coursepilot', '', $filename);
                }
            },
        );
    }
}
