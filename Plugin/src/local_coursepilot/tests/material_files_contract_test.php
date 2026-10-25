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
use PHPUnit\Framework\Attributes\DataProvider;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact name policy, quota warning, embed limit and helpers of material_files (Spec 0018 §2.4, §6, §8, #496).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(material_files::class)]
final class material_files_contract_test extends \advanced_testcase {
    use tests\caught_throwable;

    /**
     * Calls a private static method.
     *
     * @param string $method
     * @param mixed ...$args
     * @return mixed
     */
    private function call(string $method, mixed ...$args): mixed {
        return (new \ReflectionMethod(material_files::class, $method))->invoke(null, ...$args);
    }

    public function test_the_helpers_other_classes_rely_on_stay_public(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $calls = [
            'area' => [],
            'relative_directory' => [material_files::resolve_directory('')],
            'entry_path' => ['a.pdf'],
        ];

        foreach ($calls as $method => $arguments) {
            // Invoked so that the declaration counts as executed, then checked for its visibility.
            $reflection = new \ReflectionMethod(material_files::class, $method);
            $reflection->invoke(null, ...$arguments);
            $this->assertTrue($reflection->isPublic(), $method);
        }
    }

    public function test_an_entry_names_its_path_as_string_or_as_object_and_nothing_else(): void {
        $this->assertSame('a.pdf', material_files::entry_path('a.pdf'));
        $this->assertSame('b.pdf', material_files::entry_path(['path' => 'b.pdf', 'target_folder' => 'x']));
        foreach ([['target_folder' => 'x'], ['path' => 5], 7, null] as $bad) {
            $e = $this->caught(fn() => material_files::entry_path($bad));
            $this->assertInstanceOf(\moodle_exception::class, $e);
            $this->assertSame('invalidmaterialpath', $e->errorcode);
        }
    }

    /**
     * Provides file names the material area refuses although a part of them looks fine.
     *
     * @return array<string, array{string}>
     */
    public static function refused_names(): array {
        return [
            'space before a valid tail' => ['my file.pdf'],
            'dot inside the base name' => ['a.b-c.pdf'],
            'trailing characters' => ['a.pdf!'],
            'no extension' => ['readme'],
            'extension not allowed' => ['run.exe'],
            'empty base' => ['.pdf'],
        ];
    }

    #[DataProvider('refused_names')]
    public function test_names_outside_the_policy_are_refused_with_name_and_allowed_list(string $filename): void {
        $e = $this->caught(fn() => (material_files::area()->checkwritablename)($filename));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialfiledisallowedtype', $e->errorcode);
        $this->assertSame($filename, $e->a->filename);
        $this->assertSame(implode(', ', material_files::allowed_extensions()), $e->a->allowed);
    }

    public function test_valid_names_pass_whatever_the_case_of_the_extension(): void {
        foreach (['report_1-final.pdf', 'SCAN.PNG', 'a.Docx'] as $filename) {
            $this->assertNull((material_files::area()->checkwritablename)($filename), $filename);
        }
    }

    public function test_the_allowed_extensions_are_one_list_without_repeats(): void {
        $this->assertSame(
            [
                'pdf',
                'docx',
                'doc',
                'xlsx',
                'xls',
                'pptx',
                'ppt',
                'html',
                'htm',
                'png',
                'jpg',
                'jpeg',
                'gif',
                'svg',
                'txt',
                'csv',
                'zip',
                'webp',
            ],
            material_files::allowed_extensions()
        );
    }

    public function test_the_extension_is_compared_in_lower_case_and_must_exist(): void {
        $this->assertTrue(material_files::is_allowed_extension('A.PDF'));
        $this->assertTrue(material_files::is_allowed_extension('b.WebP'));
        $this->assertFalse(material_files::is_allowed_extension('noextension'));
        $this->assertFalse(material_files::is_allowed_extension('trailingdot.'));
    }

    public function test_only_non_folder_entries_on_moodle_keep_their_checksum_as_contenthash(): void {
        $moodle = pointer_location::moodle('/m/');
        $external = pointer_location::external(1, 'x', ['server' => 's', 'basepath' => '/b', 'account' => 'a']);

        $file = $this->call('with_contenthash', ['name' => 'f', 'type' => 'file', 'checksum' => 'abc'], $moodle);
        $untyped = $this->call('with_contenthash', ['name' => 'f', 'checksum' => 'abc'], $moodle);
        $folder = $this->call('with_contenthash', ['name' => 'd', 'type' => 'folder', 'checksum' => 'abc'], $moodle);
        $remote = $this->call('with_contenthash', ['name' => 'f', 'type' => 'file', 'checksum' => 'abc'], $external);

        $this->assertSame(['name' => 'f', 'type' => 'file', 'contenthash' => 'abc'], $file);
        $this->assertSame(['name' => 'f', 'contenthash' => 'abc'], $untyped);
        $this->assertSame(['name' => 'd', 'type' => 'folder', 'contenthash' => ''], $folder);
        $this->assertSame(['name' => 'f', 'type' => 'file', 'contenthash' => ''], $remote);
    }

    public function test_a_folder_that_is_the_context_area_is_marked_by_its_full_path(): void {
        $location = pointer_location::moodle('/m/');
        $nested = pointer_location::moodle('/m/sub/ctx/');
        $top = pointer_location::moodle('/m/ctx/');

        $below = $this->call('mark_context_area_entry', ['name' => 'ctx', 'type' => 'folder'], $location, $nested, 'sub');
        $root = $this->call('mark_context_area_entry', ['name' => 'ctx', 'type' => 'folder'], $location, $top, '');
        $other = $this->call('mark_context_area_entry', ['name' => 'ctx', 'type' => 'folder'], $location, $top, 'sub');
        $file = $this->call('mark_context_area_entry', ['name' => 'ctx', 'type' => 'file'], $location, $top, '');

        $this->assertSame('context_area', $below['type']);
        $this->assertSame('context_area', $root['type']);
        $this->assertSame('folder', $other['type']);
        $this->assertSame('file', $file['type']);
    }

    public function test_the_embed_limit_allows_up_to_and_including_the_maximum(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->maxbytes = '1000';

        $this->call('guard_embed_size', 1000);
        $e = $this->caught(fn() => $this->call('guard_embed_size', 1001));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialembedtoolarge', $e->errorcode);
        $this->assertSame(1001, $e->a->size);
        $this->assertSame(1000, $e->a->max);
    }

    public function test_without_a_configured_maximum_or_with_zero_nothing_is_limited(): void {
        global $CFG;
        $this->resetAfterTest();

        unset($CFG->maxbytes);
        $this->call('guard_embed_size', 5);
        $CFG->maxbytes = 0;
        $this->call('guard_embed_size', PHP_INT_MAX);
        $this->expectNotToPerformAssertions();
    }

    /**
     * Fresh user with a quota of the given size and the remaining bytes.
     *
     * @param int $quota
     * @return int Remaining bytes.
     */
    private function user_with_quota(int $quota): int {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $CFG->userquota = $quota;
        return (int) material_files::remaining_quota();
    }

    public function test_the_quota_warning_names_the_space_left_with_one_decimal(): void {
        $remaining = $this->user_with_quota(104857600);

        $this->assertSame(
            'Note: only 5.0 MB of storage left.',
            material_files::quota_warning($remaining - 5 * 1048576)
        );
        $this->assertSame('Note: only 2.0 MB of storage left.', material_files::quota_warning($remaining - 2149580));
        $this->assertSame('Note: only 2.1 MB of storage left.', material_files::quota_warning($remaining - 2149581));
    }

    public function test_no_warning_while_at_least_a_tenth_is_left(): void {
        $remaining = $this->user_with_quota(104857600);

        $this->assertNull(material_files::quota_warning($remaining - 11 * 1048576));
        $this->assertNull(material_files::quota_warning($remaining - 10485760));
        $this->assertSame('Note: only 10.0 MB of storage left.', material_files::quota_warning($remaining - 10485759));
    }

    public function test_growth_beyond_the_remaining_space_warns_with_zero_not_a_negative_number(): void {
        $remaining = $this->user_with_quota(104857600);

        $this->assertSame('Note: only 0.0 MB of storage left.', material_files::quota_warning($remaining + 5000));
    }

    public function test_with_a_tiny_quota_nothing_left_still_warns(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => 'user',
            'filearea' => 'private',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'fill.txt',
        ], 'ten bytes!');
        $CFG->userquota = 5;

        $this->assertSame('Note: only 0.0 MB of storage left.', material_files::quota_warning(0));
    }

    public function test_exactly_a_tenth_left_is_no_reason_to_warn(): void {
        $remaining = $this->user_with_quota(100);

        $this->assertNull(material_files::quota_warning($remaining - 10));
        $this->assertNotNull(material_files::quota_warning($remaining - 9));
    }

    public function test_users_who_ignore_the_quota_are_never_warned(): void {
        global $DB, $CFG;
        $remaining = $this->user_with_quota(100);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/user:ignoreuserquota', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertNull(material_files::remaining_quota());
        $this->assertNull(material_files::quota_warning($remaining));
        $this->assertSame('100', (string) $CFG->userquota);
    }

    public function test_content_hashes_come_from_every_course_the_user_may_use_even_after_one_that_is_not(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $courses = [
            'first' => $this->getDataGenerator()->create_course(),
            'student' => $this->getDataGenerator()->create_course(),
            'last' => $this->getDataGenerator()->create_course(),
        ];
        $this->getDataGenerator()->enrol_user($user->id, $courses['first']->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($user->id, $courses['student']->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $courses['last']->id, 'editingteacher');
        $hashes = [];
        foreach ($courses as $key => $course) {
            $file = get_file_storage()->create_file_from_string([
                'contextid' => \context_course::instance($course->id)->id,
                'component' => 'course',
                'filearea' => 'legacy',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $key . '.txt',
            ], 'content of ' . $key);
            $hashes[$key] = $file->get_contenthash();
        }
        $this->setUser($user);

        $used = material_files::used_contenthashes();

        $this->assertEqualsCanonicalizing([$hashes['first'], $hashes['last']], $used);
    }

    public function test_a_draft_copy_keeps_the_stored_mime_type(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => 'note.txt',
            'mimetype' => 'application/x-custom',
        ], 'content');

        $draftid = material_files::resolve_into_draft(
            \context_module::instance($assign->cmid)->id,
            'mod_assign',
            'introattachment',
            0,
            ['note.txt']
        );

        $draft = get_file_storage()->get_file(material_files::own_context()->id, 'user', 'draft', $draftid, '/', 'note.txt');
        $this->assertNotFalse($draft);
        $this->assertSame('application/x-custom', $draft->get_mimetype());
    }
}
