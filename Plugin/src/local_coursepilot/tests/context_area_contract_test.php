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
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact listing flags, defaults, rethrown errors and best-effort supplementing of the context area.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(context_area::class)]
final class context_area_contract_test extends \advanced_testcase {
    use caught_throwable;
    use webdav_instance_fixture;

    /** @var string A markdown text marked as personal data. */
    private const MARKED = "---\ncoursepilot:\n  personenbezug: true\n---\nText\n";

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Logs in a user who owns private files; personal data is allowed until a test switches it off.
     */
    private function user(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        set_config('allowpersonaldata', 1, 'local_coursepilot');
    }

    public function test_only_marked_markdown_files_are_locked_and_folders_never(): void {
        $this->user();
        context_area::write('plain.md', "Text\n");
        context_area::write('marked.md', self::MARKED);
        private_files_storage_port::write_content('/' . storage_anchor::ANCHOR_DEFAULT_ROOT . '/', 'UPPER.MD', self::MARKED);
        context_area::write('sub/inner.md', self::MARKED);
        set_config('allowpersonaldata', 0, 'local_coursepilot');

        $root = context_area::list('');
        $byname = array_column($root['entries'], null, 'name');

        $this->assertSame('', $root['directory']);
        $this->assertFalse($byname['plain.md']['locked']);
        $this->assertTrue($byname['marked.md']['locked']);
        $this->assertTrue($byname['UPPER.MD']['locked']);
        $this->assertSame('folder', $byname['sub']['type']);
        $this->assertFalse($byname['sub']['locked']);
        $this->assertSame('', $byname['sub']['contenthash']);
        $this->assertNotSame('', $byname['marked.md']['contenthash']);
        $this->assertArrayNotHasKey('checksum', $byname['marked.md']);
    }

    public function test_a_marked_file_in_a_subfolder_is_found_by_its_relative_path(): void {
        $this->user();
        context_area::write('sub/inner.md', self::MARKED);
        set_config('allowpersonaldata', 0, 'local_coursepilot');

        $listing = context_area::list('sub');

        $this->assertSame('sub', $listing['directory']);
        $this->assertTrue($listing['entries'][0]['locked']);
        $this->assertSame('inner.md', $listing['entries'][0]['name']);
    }

    public function test_personal_data_allowed_unlocks_everything(): void {
        $this->user();
        context_area::write('marked.md', self::MARKED);

        $entries = context_area::list('')['entries'];

        $this->assertFalse($entries[0]['locked']);
    }

    public function test_writing_without_options_overwrites_and_reports_the_state(): void {
        $this->user();

        $first = context_area::write('a.md', 'one');
        $second = context_area::write('a.md', 'three');

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(3, $first['size']);
        $this->assertSame(5, $second['size']);
        $this->assertSame(3, $second['oldsize']);
        $this->assertSame('three', context_area::read('a.md')['content']);
    }

    public function test_a_pointer_that_cannot_be_resolved_stops_the_write_with_its_own_error(): void {
        $this->user();
        private_files_storage_port::write_content(
            '/' . storage_anchor::ANCHOR_DEFAULT_ROOT . '/',
            storage_anchor::POINTER_FILENAME,
            '{"context_area":"x"}'
        );

        $write = $this->caught(fn() => context_area::write('a.md', 'x'));
        $append = $this->caught(fn() => context_area::append('a.md', 'x'));

        foreach ([$write, $append] as $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
            $this->assertSame('pointerincomplete', $e->errorcode);
        }
    }

    public function test_supplementing_creates_only_what_is_missing_and_survives_failures(): void {
        $this->user();
        context_area::write('keep.md', 'mine');

        $created = context_area::supplement_missing([
            'keep.md' => 'template',
            'new.md' => 'fresh',
            'bad name.txt' => 'refused by the name policy',
            'later.md' => 'also fresh',
        ]);

        $this->assertSame(['new.md', 'later.md'], $created);
        $this->assertSame('mine', context_area::read('keep.md')['content']);
        $this->assertSame('fresh', context_area::read('new.md')['content']);
        $this->assertSame('also fresh', context_area::read('later.md')['content']);
    }

    public function test_a_failing_external_write_records_the_pending_note_without_a_course(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->grant_webdav_capability($USER);
        $instanceid = $this->create_webdav_instance($USER, ['webdav_path' => '']);
        $this->write_v2_pointer($USER, 'context_area', $instanceid, 'Ctx', [
            'server' => $this->fixtureserver,
            'basepath' => '',
            'account' => $this->fixturekonto,
        ]);
        $fake = new fake_webdav_transport();
        $fake->seed_folder('/Ctx');
        $fake->deny_auth(401);
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);

        $e = $this->caught(fn() => context_area::write('a.md', 'x'));
        $a = $this->caught(fn() => context_area::append('a.md', 'x'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('pendingwritefailed', $e->errorcode);
        $this->assertInstanceOf(\moodle_exception::class, $a);
        $this->assertSame('pendingwritefailed', $a->errorcode);
        $entries = pending_write_notice::list_grouped();
        $this->assertSame(0, $entries[0]['entries'][0]['course_id']);
        $this->assertSame(0, $entries[0]['entries'][1]['course_id'] ?? 0);
    }
}
