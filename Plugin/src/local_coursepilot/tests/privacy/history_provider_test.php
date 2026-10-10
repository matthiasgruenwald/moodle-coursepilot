<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\request\approved_contextlist;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Change history in privacy discovery, export and deletion (#641).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(provider::class)]
final class history_provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass */
    private \stdClass $author;

    /** @var \stdClass */
    private \stdClass $other;

    /** @var \stdClass */
    private \stdClass $cm;

    /** @var \stdClass Second activity, never part of an approved context. */
    private \stdClass $othercm;

    /** @var int File metadata linked by states of both users. */
    private int $sharedfileid;

    /** @var int File metadata linked only by the author's state. */
    private int $authorfileid;

    /**
     * Two activities; captured states of the author removed, then synthetic
     * states: author and other user on $cm, author on $othercm.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->author = $this->getDataGenerator()->create_user();
        $this->other = $this->getDataGenerator()->create_user();
        $pages = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $this->cm = get_coursemodule_from_instance('page', $pages->create_instance(['course' => $course->id])->id);
        $this->othercm = get_coursemodule_from_instance('page', $pages->create_instance(['course' => $course->id])->id);
        $DB->delete_records('local_coursepilot_cm_version_file');
        $DB->delete_records('local_coursepilot_cm_version');
        $DB->delete_records('local_coursepilot_cm_file');

        $this->sharedfileid = $this->add_file('mod_page', 'intro', 'shared.png');
        $this->authorfileid = $this->add_file('mod_page', 'intro', 'author.png');
        $submissionfileid = $this->add_file('assignsubmission_file', 'submission_files', 'student.pdf');
        $this->add_version($this->cm, 1, $this->author->id, [$this->sharedfileid, $this->authorfileid, $submissionfileid]);
        $this->add_version($this->cm, 2, $this->other->id, [$this->sharedfileid]);
        $this->add_version($this->othercm, 1, $this->author->id, [$this->authorfileid]);
    }

    /**
     * @param string $component
     * @param string $filearea
     * @param string $filename
     * @return int
     */
    private function add_file(string $component, string $filearea, string $filename): int {
        global $DB;
        return (int) $DB->insert_record('local_coursepilot_cm_file', (object) [
            'pathnamehash' => sha1($component . $filearea . $filename), 'contenthash' => sha1($filename),
            'component' => $component, 'filearea' => $filearea, 'itemid' => 0, 'filepath' => '/',
            'filename' => $filename, 'filesize' => 10, 'mimetype' => null, 'timemodified' => 100,
        ]);
    }

    /**
     * @param \stdClass $cm
     * @param int $version
     * @param int $userid
     * @param int[] $fileids
     * @return int
     */
    private function add_version(\stdClass $cm, int $version, int $userid, array $fileids): int {
        global $DB;
        $versionid = (int) $DB->insert_record('local_coursepilot_cm_version', (object) [
            'cmid' => $cm->id, 'courseid' => $cm->course, 'version' => $version, 'source' => 'moodle',
            'userid' => $userid, 'moduleinfo_json' => '{"intro":"secret design text"}',
            'coursemodule_json' => '{}', 'timecreated' => 1000 + $version,
        ]);
        foreach ($fileids as $fileid) {
            $DB->insert_record('local_coursepilot_cm_version_file', (object) [
                'versionid' => $versionid, 'fileid' => $fileid, 'gap' => 0,
            ]);
        }
        return $versionid;
    }

    /**
     * @param int $userid
     * @param \stdClass $cm
     * @return int
     */
    private function count_versions(int $userid, \stdClass $cm): int {
        global $DB;
        return $DB->count_records('local_coursepilot_cm_version', ['userid' => $userid, 'cmid' => $cm->id]);
    }

    /** Count of version_file links pointing at no existing state. */
    private function dangling_links(): int {
        global $DB;
        return $DB->count_records_sql('SELECT COUNT(1) FROM {local_coursepilot_cm_version_file} vf
            LEFT JOIN {local_coursepilot_cm_version} v ON v.id = vf.versionid WHERE v.id IS NULL');
    }

    public function test_discovery_finds_authors_in_real_module_contexts(): void {
        global $DB;
        // A state whose activity and context no longer exist is not a real context.
        $DB->insert_record('local_coursepilot_cm_version', (object) [
            'cmid' => 999999, 'courseid' => 1, 'version' => 1, 'source' => 'moodle', 'userid' => $this->other->id,
            'moduleinfo_json' => '{}', 'coursemodule_json' => '{}', 'timecreated' => 1,
        ]);
        $ctx = \context_module::instance($this->cm->id);
        $otherctx = \context_module::instance($this->othercm->id);

        $authorcontexts = array_map('intval', provider::get_contexts_for_userid($this->author->id)->get_contextids());
        $othercontexts = array_map('intval', provider::get_contexts_for_userid($this->other->id)->get_contextids());
        sort($authorcontexts);

        $this->assertSame([(int) $ctx->id, (int) $otherctx->id], $authorcontexts);
        $this->assertSame([(int) $ctx->id], $othercontexts);

        $userlist = new userlist($ctx, 'local_coursepilot');
        provider::get_users_in_context($userlist);
        $userids = array_map('intval', $userlist->get_userids());
        sort($userids);
        $this->assertSame([(int) $this->author->id, (int) $this->other->id], $userids);

        $courselist = new userlist(\context_course::instance($this->cm->course), 'local_coursepilot');
        provider::get_users_in_context($courselist);
        $this->assertSame([], $courselist->get_userids());
    }

    public function test_export_contains_only_own_states_and_allowed_file_metadata(): void {
        $ctx = \context_module::instance($this->cm->id);

        $this->export_context_data_for_user($this->author->id, $ctx, 'local_coursepilot');

        $data = writer::with_context($ctx)->get_data([
            get_string('pluginname', 'local_coursepilot'), get_string('historytitle', 'local_coursepilot'),
        ]);
        $this->assertCount(1, $data->versions);
        $this->assertSame(1, $data->versions[0]->version);
        $filenames = array_column($data->versions[0]->files, 'filename');
        sort($filenames);
        $this->assertSame(['author.png', 'shared.png'], $filenames);
        $encoded = json_encode($data);
        $this->assertStringNotContainsString('student.pdf', $encoded);
        $this->assertStringNotContainsString('secret design text', $encoded);
    }

    public function test_delete_for_user_removes_own_states_in_approved_context_only(): void {
        global $DB;
        $ctx = \context_module::instance($this->cm->id);

        provider::delete_data_for_user(new approved_contextlist($this->author, 'local_coursepilot', [$ctx->id]));

        $this->assertSame(0, $this->count_versions($this->author->id, $this->cm));
        $this->assertSame(1, $this->count_versions($this->other->id, $this->cm));
        $this->assertSame(1, $this->count_versions($this->author->id, $this->othercm));
        $this->assertSame(0, $this->dangling_links());
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_file', ['id' => $this->sharedfileid]));
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_file', ['id' => $this->authorfileid]));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['filename' => 'student.pdf']));
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $this->cm->id]));
    }

    public function test_delete_for_users_removes_only_approved_users(): void {
        $ctx = \context_module::instance($this->cm->id);

        provider::delete_data_for_users(new approved_userlist($ctx, 'local_coursepilot', [$this->author->id]));

        $this->assertSame(0, $this->count_versions($this->author->id, $this->cm));
        $this->assertSame(1, $this->count_versions($this->other->id, $this->cm));
        $this->assertSame(1, $this->count_versions($this->author->id, $this->othercm));

        provider::delete_data_for_users(new approved_userlist(
            $ctx,
            'local_coursepilot',
            [$this->author->id, $this->other->id]
        ));

        $this->assertSame(0, $this->count_versions($this->other->id, $this->cm));
        $this->assertSame(1, $this->count_versions($this->author->id, $this->othercm));
        $this->assertSame(0, $this->dangling_links());
    }

    public function test_context_deletion_removes_whole_history_there_and_keeps_shared_metadata(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context(\context_module::instance($this->cm->id));

        $this->assertSame(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $this->cm->id]));
        $this->assertSame(1, $this->count_versions($this->author->id, $this->othercm));
        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', ['id' => $this->sharedfileid]));
        $this->assertTrue($DB->record_exists('local_coursepilot_cm_file', ['id' => $this->authorfileid]));
        $this->assertSame(0, $this->dangling_links());
        $this->assertTrue($DB->record_exists('course_modules', ['id' => $this->cm->id]));
    }

    public function test_unapproved_contexts_keep_history(): void {
        global $DB;
        $before = $DB->count_records('local_coursepilot_cm_version');

        provider::delete_data_for_user(new approved_contextlist(
            $this->author,
            'local_coursepilot',
            [\context_system::instance()->id, \context_user::instance($this->author->id)->id]
        ));
        provider::delete_data_for_all_users_in_context(\context_course::instance($this->cm->course));
        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'local_coursepilot',
            [$this->author->id]
        ));

        $this->assertSame($before, $DB->count_records('local_coursepilot_cm_version'));
    }

    public function test_private_files_are_left_to_core(): void {
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($this->author->id)->id, 'component' => 'user',
            'filearea' => 'private', 'itemid' => 0, 'filepath' => '/', 'filename' => 'notes.md',
        ], 'x');
        $contextids = provider::get_contexts_for_userid($this->author->id)->get_contextids();

        provider::delete_data_for_user(new approved_contextlist($this->author, 'local_coursepilot', $contextids));
        provider::delete_data_for_all_users_in_context(\context_user::instance($this->author->id));

        $this->assertTrue(get_file_storage()->get_file_by_id($file->get_id()) !== false);
    }

    public function test_metadata_declares_snapshot_fields_and_configurable_retention(): void {
        $tables = [];
        foreach (provider::get_metadata(new collection('local_coursepilot'))->get_collection() as $item) {
            if ($item instanceof database_table) {
                $tables[$item->get_name()] = $item;
            }
        }
        $fields = array_keys($tables['local_coursepilot_cm_version']->get_privacy_fields());
        foreach (
            ['userid', 'version', 'source', 'sourcecmid', 'moduleinfo_json', 'coursemodule_json',
                'arrangement_json'] as $field
        ) {
            $this->assertContains($field, $fields);
        }
        $summary = get_string('privacy:metadata:cm_version', 'local_coursepilot');
        $this->assertStringNotContainsString('at most 1 year', $summary);
        $this->assertStringContainsString('privacy request', $summary);
    }
}
