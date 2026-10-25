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
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\private_files_storage_port::class)]
final class pending_write_notice_test extends \advanced_testcase {
    use \local_coursepilot\tests\caught_throwable;

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

    /**
     * Replaces the stored note of the current user with the given raw content.
     *
     * @param string $content
     */
    private function put_note(string $content): void {
        $fs = get_file_storage();
        $record = [
            'contextid' => storage_anchor::own_context()->id,
            'component' => storage_anchor::COMPONENT,
            'filearea' => storage_anchor::FILEAREA,
            'itemid' => storage_anchor::ITEMID,
            'filepath' => storage_anchor::anchor_root(),
            'filename' => storage_anchor::PENDING_FILENAME,
        ];
        $existing = $fs->get_file(
            $record['contextid'],
            $record['component'],
            $record['filearea'],
            $record['itemid'],
            $record['filepath'],
            $record['filename']
        );
        if ($existing) {
            $existing->delete();
        }
        $fs->create_file_from_string($record, $content);
    }

    /**
     * Raw content of the stored note of the current user.
     *
     * @return string
     */
    private function note_content(): string {
        return get_file_storage()->get_file(
            storage_anchor::own_context()->id,
            storage_anchor::COMPONENT,
            storage_anchor::FILEAREA,
            storage_anchor::ITEMID,
            storage_anchor::anchor_root(),
            storage_anchor::PENDING_FILENAME
        )->get_content();
    }

    /**
     * Builds one raw note entry.
     *
     * @param int $timestamp
     * @param string $path
     * @param bool $withcourse
     * @return mixed[]
     */
    private static function raw_entry(int $timestamp, string $path, bool $withcourse = true): array {
        return ['timestamp' => $timestamp, 'path' => $path, 'operation' => 'create', 'error_class' => 'storage_full']
            + ($withcourse ? ['course_id' => 3] : []);
    }

    /**
     * Entries of one file are listed oldest first and files by their oldest entry, whatever the stored order.
     */
    public function test_listing_is_ordered_by_time_inside_files_and_across_files(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_note(json_encode([
            'LAST0004' => self::raw_entry(400, 'a.md'),
            'LATEST01' => self::raw_entry(300, 'b.md'),
            'MIDDLE02' => self::raw_entry(200, 'a.md'),
            'EARLY003' => self::raw_entry(100, 'b.md'),
        ]));

        $groups = pending_write_notice::list_grouped();

        $this->assertSame(['b.md', 'a.md'], array_column($groups, 'path'));
        $this->assertSame(['EARLY003', 'LATEST01'], array_column($groups[0]['entries'], 'identifier'));
        $this->assertSame(['MIDDLE02', 'LAST0004'], array_column($groups[1]['entries'], 'identifier'));
        $this->assertSame([100, 300], array_column($groups[0]['entries'], 'timestamp'));
    }

    /**
     * Groups are ordered by the first entry of each, also when a later entry of an earlier file is newer.
     */
    public function test_groups_are_ordered_by_their_oldest_entry_only(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $y = ['TWOAAAAA' => self::raw_entry(120, 'y.md'), 'TWOBBBBB' => self::raw_entry(130, 'y.md')];
        $x = ['ONEAAAAA' => self::raw_entry(100, 'x.md'), 'ONEBBBBB' => self::raw_entry(900, 'x.md')];

        foreach ([$y + $x, $x + $y] as $stored) {
            $this->put_note(json_encode($stored));

            $groups = pending_write_notice::list_grouped();

            $this->assertSame(['x.md', 'y.md'], array_column($groups, 'path'));
        }
    }

    /**
     * Entries written before course ids existed list a course id of zero.
     */
    public function test_entry_without_course_id_lists_zero(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->put_note(json_encode(['OLDENTRY' => self::raw_entry(100, 'x.md', false)]));

        $this->assertSame(0, pending_write_notice::list_grouped()[0]['entries'][0]['course_id']);
    }

    /**
     * A note file that is not a JSON object counts as empty: nothing is listed or dismissed from it.
     */
    public function test_a_note_that_is_not_a_json_object_counts_as_empty(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        foreach (['[1,2]', '"text"', 'null', 'not json'] as $content) {
            $this->put_note($content);
            $this->assertFalse(pending_write_notice::dismiss('0'), $content);
            $this->assertSame([], pending_write_notice::list_grouped(), $content);
        }
    }

    /**
     * The note is stored as readable, pretty-printed JSON with slashes and umlauts unescaped.
     */
    public function test_the_note_is_stored_as_readable_json(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        pending_write_notice::record('Ordner/Überblick.md', 'create', 'storage_full', 7);

        $content = $this->note_content();
        $this->assertStringContainsString("\n        \"path\": \"Ordner/Überblick.md\",\n", $content);
        $this->assertStringStartsWith("{\n", $content);
    }

    /**
     * An identifier is eight upper-case characters and never repeats one that is already open.
     */
    public function test_identifiers_are_eight_uppercase_characters(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $identifiers = [];
        for ($i = 0; $i < 5; $i++) {
            $identifiers[] = pending_write_notice::record('plan.md', 'create', 'storage_full', 0);
        }

        foreach ($identifiers as $identifier) {
            $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $identifier);
        }
        $this->assertCount(5, array_unique($identifiers));
    }

    /**
     * The quota check compares the growth of the note, not its absolute size: exactly fitting passes.
     */
    public function test_a_note_that_exactly_fits_the_quota_is_written(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $first = strlen($this->note_content());
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $second = strlen($this->note_content());

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $CFG->userquota = $first;
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $this->assertSame($first, strlen($this->note_content()), 'The first note fills the quota exactly.');

        $CFG->userquota = $second;
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $this->assertSame($second, strlen($this->note_content()), 'Growing by the difference fits the quota exactly.');

        $this->assertSame(1, $DB->count_records('files', [
            'contextid' => storage_anchor::own_context()->id,
            'filename' => storage_anchor::PENDING_FILENAME,
        ]));
    }

    /**
     * One byte less than needed is refused, for the first note and for a growing one.
     */
    public function test_a_note_one_byte_over_the_quota_is_refused(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $first = strlen($this->note_content());
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $second = strlen($this->note_content());

        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = $first - 1;
        $e = $this->caught(fn() => pending_write_notice::record('plan.md', 'create', 'storage_full', 7));
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('pendingnotequotaexceeded', $e->errorcode);

        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = $first;
        pending_write_notice::record('plan.md', 'create', 'storage_full', 7);
        $CFG->userquota = $second - 1;
        $e = $this->caught(fn() => pending_write_notice::record('plan.md', 'create', 'storage_full', 7));
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('pendingnotequotaexceeded', $e->errorcode);
    }
}
