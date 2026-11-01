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

defined('MOODLE_INTERNAL') || die();

/**
 * Refused restores report their cause, stay quiet and leave no scaffold behind (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_backup::class)]
final class activity_backup_failure_contract_test extends \advanced_testcase {
    use caught_throwable;
    use failing_database_write;

    protected function setUp(): void {
        parent::setUp();
        $this->preventResetByRollback();
    }

    public function test_a_broken_xml_is_refused_as_invalid_parameter_without_php_warnings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $e = $this->caught(fn() => activity_backup::restore((int) $course->id, 1, '<activity'));
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame([], array_values(array_filter($warnings, static fn($m) => str_contains($m, 'nodeName'))));
    }

    public function test_a_backup_from_a_newer_moodle_cannot_be_restored_and_is_cleaned_up(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $backupid = activity_backup::backup($cm);
        $CFG->version = (float) $CFG->version - 100;
        $pages = $DB->count_records('page');

        $e = $this->caught(fn() => activity_backup::restore((int) $course->id, null, $backupid));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        // Before the plan ran there is no owned activity, so the cleanup report replaces the precheck error.
        $this->assertSame('activitycleanupincomplete', $e->errorcode);
        $this->assertSame($pages, $DB->count_records('page'));
        $this->assertDirectoryDoesNotExist($CFG->tempdir . '/backup/' . $backupid);
    }

    public function test_a_backup_that_fails_while_running_is_reported_and_its_temp_directory_removed(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $before = glob($CFG->tempdir . '/backup/*', GLOB_ONLYDIR) ?: [];

        $e = $this->with_failing_write(
            'backup_controllers',
            'UPDATE',
            '1',
            fn() => $this->caught(fn() => activity_backup::backup($cm))
        );

        $this->assertInstanceOf(\dml_exception::class, $e);
        $this->assertSame($before, glob($CFG->tempdir . '/backup/*', GLOB_ONLYDIR) ?: []);
    }
}
