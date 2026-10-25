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
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\declared_return_types;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact responses, messages and size/permission checks of the material store tools
 * upload_material_file, delete_material_files and report_loose_material_files.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(upload_material_file::class)]
#[CoversClass(delete_material_files::class)]
#[CoversClass(report_loose_material_files::class)]
final class material_store_tools_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /**
     * A user who may manage own files.
     */
    private function setup_user(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * Runs a call under a context restriction to a course.
     *
     * @param callable $call
     * @return \Throwable|null
     */
    private function restricted(callable $call): ?\Throwable {
        $course = $this->getDataGenerator()->create_course();
        external_api::set_context_restriction(\context_course::instance($course->id));
        try {
            return $this->caught($call);
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
    }

    /**
     * Forbids the user to manage own files.
     */
    private function forbid_own_files(): void {
        global $DB, $USER;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/user:manageownfiles', CAP_PROHIBIT, $roleid, \context_user::instance($USER->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();
    }

    public function test_upload_reports_creation_and_overwrite_with_exact_messages(): void {
        $this->setup_user();

        $created = upload_material_file::execute('notes.txt', base64_encode('12345'));
        $overwritten = upload_material_file::execute('notes.txt', base64_encode('1234567'));

        $this->assert_declared_types($created, upload_material_file::execute_returns());
        $this->assertSame(
            ['path' => 'notes.txt', 'created' => true, 'size' => 5, 'message' => 'notes.txt created.'],
            $created
        );
        $this->assertSame(
            ['path' => 'notes.txt', 'created' => false, 'size' => 7,
                'message' => 'notes.txt overwritten (before: 5 bytes, now: 7 bytes).'],
            $overwritten
        );
    }

    public function test_upload_appends_a_quota_warning_to_the_message(): void {
        global $CFG;
        $this->setup_user();
        $CFG->userquota = 1000;

        $result = upload_material_file::execute('notes.txt', base64_encode(str_repeat('x', 990)));

        $this->assertSame(
            'notes.txt created. ' . get_string('materialquotawarning', 'local_coursepilot', '0.0'),
            $result['message']
        );
    }

    public function test_upload_refuses_invalid_base64_with_its_reason(): void {
        $this->setup_user();

        $invalid = $this->caught(fn() => upload_material_file::execute('notes.txt', '***'));

        $this->assertInstanceOf(\invalid_parameter_exception::class, $invalid);
        $this->assertSame('content_base64 is not valid base64.', $invalid->debuginfo);
    }

    /**
     * Calls a private static method of upload_material_file.
     *
     * @param string $method
     * @param mixed ...$arguments
     * @return mixed
     */
    private function call_private(string $method, mixed ...$arguments): mixed {
        return (new \ReflectionMethod(upload_material_file::class, $method))->invoke(null, ...$arguments);
    }

    public function test_the_size_guard_accepts_up_to_the_limit_and_names_both_numbers_above_it(): void {
        $this->setup_user();

        $this->assertNull($this->call_private('guard_size_against_limit', 100, 100));
        $this->assertNull($this->call_private('guard_size_against_limit', 5, 100));
        $this->assertNull($this->call_private('guard_size_against_limit', 500, 0), 'No limit at all.');

        $e = $this->caught(fn() => $this->call_private('guard_size_against_limit', 101, 100));
        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialfiletoolarge', $e->errorcode);
        $this->assertEquals((object) ['size' => 101, 'max' => 100], $e->a);
        $this->assertSame(
            'File too large: 101 bytes, the server allows at most 100 bytes per upload (post_max_size/upload_max_filesize).',
            $e->getMessage()
        );
    }

    public function test_the_server_limit_is_the_one_moodle_reports_for_uploads(): void {
        $this->setup_user();
        $limit = get_max_upload_file_size();
        $this->assertGreaterThan(0, $limit);

        $this->assertNull($this->call_private('guard_server_size_limit', $limit));
        $e = $this->caught(fn() => $this->call_private('guard_server_size_limit', $limit + 1));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertEquals((object) ['size' => $limit + 1, 'max' => $limit], $e->a);
    }

    public function test_the_context_restriction_and_the_file_right_are_checked_before_the_content(): void {
        $this->setup_user();

        $restricted = $this->restricted(fn() => upload_material_file::execute('notes.txt', '***'));
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $this->forbid_own_files();
        $denied = $this->caught(fn() => upload_material_file::execute('notes.txt', '***'));
        $this->assertInstanceOf(\required_capability_exception::class, $denied);
    }

    public function test_delete_reports_every_path_and_the_freed_space(): void {
        $this->setup_user();
        upload_material_file::execute('a.txt', base64_encode(str_repeat('a', 1024 * 1024)));
        upload_material_file::execute('b.txt', base64_encode(str_repeat('b', 1024 * 1024 + 512 * 1024)));
        upload_material_file::execute('c.txt', base64_encode('keep'));

        $result = delete_material_files::execute(['a.txt', 'b.txt']);

        $this->assert_declared_types($result, delete_material_files::execute_returns());
        $this->assertSame([
            'deleted' => ['a.txt', 'b.txt'],
            'freed_bytes' => 1024 * 1024 + 1024 * 1024 + 512 * 1024,
            'path' => 'a.txt, b.txt',
            'message' => '2 file(s) deleted, 2.5 MB freed.',
        ], $result);
        $this->assertNull(\local_coursepilot\material_area::read('a.txt'));
        $this->assertNotNull(\local_coursepilot\material_area::read('c.txt'));
    }

    public function test_the_freed_space_is_divided_by_exactly_one_mebibyte(): void {
        $this->setup_user();
        // Just below and just above the rounding step 1.05 MB (1101004.8 bytes).
        upload_material_file::execute('below.txt', base64_encode(str_repeat('b', 1101004)));
        upload_material_file::execute('above.txt', base64_encode(str_repeat('a', 1101005)));

        $below = delete_material_files::execute(['below.txt']);
        $above = delete_material_files::execute(['above.txt']);

        $this->assertSame('1 file(s) deleted, 1.0 MB freed.', $below['message']);
        $this->assertSame('1 file(s) deleted, 1.1 MB freed.', $above['message']);
    }

    public function test_delete_changes_nothing_when_one_path_is_missing(): void {
        $this->setup_user();
        upload_material_file::execute('a.txt', base64_encode('a'));

        $e = $this->caught(fn() => delete_material_files::execute(['a.txt', 'missing.txt']));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('materialdeletefilenotfound', $e->errorcode);
        $this->assertSame('missing.txt', $e->a);
        $this->assertNotNull(\local_coursepilot\material_area::read('a.txt'));
    }

    public function test_delete_checks_context_and_file_right_first(): void {
        $this->setup_user();

        $restricted = $this->restricted(fn() => delete_material_files::execute(['missing.txt']));
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $this->forbid_own_files();
        $denied = $this->caught(fn() => delete_material_files::execute(['missing.txt']));
        $this->assertInstanceOf(\required_capability_exception::class, $denied);
    }

    public function test_loose_files_are_listed_with_size_age_and_the_remaining_quota(): void {
        global $CFG, $DB;
        $this->setup_user();
        $CFG->userquota = 0;
        upload_material_file::execute('old.txt', base64_encode('12345'));
        upload_material_file::execute('sub/new.txt', base64_encode('1234567'));
        $DB->set_field_select('files', 'timecreated', time() - 3 * DAYSECS - 7200, "filename = 'old.txt'");
        upload_material_file::execute('almost.txt', base64_encode('1'));
        $DB->set_field_select('files', 'timecreated', time() - (int) (2.6 * DAYSECS), "filename = 'almost.txt'");
        upload_material_file::execute('future.txt', base64_encode('1'));
        $DB->set_field_select('files', 'timecreated', time() + 3600, "filename = 'future.txt'");

        $result = report_loose_material_files::execute();

        $this->assert_declared_types($result, report_loose_material_files::execute_returns());
        $byname = array_column($result['files'], null, 'path');
        $this->assertEqualsCanonicalizing(['old.txt', 'sub/new.txt', 'almost.txt', 'future.txt'], array_keys($byname));
        $this->assertSame(2, $byname['almost.txt']['age_days'], 'Days are counted down, not rounded.');
        $this->assertSame(0, $byname['future.txt']['age_days'], 'A file from the future is zero days old.');
        $this->assertSame(5, $byname['old.txt']['size']);
        $this->assertSame(3, $byname['old.txt']['age_days']);
        $this->assertSame(0, $byname['sub/new.txt']['age_days']);
        $this->assertSame(sha1('1234567'), $byname['sub/new.txt']['contenthash']);
        $this->assertSame(14, $result['total_size']);
        $this->assertNull($result['remaining_quota_mb']);

        $used = (int) file_get_user_used_space();
        $CFG->userquota = $used + 5 * 1048576;
        $this->assertSame('5.0', report_loose_material_files::execute()['remaining_quota_mb']);
        // Just below and just above the rounding step 1.05 MB (1101004.8 bytes).
        $CFG->userquota = $used + 1101004;
        $this->assertSame('1.0', report_loose_material_files::execute()['remaining_quota_mb']);
        $CFG->userquota = $used + 1101005;
        $this->assertSame('1.1', report_loose_material_files::execute()['remaining_quota_mb']);
    }

    public function test_report_checks_context_and_file_right_first(): void {
        $this->setup_user();

        $restricted = $this->restricted(fn() => report_loose_material_files::execute());
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $this->forbid_own_files();
        $denied = $this->caught(fn() => report_loose_material_files::execute());
        $this->assertInstanceOf(\required_capability_exception::class, $denied);
    }
}
