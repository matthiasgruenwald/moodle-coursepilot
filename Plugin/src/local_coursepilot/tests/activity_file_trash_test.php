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
 * Recycle copies of replaced activity files (ADR 0018).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_file_trash::class)]
final class activity_file_trash_test extends \advanced_testcase {
    /**
     * Creates a file in a fresh page activity.
     *
     * @return array{0: \stored_file, 1: int} File and course module id.
     */
    private function activity_file(): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'blatt.pdf',
        ], 'Fassung A');
        return [$file, (int) $page->cmid];
    }

    public function test_trashing_twice_keeps_a_single_recoverable_copy(): void {
        global $DB;
        $this->resetAfterTest();
        [$file, $cmid] = $this->activity_file();

        activity_file_trash::trash($file, $cmid);
        activity_file_trash::trash($file, $cmid);

        $this->assertSame(1, $DB->count_records('files', [
            'component' => activity_file_trash::COMPONENT, 'filearea' => activity_file_trash::FILEAREA,
            'itemid' => $cmid, 'filename' => 'blatt.pdf',
        ]));
        $found = activity_file_trash::find_for_restore($file->get_contextid(), $cmid, 'blatt.pdf', $file->get_contenthash());
        $this->assertSame('Fassung A', $found->get_content());
    }

    public function test_find_for_restore_returns_nothing_for_unknown_content(): void {
        $this->resetAfterTest();
        [$file, $cmid] = $this->activity_file();
        activity_file_trash::trash($file, $cmid);

        $this->assertNull(
            activity_file_trash::find_for_restore($file->get_contextid(), $cmid, 'blatt.pdf', sha1('anderer Inhalt'))
        );
    }

    public function test_restore_draft_replaces_same_named_current_files_and_keeps_the_rest(): void {
        $this->resetAfterTest();
        global $USER;
        [, $cmid] = $this->activity_file();
        $context = \context_module::instance($cmid);
        $this->setAdminUser();
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'zweite.pdf',
        ], 'Bleibt');

        $older = $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'intro',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'blatt.pdf',
        ], 'Fassung alt');

        $draftid = activity_file_trash::resolve_restore_into_draft($context->id, 'mod_page', 'content', [$older]);

        $usercontext = \context_user::instance($USER->id);
        $replaced = $fs->get_file($usercontext->id, 'user', 'draft', $draftid, '/', 'blatt.pdf');
        $this->assertSame('Fassung alt', $replaced->get_content());
        $this->assertNotFalse($fs->get_file($usercontext->id, 'user', 'draft', $draftid, '/', 'zweite.pdf'));
    }
}
