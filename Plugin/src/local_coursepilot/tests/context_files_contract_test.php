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
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact name policy, size limit and legacy migration (log lines, order, scope) of context_files (Spec 0016, #506).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(context_files::class)]
final class context_files_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Provides names the context area refuses although a part looks fine.
     *
     * @return array<string, array{string}>
     */
    public static function refused_names(): array {
        return [
            'junk before a valid tail' => ['my notes.md'],
            'junk after the extension' => ['notes.md!'],
            'dot inside the base name' => ['a.b.md'],
            'other extension' => ['notes.txt'],
            'no base name' => ['.md'],
        ];
    }

    #[DataProvider('refused_names')]
    public function test_only_plain_markdown_names_are_writable(string $filename): void {
        $e = $this->caught(fn() => (context_files::area()->checkwritablename)($filename));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('contextfilenotmarkdown', $e->errorcode);
        $this->assertSame($filename, $e->a);
    }

    public function test_plain_markdown_names_are_accepted(): void {
        foreach (['notes.md', 'A_b-1.md'] as $filename) {
            $this->assertNull((context_files::area()->checkwritablename)($filename), $filename);
        }
    }

    public function test_the_size_limit_allows_the_maximum_and_names_size_and_maximum_beyond_it(): void {
        context_files::require_size_within_limit(str_repeat('a', context_files::MAX_WRITE_BYTES));
        $this->addToAssertionCount(1);

        $e = $this->caught(fn() => context_files::require_size_within_limit(str_repeat('a', context_files::MAX_WRITE_BYTES + 1)));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('contextfiletoolarge', $e->errorcode);
        $this->assertSame(context_files::MAX_WRITE_BYTES + 1, $e->a->size);
        $this->assertSame(context_files::MAX_WRITE_BYTES, $e->a->max);
    }

    /**
     * Stores a legacy file.
     *
     * @param int $contextid
     * @param string $filepath
     * @param string $filename
     * @param string $content
     * @param string $filearea
     * @param string $component
     */
    private function legacy(
        int $contextid,
        string $filepath,
        string $filename,
        string $content,
        string $filearea = context_files::LEGACY_FILEAREA,
        string $component = context_files::LEGACY_COMPONENT
    ): void {
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => context_files::ITEMID,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $content);
    }

    /**
     * Runs the migration and returns [count, log].
     *
     * @return array{0: int, 1: string}
     */
    private function migrate(): array {
        ob_start();
        try {
            $copied = context_files::migrate_legacy_files();
        } finally {
            $log = ob_get_clean();
        }
        return [$copied, $log];
    }

    public function test_migration_skips_what_it_must_and_still_copies_every_later_file(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $contextid = context_files::own_context()->id;
        $root = context_files::resolve_directory('');
        $fs = get_file_storage();
        // Order of creation decides the order of the recordset: placeholder, outside, collision, then the file to copy.
        $fs->create_directory(
            $contextid,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            $root . 'dir/'
        );
        $this->legacy($contextid, '/elsewhere/', 'outside.md', 'out');
        $this->legacy($contextid, $root, 'twin.md', 'old');
        $fs->create_file_from_string([
            'contextid' => $contextid,
            'component' => context_files::COMPONENT,
            'filearea' => context_files::FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => $root,
            'filename' => 'twin.md',
        ], 'kept');
        $this->legacy($contextid, $root . 'dir/', 'fresh.md', 'copy me');
        // Neighbouring areas and components must stay out of the migration.
        $this->legacy($contextid, $root, 'other-area.md', 'x', 'some_other_area');
        $this->legacy($contextid, $root, 'other-component.md', 'x', context_files::LEGACY_FILEAREA, 'mod_page');

        [$copied, $log] = $this->migrate();

        $this->assertSame(1, $copied);
        $expectedroot = 'local_coursepilot: Context file skipped (outside "' . $root . '"): '
            . '/elsewhere/outside.md (context ' . $contextid . ')';
        $expectedtwin = 'local_coursepilot: Context file skipped (already exists in Private Files): '
            . $root . 'twin.md (context ' . $contextid . ')';
        $this->assertSame($expectedroot . "\n" . $expectedtwin . "\n", $log);
        $copy = $fs->get_file(
            $contextid,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            $root . 'dir/',
            'fresh.md'
        );
        $this->assertNotFalse($copy);
        $this->assertSame('copy me', $copy->get_content());
        $this->assertFalse(
            $fs->file_exists(
                $contextid,
                context_files::COMPONENT,
                context_files::FILEAREA,
                context_files::ITEMID,
                $root,
                'other-area.md'
            )
        );
        $this->assertFalse(
            $fs->file_exists(
                $contextid,
                context_files::COMPONENT,
                context_files::FILEAREA,
                context_files::ITEMID,
                $root,
                'other-component.md'
            )
        );
        $twin = $fs->get_file(
            $contextid,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            $root,
            'twin.md'
        );
        $this->assertSame('kept', $twin->get_content());
    }
}
