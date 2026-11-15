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

/**
 * Exact stored shape of trash copies and newest-first lookup (ADR 0018, #674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_file_trash::class)]
final class activity_file_trash_674_final_contract_test extends \advanced_testcase {
    /**
     * Creates a file in a fresh page activity.
     *
     * @param string $filepath
     * @return array{0: \stored_file, 1: int} File and course module id.
     */
    private function activity_file(string $filepath = '/'): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => $filepath, 'filename' => 'blatt.pdf',
        ], 'Fassung A');
        return [$file, (int) $page->cmid];
    }

    /**
     * The trash copy lives in the source file's context, the trash area, the course module and
     * a path derived from the source's path hash.
     */
    public function test_trash_copy_is_stored_with_exact_location_and_content(): void {
        global $DB;
        $this->resetAfterTest();
        [$file, $cmid] = $this->activity_file();

        activity_file_trash::trash($file, $cmid);

        $copies = $DB->get_records('files', [
            'component' => activity_file_trash::COMPONENT,
            'filearea' => activity_file_trash::FILEAREA,
            'itemid' => $cmid,
            'filename' => 'blatt.pdf',
        ]);
        $this->assertCount(1, $copies);
        $copy = reset($copies);
        $this->assertSame((int) $file->get_contextid(), (int) $copy->contextid);
        $this->assertSame('/' . $cmid . '/' . $file->get_pathnamehash() . '/', $copy->filepath);
        $this->assertSame('blatt.pdf', $copy->filename);
        $this->assertSame($file->get_contenthash(), $copy->contenthash);
    }

    /**
     * Of two trash copies with the same name and content, the newest one is returned.
     */
    public function test_find_for_restore_returns_the_newest_copy(): void {
        global $DB;
        $this->resetAfterTest();
        [$first, $cmid] = $this->activity_file('/');
        $context = \context_module::instance($cmid);
        $second = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/sub/', 'filename' => 'blatt.pdf',
        ], 'Fassung A');

        activity_file_trash::trash($first, $cmid);
        activity_file_trash::trash($second, $cmid);
        $DB->execute(
            "UPDATE {files} SET timecreated = 100 WHERE component = ? AND filearea = ? AND itemid = ? AND pathnamehash = ?",
            [activity_file_trash::COMPONENT, activity_file_trash::FILEAREA, $cmid, $this->trash_hash($first, $cmid)]
        );

        $found = activity_file_trash::find_for_restore(
            (int) $first->get_contextid(),
            $cmid,
            'blatt.pdf',
            $first->get_contenthash()
        );
        $this->assertNotNull($found);
        $this->assertSame($this->trash_hash($second, $cmid), $found->get_pathnamehash());
    }

    /**
     * Files in subfolders of the activity's current area survive a restore into the draft.
     */
    public function test_restore_draft_keeps_files_in_subfolders(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$source, $cmid] = $this->activity_file('/');
        $context = \context_module::instance($cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/kapitel/', 'filename' => 'anhang.pdf',
        ], 'Anhang');

        $draftid = activity_file_trash::resolve_restore_into_draft($context->id, 'mod_page', 'content', [$source]);

        $usercontext = \context_user::instance($USER->id);
        $this->assertNotFalse(
            get_file_storage()->get_file($usercontext->id, 'user', 'draft', $draftid, '/kapitel/', 'anhang.pdf')
        );
    }

    /**
     * Pathname hash of the trash copy of the given file.
     *
     * @param \stored_file $file
     * @param int $cmid
     * @return string
     */
    private function trash_hash(\stored_file $file, int $cmid): string {
        return get_file_storage()->get_pathname_hash(
            (int) $file->get_contextid(),
            activity_file_trash::COMPONENT,
            activity_file_trash::FILEAREA,
            $cmid,
            '/' . $cmid . '/' . $file->get_pathnamehash() . '/',
            'blatt.pdf'
        );
    }
}
