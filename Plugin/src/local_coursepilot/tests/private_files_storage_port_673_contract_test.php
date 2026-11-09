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

use local_coursepilot\tests\stale_read_database;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * A create-only write that loses a race against another writer reports a conflict (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(private_files_storage_port::class)]
final class private_files_storage_port_673_contract_test extends \advanced_testcase {
    /**
     * The file appears after the preflight read: the store refuses to create it and the write reports a conflict.
     */
    public function test_a_create_only_write_that_loses_the_race_reports_a_conflict_and_keeps_the_winner(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $area = new storage_area(
            rootsetting: 'raceloserroot',
            defaultroot: 'race-loser-test',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
            }
        );
        $port = new private_files_storage_port();
        $port->write($area, 'plan.md', 'winner', storage_port::MISSING_CHECKSUM);
        $realdb = $DB;
        // The loser's preflight read ran just before the winner created the file.
        $DB = new stale_read_database($realdb, 'get_record_sql');
        try {
            $port->write($area, 'plan.md', 'loser', storage_port::MISSING_CHECKSUM);
            $this->fail('Expected a storage_conflict_exception.');
        } catch (storage_conflict_exception $e) {
            $this->assertStringContainsString('plan.md', $e->getMessage());
        } finally {
            $DB = $realdb;
        }

        $this->assertSame('winner', $port->read($area, 'plan.md')['content']);
    }
}
