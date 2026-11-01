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

use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\failing_database_write;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Failures of the file store itself are neither hidden nor mistaken for conflicts or full quotas (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(private_files_storage_port::class)]
#[CoversClass(pending_write_translation::class)]
final class storage_failure_trigger_contract_test extends \advanced_testcase {
    use caught_throwable;
    use failing_database_write;

    public function test_a_create_only_write_that_the_file_store_refuses_reports_the_store_failure(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $area = new storage_area(
            rootsetting: 'storagefailuretestroot',
            defaultroot: 'storage-failure-test',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
            }
        );
        $port = new private_files_storage_port();

        $e = $this->with_failing_write(
            'files',
            'INSERT',
            "NEW.filename = 'blocked.md'",
            fn() => $this->caught(fn() => $port->write($area, 'blocked.md', 'x', storage_port::MISSING_CHECKSUM))
        );

        $this->assertInstanceOf(\stored_file_creation_exception::class, $e);
        $this->assertNull($port->read($area, 'blocked.md'));
    }

    public function test_a_pending_note_that_cannot_be_stored_for_another_reason_keeps_that_error(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->with_failing_write(
            'files',
            'INSERT',
            "NEW.filename = '" . storage_anchor::PENDING_FILENAME . "'",
            fn() => $this->caught(fn() => pending_write_translation::record_and_translate(
                'unclear',
                'internal diagnostic',
                'plan.md',
                pending_write_translation::OP_CREATE,
                'The storage did not answer.',
                'Test target',
                0
            ))
        );

        $this->assertInstanceOf(\stored_file_creation_exception::class, $e);
    }
}
