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
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact roots, paths and pointer file handling of the storage anchor (Spec 0021, #445, #494).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(storage_anchor::class)]
final class storage_anchor_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Area with a configurable root and no name policy.
     *
     * @return storage_area
     */
    private function area(): storage_area {
        return new storage_area(
            rootsetting: 'anchorcontracttestroot',
            defaultroot: 'contract-root',
            invalidpathkey: 'invalidcontextpath',
            quotaerrorkey: 'contextquotaexceeded',
            checkwritablename: static function (string $filename): void {
            },
        );
    }

    /**
     * Logs in a user who owns private files.
     */
    private function user(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    public function test_the_configured_root_is_trimmed_and_the_default_applies_when_unset_or_empty(): void {
        $this->resetAfterTest();
        $area = $this->area();

        $this->assertSame('contract-root', storage_anchor::default_root($area));
        set_config('anchorcontracttestroot', '/Custom/Root/', 'local_coursepilot');
        $this->assertSame('Custom/Root', storage_anchor::default_root($area));
        $this->assertSame('/Custom/Root/a/', storage_anchor::resolve_directory($area, 'a'));
        set_config('anchorcontracttestroot', '', 'local_coursepilot');
        $this->assertSame('contract-root', storage_anchor::default_root($area));
        set_config('anchorcontracttestroot', '///', 'local_coursepilot');
        $this->assertSame('', storage_anchor::default_root($area));
        $this->assertSame('/a/', storage_anchor::resolve_directory($area, 'a'));
    }

    public function test_client_paths_skip_empty_segments_but_keep_every_later_one(): void {
        $this->resetAfterTest();
        $area = $this->area();

        $this->assertSame('/contract-root/a/b/c/', storage_anchor::resolve_directory($area, 'a//b\\c'));
        $this->assertSame('a/b', storage_anchor::normalise_client_path($area, '//a///b//'));
        $this->assertSame('', storage_anchor::normalise_client_path($area, '//'));
        foreach (['a/./b', 'a/../b', '..'] as $bad) {
            $e = $this->caught(fn() => storage_anchor::normalise_client_path($area, $bad));
            $this->assertInstanceOf(\moodle_exception::class, $e, $bad);
            $this->assertSame('invalidcontextpath', $e->errorcode);
        }
    }

    public function test_directories_and_files_are_reported_relative_to_the_root(): void {
        $this->resetAfterTest();
        $area = $this->area();
        $root = storage_anchor::resolve_directory($area, '');
        $sub = storage_anchor::resolve_directory($area, 'x/y');

        $this->assertSame('', storage_anchor::relative_directory($area, $root));
        $this->assertSame('x/y', storage_anchor::relative_directory($area, $sub));
        $this->assertSame('f.md', storage_anchor::relative_file($area, $root, 'f.md'));
        $this->assertSame('x/y/f.md', storage_anchor::relative_file($area, $sub, 'f.md'));
    }

    public function test_an_external_path_joins_the_selected_folder_and_the_client_path_with_single_slashes(): void {
        $this->resetAfterTest();
        $area = $this->area();
        $slashed = pointer_location::external(3, 'a/b/', ['server' => 's']);
        $root = pointer_location::external(3, '', ['server' => 's']);

        $this->assertSame('a/b/f.md', storage_anchor::external_relative_path($area, $slashed, 'f.md'));
        $this->assertSame('a/b', storage_anchor::external_relative_path($area, $slashed, ''));
        $this->assertSame('f.md', storage_anchor::external_relative_path($area, $root, 'f.md'));
        $this->assertSame('', storage_anchor::external_relative_path($area, $root, ''));
    }

    /**
     * Writes raw text as the pointer file.
     *
     * @param string $content
     */
    private function plant_pointer(string $content): void {
        private_files_storage_port::write_content(
            '/' . storage_anchor::ANCHOR_DEFAULT_ROOT . '/',
            storage_anchor::POINTER_FILENAME,
            $content
        );
    }

    public function test_a_pointer_file_must_hold_a_json_object(): void {
        $this->user();
        $this->assertNull(storage_anchor::read_raw_pointer());

        foreach (['5', 'null', '"text"', '[1,2]', '{bad', ''] as $content) {
            $this->plant_pointer($content);
            $e = $this->caught(fn() => storage_anchor::read_raw_pointer());
            $this->assertInstanceOf(\moodle_exception::class, $e, $content);
            $this->assertSame('pointerunreadable', $e->errorcode, $content);
            $this->assertSame(storage_anchor::POINTER_FILENAME, $e->a);
        }
        $this->plant_pointer('{"ort":"extern"}');
        $this->assertSame(['location' => 'external'], storage_anchor::read_raw_pointer());
    }

    public function test_the_pointer_document_is_written_readable_with_raw_slashes_and_unicode(): void {
        $this->user();

        storage_anchor::write_pointer_document(['context_area' => ['path' => 'Ü/ber', 'location' => 'moodle']]);

        $stored = private_files_storage_port::read_content(
            '/' . storage_anchor::ANCHOR_DEFAULT_ROOT . '/',
            storage_anchor::POINTER_FILENAME
        );
        $this->assertStringContainsString("\n    \"context_area\"", $stored['content']);
        $this->assertStringContainsString('"path": "Ü/ber"', $stored['content']);
        $this->assertSame(['context_area' => ['path' => 'Ü/ber', 'location' => 'moodle']], storage_anchor::read_raw_pointer());
    }
}
