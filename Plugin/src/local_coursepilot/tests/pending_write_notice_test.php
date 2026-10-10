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

defined('MOODLE_INTERNAL') || die();

/**
 * Pending note (Issue #492, ADR 0023), independently of pointer_writer
 * and its WebDAV failure handling.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(pending_write_notice::class)]
final class pending_write_notice_test extends \advanced_testcase {
    /**
     * One entry per failed operation: identifier, time, path, operation and
     * error class, without content (ADR 0023).
     */
    public function test_record_returns_kennung_and_is_listed(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $kennung = pending_write_notice::record('plan.md', 'create', 'storage_full', 7);

        $this->assertNotSame('', $kennung);
        $groups = pending_write_notice::list_grouped();
        $this->assertCount(1, $groups);
        $this->assertSame('plan.md', $groups[0]['path']);
        $this->assertSame([
            'identifier' => $kennung,
            'timestamp' => $groups[0]['entries'][0]['timestamp'],
            'operation' => 'create',
            'error_class' => 'storage_full',
            'course_id' => 7,
        ], $groups[0]['entries'][0]);
    }

    /**
     * Two failed operations on the same file create separate entries with
     * distinct identifiers (ADR 0023).
     */
    public function test_two_failures_on_the_same_file_get_separate_entries(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $erste = pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $zweite = pending_write_notice::record('plan.md', 'overwrite', 'unreachable', 7);

        $this->assertNotSame($erste, $zweite);
        $groups = pending_write_notice::list_grouped();
        $this->assertCount(1, $groups);
        $this->assertCount(2, $groups[0]['entries']);
    }

    /**
     * Dismissal removes the entry and reports success; unknown IDs return
     * false rather than silently succeeding.
     */
    public function test_dismiss_removes_entry_and_reports_unknown_kennung(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $kennung = pending_write_notice::record('plan.md', 'create', 'storage_full', 7);

        $this->assertTrue(pending_write_notice::dismiss($kennung));
        $this->assertSame([], pending_write_notice::list_grouped());
        $this->assertFalse(pending_write_notice::dismiss($kennung));
        $this->assertFalse(pending_write_notice::dismiss('NIEEXISTIERT'));
    }

    /**
     * Users cannot see each other's pending notes, stored in separate user contexts.
     */
    public function test_entries_are_isolated_per_person(): void {
        $this->resetAfterTest();
        $teachera = $this->getDataGenerator()->create_user();
        $teacherb = $this->getDataGenerator()->create_user();

        $this->setUser($teachera);
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);

        $this->setUser($teacherb);
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    /**
     * Explicitly report when Private Files quota prevents saving the note
     * (ADR 0023 consequences).
     */
    public function test_record_fails_explicitly_when_quota_is_exhausted(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->userquota = 1;
        $this->setUser($this->getDataGenerator()->create_user());

        try {
            pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
            $this->fail('Quota overflow should have been rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame('pendingnotequotaexceeded', $e->errorcode);
        }
    }

    /**
     * Without an authenticated user, return an empty note without DB access,
     * as in storage_anchor::raw_pointer().
     */
    public function test_list_grouped_is_empty_without_logged_in_user(): void {
        $this->resetAfterTest();
        $this->assertSame([], pending_write_notice::list_grouped());
    }
}
