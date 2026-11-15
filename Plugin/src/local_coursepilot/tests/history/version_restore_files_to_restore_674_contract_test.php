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
namespace local_coursepilot\history;

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Restore recoverable file content and preserve existing files when historical bytes are unavailable.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(version_restore::class)]
#[CoversClass(\local_coursepilot\catalog\module_settings::class)]
#[CoversClass(\local_coursepilot\activity_file_trash::class)]
final class version_restore_files_to_restore_674_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Existing and missing files both restore through the native folder write.
     *
     * @return array<string, array{bool}>
     */
    public static function current_file_states(): array {
        return ['replacement exists' => [true], 'file is missing' => [false]];
    }

    /**
     * Restore historical bytes, retaining an unrecoverable gap and unrelated current files.
     *
     * @param bool $replacementexists Whether a newer file occupies the historical name.
     */
    #[DataProvider('current_file_states')]
    public function test_restore_recovers_available_files_and_leaves_an_unrecoverable_file_missing(bool $replacementexists): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $folder = $this->getDataGenerator()->create_module('folder', ['course' => $course->id]);
        $context = \context_module::instance($folder->cmid);
        $fs = get_file_storage();
        $record = [
            'contextid' => $context->id, 'component' => 'mod_folder', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/',
        ];
        $gone = $fs->create_file_from_string($record + ['filename' => 'gone.txt'], 'unrecoverable original');
        $recoverable = $fs->create_file_from_string($record + ['filename' => 'restore.txt'], 'recoverable original');
        $versionid = version_writer::capture((int) $folder->cmid, 2);
        $targetversion = (int) $DB->get_field('local_coursepilot_cm_version', 'version', ['id' => $versionid]);
        $this->assertCount(2, version_history::files_at((int) $folder->cmid, $targetversion));
        \local_coursepilot\activity_file_trash::trash($recoverable, (int) $folder->cmid);
        $gone->delete();
        $recoverable->delete();
        $this->assertInstanceOf(\stored_file::class, \local_coursepilot\activity_file_trash::find_for_restore(
            $context->id,
            (int) $folder->cmid,
            'restore.txt',
            sha1('recoverable original')
        ));
        if ($replacementexists) {
            $fs->create_file_from_string($record + ['filename' => 'restore.txt'], 'current replacement');
        }
        $fs->create_file_from_string(array_replace($record, [
            'filepath' => '/kept/', 'filename' => 'other.txt',
        ]), 'unrelated current content');

        $result = $this->succeeded(fn() => version_restore::restore((int) $folder->cmid, $targetversion));

        $files = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'filename', false);
        $this->assertSame((int) $folder->cmid, $result['cmid']);
        $this->assertCount(2, $files);
        $restored = $fs->get_file($context->id, 'mod_folder', 'content', 0, '/', 'restore.txt');
        $this->assertInstanceOf(\stored_file::class, $restored);
        $this->assertSame('restore.txt', $restored->get_filename());
        $this->assertSame('recoverable original', $restored->get_content(), json_encode($result));
        $kept = $fs->get_file($context->id, 'mod_folder', 'content', 0, '/kept/', 'other.txt');
        $this->assertInstanceOf(\stored_file::class, $kept);
        $this->assertSame('unrelated current content', $kept->get_content());
    }

    /**
     * Calls the private files_to_restore() with one wanted file.
     *
     * @param string $filename Wanted file name.
     * @param string $contenthash Wanted content hash.
     * @param bool $createcurrent Whether a current file of that name exists.
     * @return mixed[] The result pair.
     */
    private function files_to_restore(string $filename, string $contenthash, bool $createcurrent): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        /** @var \mod_page_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $page = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        if ($createcurrent) {
            get_file_storage()->create_file_from_string([
                'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
                'itemid' => 0, 'filepath' => '/', 'filename' => $filename,
            ], 'current content');
        }
        $method = new \ReflectionMethod(version_restore::class, 'files_to_restore');
        return $method->invoke(
            null,
            $cm,
            $context,
            ['component' => 'mod_page', 'filearea' => 'content'],
            [(object) ['filename' => $filename, 'contenthash' => $contenthash]]
        );
    }

    public function test_a_differing_file_not_in_the_recycle_bin_keeps_the_current_file(): void {
        [$tobuild, $changed] = $this->files_to_restore('a.txt', sha1('other content'), true);

        $this->assertCount(1, $tobuild);
        $this->assertInstanceOf(\stored_file::class, $tobuild[0]);
        $this->assertSame('a.txt', $tobuild[0]->get_filename());
        $this->assertSame([], $changed);
    }

    public function test_a_missing_file_not_in_the_recycle_bin_is_not_restored(): void {
        [$tobuild, $changed] = $this->files_to_restore('b.txt', sha1('other content'), false);

        $this->assertSame([], $tobuild);
        $this->assertSame([], $changed);
    }
}
