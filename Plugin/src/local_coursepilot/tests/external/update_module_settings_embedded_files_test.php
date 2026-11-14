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
 * Files embedded in a page's content and intro survive a settings write.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_module_settings::class)]
#[CoversClass(\local_coursepilot\catalog\pseudofield_carry_forward::class)]
final class update_module_settings_embedded_files_test extends \advanced_testcase {
    /**
     * Creates a page whose content and intro each reference one stored file.
     *
     * @return int Course module id.
     */
    private function page_with_embedded_files(): int {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'intro' => '<p>Intro <img src="@@PLUGINFILE@@/intro.png"></p>',
            'content' => '<p>Body <img src="@@PLUGINFILE@@/body.png"></p>',
            'contentformat' => FORMAT_HTML,
        ]);
        $context = \context_module::instance($page->cmid);
        $fs = get_file_storage();
        foreach (['content' => 'body.png', 'intro' => 'intro.png'] as $filearea => $filename) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_page',
                'filearea' => $filearea,
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ], 'image bytes ' . $filename);
        }
        return (int) $page->cmid;
    }

    /**
     * Applies a patch as a client does and returns the names of the files left in an area.
     *
     * @param int $cmid
     * @param mixed[] $patch
     * @param string $filearea
     * @return string[]
     */
    private function write_and_list_files(int $cmid, array $patch, string $filearea): array {
        external_api::clean_returnvalue(
            update_module_settings::execute_returns(),
            update_module_settings::execute($cmid, json_encode($patch))
        );
        $files = get_file_storage()->get_area_files(
            \context_module::instance($cmid)->id,
            'mod_page',
            $filearea,
            0,
            'filename',
            false
        );
        return array_values(array_map(static fn($file): string => $file->get_filename(), $files));
    }

    /**
     * Patches that leave the files referenced by the text, with the area to check.
     *
     * @return array<string, array{mixed[], string, string}> Patch, file area, expected file.
     */
    public static function patches_keeping_referenced_files(): array {
        return [
            'name only, content area' => [['name' => 'Renamed'], 'content', 'body.png'],
            'name only, intro area' => [['name' => 'Renamed'], 'intro', 'intro.png'],
            'content text with the reference' => [
                ['content' => '<p>New <img src="@@PLUGINFILE@@/body.png"></p>'],
                'content',
                'body.png',
            ],
            'page editor with the reference' => [
                ['page' => '<p>New <img src="@@PLUGINFILE@@/body.png"></p>'],
                'content',
                'body.png',
            ],
            'intro text with the reference' => [
                ['intro' => '<p>New <img src="@@PLUGINFILE@@/intro.png"></p>'],
                'intro',
                'intro.png',
            ],
        ];
    }

    /**
     * A write whose text still references a stored file keeps that file.
     *
     * @param mixed[] $patch
     * @param string $filearea
     * @param string $expected
     */
    #[DataProvider('patches_keeping_referenced_files')]
    public function test_a_write_keeps_the_files_the_text_still_references(array $patch, string $filearea, string $expected): void {
        $this->resetAfterTest();
        $cmid = $this->page_with_embedded_files();

        $this->assertSame([$expected], $this->write_and_list_files($cmid, $patch, $filearea));
    }

    /**
     * The page text keeps pointing at the stored file after an unrelated write.
     */
    public function test_the_page_text_still_references_the_kept_file(): void {
        global $DB;
        $this->resetAfterTest();
        $cmid = $this->page_with_embedded_files();

        $this->write_and_list_files($cmid, ['name' => 'Renamed'], 'content');

        $content = $DB->get_field('page', 'content', ['id' => get_coursemodule_from_id('page', $cmid)->instance]);
        $this->assertStringContainsString('@@PLUGINFILE@@/body.png', $content);
    }

    /**
     * An assignment's activity instructions keep their embedded file on an unrelated write.
     */
    public function test_an_assignment_keeps_the_file_in_its_activity_instructions(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'activity' => '<p>Task <img src="@@PLUGINFILE@@/task.png"></p>',
            'activityformat' => FORMAT_HTML,
        ]);
        $context = \context_module::instance($assign->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_assign',
            'filearea' => 'activity',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'task.png',
        ], 'image bytes');

        update_module_settings::execute((int) $assign->cmid, json_encode(['name' => 'Renamed']));

        $files = get_file_storage()->get_area_files($context->id, 'mod_assign', 'activity', 0, 'filename', false);
        $this->assertSame(['task.png'], array_values(array_map(static fn($file): string => $file->get_filename(), $files)));
    }
}
