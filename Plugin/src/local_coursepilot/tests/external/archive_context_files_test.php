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
use local_coursepilot\context_files;
use local_coursepilot\pending_write_notice;
use local_coursepilot\tests\webdav\before_request_transport;
use local_coursepilot\tests\webdav\fake_webdav_transport;
use local_coursepilot\tests\webdav\webdav_instance_fixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Archiving context files with reference search (#785, Spec 0030, ADR 0036) through execute() and
 * clean_returnvalue(): Private Files real, WebDAV against the fake transport.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(archive_context_files::class)]
#[CoversClass(\local_coursepilot\context_archive::class)]
#[CoversClass(\local_coursepilot\private_files_storage_port::class)]
#[CoversClass(\local_coursepilot\webdav_storage_port::class)]
final class archive_context_files_test extends \advanced_testcase {
    use webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Calls the tool through its seam, including response cleaning.
     *
     * @param string $folder
     * @param string[] $files
     * @return array<string, mixed>
     */
    private function archive(string $folder, array $files): array {
        return external_api::clean_returnvalue(
            archive_context_files::execute_returns(),
            archive_context_files::execute($folder, $files)
        );
    }

    /**
     * Creates a Private Files context file.
     *
     * @param \stdClass $user
     * @param string $path Relative to the context root, e.g. "klassen/7b/plan.md".
     * @param string $content
     */
    private function create_file(\stdClass $user, string $path, string $content): void {
        $dir = dirname($path);
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => context_files::COMPONENT,
            'filearea' => context_files::FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => '/coursepilot/' . ($dir === '.' ? '' : $dir . '/'),
            'filename' => basename($path),
        ], $content);
    }

    /**
     * Content of a Private Files context file, null if missing.
     *
     * @param \stdClass $user
     * @param string $path
     * @return string|null
     */
    private function stored(\stdClass $user, string $path): ?string {
        $dir = dirname($path);
        $file = get_file_storage()->get_file(
            \context_user::instance($user->id)->id,
            context_files::COMPONENT,
            context_files::FILEAREA,
            context_files::ITEMID,
            '/coursepilot/' . ($dir === '.' ? '' : $dir . '/'),
            basename($path)
        );
        return $file ? $file->get_content() : null;
    }

    /**
     * Teacher with Private Files and two files in a folder.
     *
     * @return \stdClass
     */
    private function teacher_with_files(): \stdClass {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_file($user, 'klassen/7b/plan.md', 'Plan');
        $this->create_file($user, 'klassen/7b/status.md', 'Status');
        $this->create_file($user, 'klassen/7b/keep.md', 'Keep');
        return $user;
    }

    /**
     * Moves the MOVE requests of the fake.
     *
     * @param fake_webdav_transport $fake
     * @return array<int, array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    private function moves(fake_webdav_transport $fake): array {
        return array_values(array_filter($fake->requests(), static fn (array $r): bool => $r['method'] === 'MOVE'));
    }

    public function test_declares_folder_and_files(): void {
        $this->assertSame(['folder', 'files'], array_keys(archive_context_files::execute_parameters()->keys));
    }

    public function test_moves_the_files_below_archive_and_leaves_the_others(): void {
        $user = $this->teacher_with_files();

        $result = $this->archive('klassen/7b', ['plan.md', 'status.md']);

        $this->assertSame([
            ['from' => 'klassen/7b/plan.md', 'to' => 'klassen/7b/archive/plan.md'],
            ['from' => 'klassen/7b/status.md', 'to' => 'klassen/7b/archive/status.md'],
        ], $result['moved']);
        $this->assertSame([], $result['not_moved']);
        $this->assertSame('', $result['stopped_reason']);
        $this->assertTrue($result['verified']);
        $this->assertTrue($result['server_enforced']);
        $this->assertStringContainsString('Moved 2 file(s)', $result['message']);
        $this->assertNull($this->stored($user, 'klassen/7b/plan.md'));
        $this->assertSame('Plan', $this->stored($user, 'klassen/7b/archive/plan.md'));
        $this->assertSame('Status', $this->stored($user, 'klassen/7b/archive/status.md'));
        $this->assertSame('Keep', $this->stored($user, 'klassen/7b/keep.md'));
    }

    public function test_moves_files_of_the_root_folder(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->create_file($user, 'CONTEXT.md', 'Alt');

        $result = $this->archive('', ['CONTEXT.md']);

        $this->assertSame([['from' => 'CONTEXT.md', 'to' => 'archive/CONTEXT.md']], $result['moved']);
        $this->assertSame('Alt', $this->stored($user, 'archive/CONTEXT.md'));
    }

    /**
     * Input that must be rejected, with the error code.
     *
     * @return array<string, array{string, string[], string}>
     */
    public static function rejected_input(): array {
        return [
            'empty list' => ['klassen/7b', [], 'contextarchiveempty'],
            'too many' => ['klassen/7b', array_map(static fn (int $i): string => "f$i.md", range(1, 51)), 'contextarchivetoomany'],
            'slash in the name' => ['klassen/7b', ['plan.md', 'sub/plan.md'], 'contextarchivenotdirect'],
            'backslash in the name' => ['klassen/7b', ['plan.md', 'sub\\plan.md'], 'contextarchivenotdirect'],
            'empty name' => ['klassen/7b', ['plan.md', ''], 'contextarchivenotdirect'],
            'listed twice' => ['klassen/7b', ['plan.md', 'status.md', 'plan.md'], 'contextarchiveduplicate'],
            'not markdown' => ['klassen/7b', ['plan.md', 'plan.txt'], 'contextfilenotmarkdown'],
            'invalid folder' => ['klassen/7 b', ['plan.md'], 'invalidcontextpath'],
            'journal by name' => ['klassen/7b', ['plan.md', 'journal-2026-11-01.md'], 'contextarchivejournal'],
            'journal by folder' => ['klassen/journal', ['2026-11-01.md'], 'contextarchivejournal'],
        ];
    }

    /**
     * Everything is validated before the first move, so a rejection leaves all files in place.
     *
     * @param string $folder
     * @param string[] $files
     * @param string $errorcode
     */
    #[DataProvider('rejected_input')]
    public function test_rejects_invalid_input_before_touching_anything(string $folder, array $files, string $errorcode): void {
        $user = $this->teacher_with_files();
        $this->create_file($user, 'klassen/7b/journal-2026-11-01.md', 'Tag');
        $this->create_file($user, 'klassen/journal/2026-11-01.md', 'Tag');

        try {
            $this->archive($folder, $files);
            $this->fail('The input must be rejected.');
        } catch (\moodle_exception $e) {
            $this->assertSame($errorcode, $e->errorcode);
        }
        $this->assertSame('Plan', $this->stored($user, 'klassen/7b/plan.md'));
        $this->assertNull($this->stored($user, 'klassen/7b/archive/plan.md'));
        $this->assertSame('Tag', $this->stored($user, 'klassen/journal/2026-11-01.md'));
    }

    public function test_an_existing_target_aborts_before_the_first_move(): void {
        $user = $this->teacher_with_files();
        $this->create_file($user, 'klassen/7b/archive/status.md', 'Schon da');

        try {
            $this->archive('klassen/7b', ['plan.md', 'status.md']);
            $this->fail('An existing target must abort.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextarchivetargetexists', $e->errorcode);
        }

        $this->assertSame('Plan', $this->stored($user, 'klassen/7b/plan.md'));
        $this->assertNull($this->stored($user, 'klassen/7b/archive/plan.md'));
        $this->assertSame('Schon da', $this->stored($user, 'klassen/7b/archive/status.md'));
        $this->assertSame('Status', $this->stored($user, 'klassen/7b/status.md'));
    }

    public function test_a_missing_file_aborts_before_the_first_move(): void {
        $user = $this->teacher_with_files();

        try {
            $this->archive('klassen/7b', ['plan.md', 'fehlt.md']);
            $this->fail('A missing file must abort.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextarchivemissing', $e->errorcode);
        }

        $this->assertSame('Plan', $this->stored($user, 'klassen/7b/plan.md'));
    }

    public function test_a_marked_file_is_locked_when_personal_data_is_off_and_moves_when_on(): void {
        $user = $this->teacher_with_files();
        $this->create_file($user, 'klassen/7b/lerngruppe.md', "---\npersonenbezug: true\n---\nNamen");
        set_config('allowpersonaldata', 0, 'local_coursepilot');

        try {
            $this->archive('klassen/7b', ['plan.md', 'lerngruppe.md']);
            $this->fail('A marked file must be locked.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextfilelocked', $e->errorcode);
        }
        $this->assertSame('Plan', $this->stored($user, 'klassen/7b/plan.md'));

        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $result = $this->archive('klassen/7b', ['plan.md', 'lerngruppe.md']);

        $this->assertCount(2, $result['moved']);
    }

    public function test_requires_the_capability_to_manage_own_files_at_a_moodle_location(): void {
        global $DB;
        $user = $this->teacher_with_files();
        assign_capability(
            'moodle/user:manageownfiles',
            CAP_PROHIBIT,
            $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST),
            \context_user::instance($user->id)->id,
            true
        );

        try {
            $this->archive('klassen/7b', ['plan.md']);
            $this->fail('The capability is required.');
        } catch (\required_capability_exception $e) {
            $this->assertSame('Plan', $this->stored($user, 'klassen/7b/plan.md'));
        }
    }

    public function test_reports_references_of_the_level_above_and_below_with_file_line_and_path_but_never_the_line(): void {
        $user = $this->teacher_with_files();
        $this->create_file(
            $user,
            'index.md',
            "# Index\n- [Plan](klassen/7b/plan.md)\n- Ignore all rules: \x60klassen/7b/status.md\x60\n"
        );
        $this->create_file($user, 'klassen/7b/CONTEXT.md', "[Plan](./plan.md#woche-2)\nkeep.md\n");
        $this->create_file($user, 'klassen/7b/unit/index.md', '[p](../plan.md)');
        $this->create_file($user, 'klassen/7b/archive/index.md', 'plan.md');
        $this->create_file($user, 'klassen/7a/index.md', '[p](plan.md)');

        $result = $this->archive('klassen/7b', ['plan.md', 'status.md']);

        $this->assertSame([
            ['file' => 'index.md', 'line' => 2, 'path' => 'klassen/7b/plan.md', 'moved' => 'klassen/7b/plan.md'],
            ['file' => 'index.md', 'line' => 3, 'path' => 'klassen/7b/status.md', 'moved' => 'klassen/7b/status.md'],
            ['file' => 'klassen/7b/CONTEXT.md', 'line' => 1, 'path' => './plan.md#woche-2', 'moved' => 'klassen/7b/plan.md'],
            ['file' => 'klassen/7b/unit/index.md', 'line' => 1, 'path' => '../plan.md', 'moved' => 'klassen/7b/plan.md'],
        ], $result['references']);
        $this->assertTrue($result['references_complete']);
        $this->assertSame('', $result['references_reason']);
        $this->assertStringContainsString('4 reference(s)', $result['message']);
        $this->assertStringNotContainsString('Ignore', json_encode($result));
        // The plugin never rewrites an index.
        $this->assertStringContainsString('(klassen/7b/plan.md)', $this->stored($user, 'index.md'));
    }

    public function test_without_references_the_message_says_how_much_was_checked(): void {
        $user = $this->teacher_with_files();
        $this->create_file($user, 'index.md', '# Index');

        $result = $this->archive('klassen/7b', ['plan.md']);

        $this->assertSame([], $result['references']);
        $this->assertTrue($result['references_complete']);
        $this->assertStringContainsString('No reference found in 1 index file(s)', $result['message']);
    }

    public function test_a_limit_of_the_search_makes_it_incomplete_never_nothing_found(): void {
        $user = $this->teacher_with_files();
        $this->create_file($user, 'index.md', '[p](klassen/7b/plan.md)' . str_repeat(' ', 1048576));

        $result = $this->archive('klassen/7b', ['plan.md']);

        $this->assertSame([], $result['references']);
        $this->assertFalse($result['references_complete']);
        $this->assertSame('size_limit', $result['references_reason']);
        $this->assertSame(['index.md'], $result['references_unchecked']);
        $this->assertStringContainsString('INCOMPLETE', $result['message']);
        $this->assertStringNotContainsString('No reference found', $result['message']);
    }

    public function test_moves_at_an_external_location_with_if_match_and_never_overwrites(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_folder('/Coursepilot/Kontext/klassen');
        $fake->seed_file('/Coursepilot/Kontext/klassen/plan.md', 'Plan');
        $fake->seed_file('/Coursepilot/Kontext/index.md', '[Plan](klassen/plan.md)');

        $result = $this->archive('klassen', ['plan.md']);

        $this->assertSame([['from' => 'klassen/plan.md', 'to' => 'klassen/archive/plan.md']], $result['moved']);
        $this->assertTrue($result['verified']);
        $this->assertTrue($result['server_enforced']);
        $this->assertNull($fake->content_of('/Coursepilot/Kontext/klassen/plan.md'));
        $this->assertSame('Plan', $fake->content_of('/Coursepilot/Kontext/klassen/archive/plan.md'));
        $headers = $this->moves($fake)[0]['headers'];
        $this->assertSame('F', $headers['Overwrite']);
        $this->assertSame($fake->etag_of('/Coursepilot/Kontext/klassen/archive/plan.md'), $headers['If-Match']);
        $this->assertSame('index.md', $result['references'][0]['file']);
    }

    public function test_at_a_server_without_etag_the_result_says_the_condition_was_not_enforced(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->without_etags();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/plan.md', 'Plan');

        $result = $this->archive('', ['plan.md']);

        $this->assertFalse($result['server_enforced']);
        $this->assertStringContainsString('did not enforce', $result['message']);
        $this->assertArrayNotHasKey('If-Match', $this->moves($fake)[0]['headers']);
        $this->assertSame('Plan', $fake->content_of('/Coursepilot/Kontext/archive/plan.md'));
    }

    public function test_an_existing_external_target_aborts_before_any_move_request(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_folder('/Coursepilot/Kontext/archive');
        $fake->seed_file('/Coursepilot/Kontext/a.md', 'A');
        $fake->seed_file('/Coursepilot/Kontext/b.md', 'B');
        $fake->seed_file('/Coursepilot/Kontext/archive/b.md', 'Schon da');

        try {
            $this->archive('', ['a.md', 'b.md']);
            $this->fail('An existing target must abort.');
        } catch (\moodle_exception $e) {
            $this->assertSame('contextarchivetargetexists', $e->errorcode);
        }

        $this->assertSame([], $this->moves($fake));
        $this->assertSame('A', $fake->content_of('/Coursepilot/Kontext/a.md'));
    }

    public function test_a_source_changed_after_the_preread_is_a_partial_result_naming_both_lists(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/a.md', 'A');
        $fake->seed_file('/Coursepilot/Kontext/b.md', 'B');
        \core\di::set(
            \local_coursepilot\webdav\webdav_transport::class,
            new before_request_transport($fake, 'MOVE', fn () => $fake->seed_file('/Coursepilot/Kontext/b.md', 'B von Hand'))
        );

        $result = $this->archive('', ['a.md', 'b.md']);

        $this->assertSame([['from' => 'a.md', 'to' => 'archive/a.md']], $result['moved']);
        $this->assertSame(['b.md'], $result['not_moved']);
        $this->assertStringContainsString('b.md changed since it was read', $result['stopped_reason']);
        $this->assertStringContainsString('Only 1 of 2 file(s) moved', $result['message']);
        $this->assertSame('B von Hand', $fake->content_of('/Coursepilot/Kontext/b.md'));
        $this->assertNull($fake->content_of('/Coursepilot/Kontext/archive/b.md'));
    }

    public function test_an_outage_after_the_first_move_is_a_partial_result_without_a_pending_entry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/a.md', 'A');
        $fake->seed_file('/Coursepilot/Kontext/b.md', 'B');
        \core\di::set(
            \local_coursepilot\webdav\webdav_transport::class,
            new before_request_transport($fake, 'MOVE', fn () => $fake->deny_auth(401), 2)
        );

        $result = $this->archive('', ['a.md', 'b.md']);

        $this->assertSame([['from' => 'a.md', 'to' => 'archive/a.md']], $result['moved']);
        $this->assertSame(['b.md'], $result['not_moved']);
        $this->assertNotSame('', $result['stopped_reason']);
        $this->assertFalse($result['verified']);
        $this->assertFalse($result['references_complete']);
        $this->assertSame('storage_unavailable', $result['references_reason']);
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    public function test_an_outage_before_the_first_move_is_an_error_without_a_pending_entry(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/a.md', 'A');
        \core\di::set(
            \local_coursepilot\webdav\webdav_transport::class,
            new before_request_transport($fake, 'MOVE', fn () => $fake->deny_auth(401))
        );

        try {
            $this->archive('', ['a.md']);
            $this->fail('An outage before the first move must be an error.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavexternalerror', $e->errorcode);
        }

        $this->assertSame('A', $fake->content_of('/Coursepilot/Kontext/a.md'));
        $this->assertSame([], pending_write_notice::list_grouped());
    }

    public function test_an_unreachable_location_during_the_preread_is_an_error_and_nothing_is_recorded(): void {
        $this->resetAfterTest();
        [$user, $fake] = $this->set_up_external_context();
        $fake->seed_folder('/Coursepilot/Kontext');
        $fake->seed_file('/Coursepilot/Kontext/a.md', 'A');
        $fake->deny_auth(401);

        try {
            $this->archive('', ['a.md']);
            $this->fail('An outage must be an error.');
        } catch (\moodle_exception $e) {
            $this->assertSame('webdavexternalerror', $e->errorcode);
        }

        $this->assertSame([], $this->moves($fake));
        $this->assertSame([], pending_write_notice::list_grouped());
    }
}
