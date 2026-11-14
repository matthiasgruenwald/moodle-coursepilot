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

namespace local_coursepilot\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Files embedded in editor text survive writes that run through the carried-forward editor draft.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_module_settings::class)]
#[CoversClass(set_completion::class)]
#[CoversClass(\local_coursepilot\catalog\pseudofield_carry_forward::class)]
final class update_module_settings_embedded_files_test extends \advanced_testcase {
    /**
     * Signs in an editing teacher of a new course.
     *
     * @return \stdClass The course.
     */
    private function course_with_teacher(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Stores a file in an activity file area.
     *
     * @param int $cmid
     * @param string $component
     * @param string $filearea
     * @param string $filepath
     * @param string $filename
     */
    private function store_file(int $cmid, string $component, string $filearea, string $filepath, string $filename): void {
        get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cmid)->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => $filepath,
            'filename' => $filename,
        ], 'bytes ' . $filename);
    }

    /**
     * Lists the stored files of an activity file area as path plus name.
     *
     * @param int $cmid
     * @param string $component
     * @param string $filearea
     * @return string[]
     */
    private function stored_files(int $cmid, string $component, string $filearea): array {
        $files = get_file_storage()->get_area_files(
            \context_module::instance($cmid)->id,
            $component,
            $filearea,
            0,
            'filepath, filename',
            false
        );
        $paths = array_values(array_map(static fn($file): string => $file->get_filepath() . $file->get_filename(), $files));
        sort($paths);
        return $paths;
    }

    /**
     * Creates a page whose content and intro each reference one stored file.
     *
     * @return int Course module id.
     */
    private function page_with_embedded_files(): int {
        $course = $this->course_with_teacher();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'intro' => '<p>Intro <img src="@@PLUGINFILE@@/intro.png"></p>',
            'content' => '<p>Body <img src="@@PLUGINFILE@@/body.png"></p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $this->store_file((int) $page->cmid, 'mod_page', 'content', '/', 'body.png');
        $this->store_file((int) $page->cmid, 'mod_page', 'intro', '/', 'intro.png');
        return (int) $page->cmid;
    }

    /**
     * Applies a patch through the public tool as a client calls it.
     *
     * @param int $cmid
     * @param mixed[] $patch
     */
    private function write(int $cmid, array $patch): void {
        external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode($patch))
        );
    }

    /**
     * Patches to a page that leave the content file referenced.
     *
     * @return array<string, array{mixed[]}>
     */
    public static function page_patches_keeping_the_content_file(): array {
        return [
            'a field unrelated to the content' => [['name' => 'Renamed']],
            'content text that still references the file' => [
                ['content' => '<p>New <img src="@@PLUGINFILE@@/body.png"></p>'],
            ],
        ];
    }

    /**
     * A page write that does not replace the editor draft keeps the content file.
     *
     * @param mixed[] $patch
     */
    #[DataProvider('page_patches_keeping_the_content_file')]
    public function test_a_page_write_keeps_the_content_file(array $patch): void {
        $this->resetAfterTest();
        $cmid = $this->page_with_embedded_files();

        $this->write($cmid, $patch);

        $this->assertSame(['/body.png'], $this->stored_files($cmid, 'mod_page', 'content'));
    }

    /**
     * The stored page text still points at the kept file.
     */
    public function test_the_page_text_still_references_the_kept_file(): void {
        global $DB;
        $this->resetAfterTest();
        $cmid = $this->page_with_embedded_files();

        $this->write($cmid, ['name' => 'Renamed']);

        $content = $DB->get_field('page', 'content', ['id' => get_coursemodule_from_id('page', $cmid)->instance]);
        $this->assertStringContainsString('@@PLUGINFILE@@/body.png', $content);
    }

    /**
     * Content files in subfolders survive, as the page editor allows subfolders.
     */
    public function test_a_page_write_keeps_content_files_in_subfolders(): void {
        $this->resetAfterTest();
        $cmid = $this->page_with_embedded_files();
        $this->store_file($cmid, 'mod_page', 'content', '/sub/', 'deep.png');

        $this->write($cmid, ['name' => 'Renamed']);

        $this->assertSame(['/body.png', '/sub/deep.png'], $this->stored_files($cmid, 'mod_page', 'content'));
    }

    /**
     * Regression guard for the intro path, which is not carried forward: patches to the page
     * that name or leave the intro keep its file.
     *
     * @return array<string, array{mixed[]}>
     */
    public static function page_patches_on_the_intro(): array {
        return [
            'a field unrelated to the intro' => [['name' => 'Renamed']],
            'intro text that still references the file' => [
                ['intro' => '<p>New <img src="@@PLUGINFILE@@/intro.png"></p>'],
            ],
        ];
    }

    /**
     * Regression guard: the intro file survives patches that go through the intro editor.
     *
     * @param mixed[] $patch
     */
    #[DataProvider('page_patches_on_the_intro')]
    public function test_a_page_write_keeps_the_intro_file(array $patch): void {
        $this->resetAfterTest();
        $cmid = $this->page_with_embedded_files();

        $this->write($cmid, $patch);

        $this->assertSame(['/intro.png'], $this->stored_files($cmid, 'mod_page', 'intro'));
    }

    /**
     * Regression guard: naming the "page" editor pseudofield explicitly keeps the content file.
     */
    public function test_a_page_write_naming_the_page_editor_keeps_the_content_file(): void {
        $this->resetAfterTest();
        $cmid = $this->page_with_embedded_files();

        $this->write($cmid, ['page' => '<p>New <img src="@@PLUGINFILE@@/body.png"></p>']);

        $this->assertSame(['/body.png'], $this->stored_files($cmid, 'mod_page', 'content'));
    }

    /**
     * Setting completion on a page takes the same carried-forward editor draft and keeps the file.
     */
    public function test_setting_completion_on_a_page_keeps_the_content_file(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('enablecompletion', 1);
        $cmid = $this->page_with_embedded_files();
        $DB->set_field('course', 'enablecompletion', 1, ['id' => get_coursemodule_from_id('page', $cmid)->course]);

        external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, json_encode(['completion' => COMPLETION_TRACKING_MANUAL]))
        );

        $this->assertSame(['/body.png'], $this->stored_files($cmid, 'mod_page', 'content'));
    }

    /**
     * An assignment's activity instructions keep their files, which core stores in the
     * "activityattachment" area including subfolders.
     */
    public function test_an_assignment_write_keeps_the_activity_instruction_files(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course_with_teacher();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->set_field('assign', 'activity', '<p>Task <img src="@@PLUGINFILE@@/task.png"></p>', ['id' => $assign->id]);
        $DB->set_field('assign', 'activityformat', FORMAT_HTML, ['id' => $assign->id]);
        $this->store_file((int) $assign->cmid, 'mod_assign', 'activityattachment', '/', 'task.png');
        $this->store_file((int) $assign->cmid, 'mod_assign', 'activityattachment', '/sub/', 'deep.png');

        $this->write((int) $assign->cmid, ['name' => 'Renamed']);

        $this->assertSame(
            ['/sub/deep.png', '/task.png'],
            $this->stored_files((int) $assign->cmid, 'mod_assign', 'activityattachment')
        );
    }
}
