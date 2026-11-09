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
use local_coursepilot\tests\native_override;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/native_override_functions.php');

/**
 * Failure paths of the activity backup scaffold and restore (#673).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_backup::class)]
final class activity_backup_673_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Restores the real random_string().
     */
    protected function tearDown(): void {
        native_override::reset();
        parent::tearDown();
    }

    /**
     * A scaffold file that cannot be written aborts the restore and removes the half-built directory.
     */
    public function test_a_scaffold_file_that_cannot_be_written_fails_the_restore_and_removes_the_directory(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $xml = activity_backup::export(get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST));
        native_override::$strings[30] = 'scaffoldwritefailure0000000000';
        $base = $CFG->tempdir . '/backup/cpscaffoldwritefailure0000000000';
        // A directory where the first scaffold file belongs: writing it fails.
        check_dir_exists($base . '/files.xml');
        $pages = count(get_coursemodules_in_course('page', $course->id));
        set_error_handler(static function (int $errno, string $message): bool {
            throw new \ErrorException($message, 0, $errno);
        });
        try {
            $e = $this->caught(fn() => activity_backup::restore((int) $course->id, 1, $xml));
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(\ErrorException::class, $e);
        $this->assertStringContainsString('files.xml', $e->getMessage());
        $this->assertDirectoryDoesNotExist($base);
        $this->assertCount($pages, get_coursemodules_in_course('page', $course->id));
    }

    /**
     * A backup whose plan restores no activity is reported as a failed restore with nothing to clean up.
     */
    public function test_a_backup_whose_plan_restores_no_activity_is_reported_as_incomplete_cleanup(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $backupid = activity_backup::backup($cm);
        // The manifest lists no activity, so the plan has no activity task.
        $file = $CFG->tempdir . '/backup/' . $backupid . '/moodle_backup.xml';
        $manifest = preg_replace(
            ['~<activities>.*?</activities>~s', '~<setting>\\s*<level>activity</level>.*?</setting>~s'],
            ['<activities></activities>', ''],
            file_get_contents($file)
        );
        file_put_contents($file, $manifest);
        $pages = $DB->count_records('page');

        // Core warns about the empty activity list of this hand-made manifest while it builds the plan.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $e = $this->caught(fn() => activity_backup::restore((int) $course->id, null, $backupid));
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('activitycleanupincomplete', $e->errorcode);
        $this->assertSame(
            'Restore reported no owned activity identity; no automatic deletion was attempted.',
            $e->debuginfo
        );
        $this->assertSame($pages, $DB->count_records('page'));
        $this->assertDirectoryDoesNotExist($CFG->tempdir . '/backup/' . $backupid);
    }
}
