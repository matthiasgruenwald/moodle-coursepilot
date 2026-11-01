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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * A wanted file that differs from the current one and is not in the recycle bin keeps the current file (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(version_restore::class)]
final class version_restore_files_to_restore_674_contract_test extends \advanced_testcase {
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
