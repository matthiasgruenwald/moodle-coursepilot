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

namespace local_coursepilot\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\database_table;
use core_privacy\local\metadata\types\subsystem_link;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\request\approved_contextlist;
use local_coursepilot\context_files;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The four system-context stores (OAuth codes, tokens, connections, workbench tickets) and the
 * declared metadata in privacy discovery, export and deletion: each store on its own, the right
 * user only, and no reaction to foreign contexts.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_stores_test extends \core_privacy\tests\provider_testcase {
    /** @var string[] The system-context stores. */
    private const STORES = [
        'local_coursepilot_oauth_code', 'local_coursepilot_oauth_token', 'local_coursepilot_oauth_grant',
        'local_coursepilot_workbench_ticket',
    ];

    /**
     * Each system-context store.
     *
     * @return array<string, array{string}>
     */
    public static function stores(): array {
        return array_combine(self::STORES, array_map(static fn (string $s): array => [$s], self::STORES));
    }

    /**
     * Inserts one row of a store for a user.
     *
     * @param string $store
     * @param int $userid
     * @return int id of the row
     */
    private function seed(string $store, int $userid): int {
        global $DB;

        $rows = [
            'local_coursepilot_oauth_code' => [
                'code' => 'code-' . $userid . '-' . random_int(1, 999999), 'clientid' => 'client-code', 'userid' => $userid,
                'redirecturi' => 'https://example.test/cb', 'codechallenge' => str_repeat('c', 43), 'expires' => 4000, 'used' => 1,
            ],
            'local_coursepilot_oauth_token' => [
                'connectionid' => 1, 'accesstokenhash' => bin2hex(random_bytes(32)),
                'refreshtokenhash' => bin2hex(random_bytes(32)),
                'clientid' => 'client-token', 'userid' => $userid, 'expires' => 5000, 'refreshexpires' => 6000, 'revoked' => 0,
                'timecreated' => 1000,
            ],
            'local_coursepilot_oauth_grant' => [
                'userid' => $userid, 'clientid' => 'client-grant', 'revoked' => 1, 'statehash' => bin2hex(random_bytes(32)),
                'timecreated' => 2000,
            ],
            'local_coursepilot_workbench_ticket' => [
                'userid' => $userid, 'path' => 'plan/lernen.md', 'contenthash' => sha1('x'),
                'tickethash' => bin2hex(random_bytes(32)),
                'oauthconnectionid' => 1, 'oauthtokenid' => 1, 'expires' => 7000, 'timecreated' => 3000,
            ],
        ];
        return (int) $DB->insert_record($store, (object) $rows[$store]);
    }

    /**
     * An approved context list; core declares its id array as a class named int, so the ids pass untyped.
     *
     * @param \stdClass $user
     * @param mixed $contextids
     * @return approved_contextlist
     */
    private function approved_contexts(\stdClass $user, mixed $contextids): approved_contextlist {
        return new approved_contextlist($user, 'local_coursepilot', $contextids);
    }

    /**
     * An approved user list, with the same untyped id array as {@see self::approved_contexts()}.
     *
     * @param \context $context
     * @param mixed $userids
     * @return approved_userlist
     */
    private function approved_users(\context $context, mixed $userids): approved_userlist {
        return new approved_userlist($context, 'local_coursepilot', $userids);
    }

    /**
     * The data the test writer recorded for a context and subcontext path.
     *
     * @param \context $context
     * @param string[] $path
     * @return mixed
     */
    private function exported(\context $context, array $path): mixed {
        /** @var \core_privacy\tests\request\content_writer $writer */
        $writer = writer::with_context($context);
        return $writer->get_data($path);
    }

    /**
     * The declared metadata names every table, field and string the privacy report relies on.
     */
    public function test_metadata_declares_every_table_with_its_fields(): void {
        $items = provider::get_metadata(new collection('local_coursepilot'))->get_collection();

        $declared = [];
        foreach ($items as $item) {
            if ($item instanceof database_table) {
                $declared[$item->get_name()] = [array_keys($item->get_privacy_fields()), $item->get_summary()];
            } else if ($item instanceof subsystem_link) {
                $declared[$item->get_name()] = [array_keys($item->get_privacy_fields()), $item->get_summary()];
            }
        }

        $this->assertSame([
            'local_coursepilot_oauth_code' => [
                ['clientid', 'userid', 'redirecturi', 'codechallenge', 'expires', 'used'], 'privacy:metadata:oauth_code',
            ],
            'local_coursepilot_oauth_grant' => [
                ['userid', 'clientid', 'revoked', 'timecreated'], 'privacy:metadata:oauth_grant',
            ],
            'local_coursepilot_oauth_token' => [
                ['connectionid', 'clientid', 'userid', 'expires', 'refreshexpires', 'revoked', 'timecreated'],
                'privacy:metadata:oauth_token',
            ],
            'core_files' => [[], 'privacy:metadata:core_files'],
            'local_coursepilot_cm_version' => [
                ['cmid', 'courseid', 'version', 'source', 'sourcecmid', 'userid', 'moduleinfo_json', 'coursemodule_json',
                    'arrangement_json', 'timecreated'],
                'privacy:metadata:cm_version',
            ],
            'local_coursepilot_cm_version_file' => [['versionid', 'fileid', 'gap'], 'privacy:metadata:cm_version_file'],
            'local_coursepilot_cm_file' => [
                ['pathnamehash', 'contenthash', 'filepath', 'filename', 'filesize', 'timemodified'], 'privacy:metadata:cm_file',
            ],
            'local_coursepilot_context_mark' => [['userid', 'path', 'ismarked'], 'privacy:metadata:context_mark'],
            'local_coursepilot_workbench_ticket' => [
                ['userid', 'path', 'contenthash', 'oauthtokenid', 'oauthconnectionid', 'expires', 'timecreated'],
                'privacy:metadata:workbench_ticket',
            ],
        ], $declared);
    }

    /**
     * A user holding data in any single store is found in the system context; one without is not.
     *
     * @param string $store
     */
    #[DataProvider('stores')]
    public function test_contexts_for_user_include_system_for_each_store_alone(string $store): void {
        $this->resetAfterTest();
        $holder = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $this->seed($store, (int) $holder->id);

        $holderids = array_map('intval', provider::get_contexts_for_userid((int) $holder->id)->get_contextids());
        $bystanderids = array_map('intval', provider::get_contexts_for_userid((int) $bystander->id)->get_contextids());

        $this->assertSame([(int) SYSCONTEXTID], $holderids);
        $this->assertSame([], $bystanderids);
    }

    /**
     * The system context lists the holders of every store, and the other contexts do not list them.
     *
     * @param string $store
     */
    #[DataProvider('stores')]
    public function test_users_in_system_context_cover_each_store(string $store): void {
        $this->resetAfterTest();
        $holder = $this->getDataGenerator()->create_user();
        $bystander = $this->getDataGenerator()->create_user();
        $this->seed($store, (int) $holder->id);

        $system = new userlist(\context_system::instance(), 'local_coursepilot');
        provider::get_users_in_context($system);
        $this->assertSame([(int) $holder->id], array_map('intval', $system->get_userids()));

        $own = new userlist(\context_user::instance($bystander->id), 'local_coursepilot');
        provider::get_users_in_context($own);
        $this->assertSame([], $own->get_userids());
    }

    /**
     * A user context lists its owner only if the owner has marks, never because another user has some;
     * a context of another type is not read as a module.
     */
    public function test_users_in_user_and_foreign_context_do_not_leak(): void {
        global $DB;

        $this->resetAfterTest();
        $marked = $this->getDataGenerator()->create_user();
        $plain = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_coursepilot_context_mark', (object) [
            'userid' => $marked->id, 'path' => 'a.md', 'pathhash' => sha1('a.md'), 'filesize' => 1, 'timemodified' => 1,
            'etag' => 'e', 'ismarked' => 1,
        ]);
        // A history row whose activity id equals the plain user's id must not make a user context look like that module.
        $DB->insert_record('local_coursepilot_cm_version', (object) [
            'cmid' => $plain->id, 'courseid' => $course->id, 'version' => 1, 'source' => 'moodle', 'userid' => $marked->id,
            'moduleinfo_json' => '{}', 'coursemodule_json' => '{}', 'timecreated' => 1,
        ]);

        $plainlist = new userlist(\context_user::instance($plain->id), 'local_coursepilot');
        provider::get_users_in_context($plainlist);
        $this->assertSame([], $plainlist->get_userids());

        $coursectx = \context_course::instance($course->id);
        $DB->set_field('local_coursepilot_cm_version', 'cmid', $course->id);
        $courselist = new userlist($coursectx, 'local_coursepilot');
        provider::get_users_in_context($courselist);
        $this->assertSame([], $courselist->get_userids());
    }

    /**
     * A mark-only user is listed once with the right id, and a user context with only a directory is empty.
     */
    public function test_user_with_only_directory_entries_is_not_listed(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_user::instance($user->id);
        get_file_storage()->create_directory(
            $context->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            $user->id
        );

        $userlist = new userlist($context, 'local_coursepilot');
        provider::get_users_in_context($userlist);

        $this->assertSame([], $userlist->get_userids());
        $this->assertSame([], provider::get_contexts_for_userid((int) $user->id)->get_contextids());
    }

    /**
     * The system export carries every field of every store, in the documented shape.
     */
    public function test_system_export_contains_each_store_with_its_fields(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        foreach (self::STORES as $store) {
            $this->seed($store, (int) $user->id);
            $this->seed($store, (int) $other->id);
        }
        $grantid = $this->seed('local_coursepilot_oauth_grant', (int) $user->id);

        $this->export_context_data_for_user($user->id, \context_system::instance(), 'local_coursepilot');
        $data = $this->exported(\context_system::instance(), [get_string('pluginname', 'local_coursepilot')]);

        $this->assertSame(['oauth_codes', 'oauth_tokens', 'oauth_connections', 'workbench_tickets'], array_keys((array) $data));
        $this->assertEquals([(object) [
            'clientid' => 'client-code', 'redirecturi' => 'https://example.test/cb',
            'expires' => transform::datetime(4000), 'used' => transform::yesno(true),
        ]], $data->oauth_codes);
        $this->assertEquals([(object) [
            'clientid' => 'client-token', 'expires' => transform::datetime(5000), 'refreshexpires' => transform::datetime(6000),
            'revoked' => transform::yesno(false), 'timecreated' => transform::datetime(1000),
        ]], $data->oauth_tokens);
        $this->assertCount(2, $data->oauth_connections);
        $this->assertEquals((object) [
            'id' => $grantid, 'clientid' => 'client-grant', 'revoked' => transform::yesno(true),
            'timecreated' => transform::datetime(2000),
        ], $data->oauth_connections[1]);
        $this->assertEquals([(object) [
            'path' => 'plan/lernen.md', 'expires' => transform::datetime(7000), 'timecreated' => transform::datetime(3000),
        ]], $data->workbench_tickets);
    }

    /**
     * Only the user's own context is exported as user data; a foreign context of the same numeric id is not.
     */
    public function test_export_writes_marks_only_into_the_users_own_context(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $DB->insert_record('local_coursepilot_context_mark', (object) [
            'userid' => $course->id, 'path' => 'a.md', 'pathhash' => sha1('a.md'), 'filesize' => 1, 'timemodified' => 1,
            'etag' => 'e', 'ismarked' => 1,
        ]);
        $fakeuser = (object) ['id' => $course->id];

        provider::export_user_data($this->approved_contexts($fakeuser, [$coursectx->id]));

        /** @var \core_privacy\tests\request\content_writer $exported */
        $exported = writer::with_context($coursectx);
        $this->assertFalse($exported->has_any_data());
    }

    /**
     * Deleting all data of a system context empties each store; other users' rows go too, nothing else is touched.
     *
     * @param string $store
     */
    #[DataProvider('stores')]
    public function test_delete_all_in_system_context_empties_each_store(string $store): void {
        global $DB;

        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $this->seed($store, (int) $first->id);
        $this->seed($store, (int) $second->id);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertSame(0, $DB->count_records($store));
    }

    /**
     * Foreign contexts leave files, marks and history of the plugin alone.
     */
    public function test_delete_all_in_course_context_touches_nothing(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $filerecord = [
            'contextid' => $coursectx->id, 'component' => context_files::LEGACY_COMPONENT,
            'filearea' => context_files::LEGACY_FILEAREA, 'itemid' => context_files::ITEMID,
            'filepath' => '/coursepilot/', 'filename' => 'bleibt.md',
        ];
        get_file_storage()->create_file_from_string($filerecord, 'inhalt');
        $DB->insert_record('local_coursepilot_cm_version', (object) [
            'cmid' => $course->id, 'courseid' => $course->id, 'version' => 1, 'source' => 'moodle', 'userid' => 2,
            'moduleinfo_json' => '{}', 'coursemodule_json' => '{}', 'timecreated' => 1,
        ]);

        provider::delete_data_for_all_users_in_context($coursectx);
        provider::delete_data_for_users($this->approved_users($coursectx, [2]));
        provider::delete_data_for_user($this->approved_contexts((object) ['id' => $course->id], [$coursectx->id]));

        $this->assertTrue(get_file_storage()->file_exists(
            $coursectx->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'bleibt.md'
        ));
        $this->assertSame(1, $DB->count_records('local_coursepilot_cm_version'));
    }

    /**
     * Deleting for a user in the system context removes that user's rows of every store and nobody else's.
     *
     * @param string $store
     */
    #[DataProvider('stores')]
    public function test_delete_for_user_removes_each_store_of_that_user_only(string $store): void {
        global $DB;

        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->seed($store, (int) $target->id);
        $this->seed($store, (int) $other->id);

        provider::delete_data_for_user($this->approved_contexts($target, [SYSCONTEXTID]));

        $this->assertFalse($DB->record_exists($store, ['userid' => $target->id]));
        $this->assertTrue($DB->record_exists($store, ['userid' => $other->id]));
    }

    /**
     * Without the system context in the approved list the stores stay untouched.
     *
     * @param string $store
     */
    #[DataProvider('stores')]
    public function test_delete_for_user_without_system_context_keeps_the_stores(string $store): void {
        global $DB;

        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_user();
        $this->seed($store, (int) $target->id);

        provider::delete_data_for_user($this->approved_contexts($target, [\context_user::instance($target->id)->id]));

        $this->assertTrue($DB->record_exists($store, ['userid' => $target->id]));
    }

    /**
     * Bulk deletion in the system context removes the listed users' rows of every store.
     *
     * @param string $store
     */
    #[DataProvider('stores')]
    public function test_delete_for_users_removes_each_store_of_the_listed_users_only(string $store): void {
        global $DB;

        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->seed($store, (int) $target->id);
        $this->seed($store, (int) $other->id);

        provider::delete_data_for_users(
            $this->approved_users(\context_system::instance(), [$target->id])
        );

        $this->assertFalse($DB->record_exists($store, ['userid' => $target->id]));
        $this->assertTrue($DB->record_exists($store, ['userid' => $other->id]));
    }

    /**
     * Every approved context is exported: the user context does not stop the system context after it.
     */
    public function test_export_covers_all_approved_contexts_of_the_user(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $usercontext = \context_user::instance($user->id);
        foreach ([$user->id, $other->id] as $id) {
            $DB->insert_record('local_coursepilot_context_mark', (object) [
                'userid' => $id, 'path' => "mark-$id.md", 'pathhash' => sha1("mark-$id.md"), 'filesize' => 1,
                'timemodified' => 1, 'etag' => 'e', 'ismarked' => 0,
            ]);
        }
        $this->seed('local_coursepilot_oauth_grant', (int) $user->id);

        provider::export_user_data($this->approved_contexts($user, [$usercontext->id, \context_system::instance()->id]));

        $marks = $this->exported($usercontext, [
            get_string('pluginname', 'local_coursepilot'), get_string('privacy:metadata:context_mark', 'local_coursepilot'),
        ]);
        $this->assertEquals(
            [(object) ['path' => "mark-{$user->id}.md", 'ismarked' => transform::yesno(false)]],
            $marks->entries
        );
        $system = $this->exported(\context_system::instance(), [get_string('pluginname', 'local_coursepilot')]);
        $this->assertCount(1, $system->oauth_connections);
    }

    /**
     * Deleting for a user touches only that user's own user context, not a foreign context with the same id
     * and not another person's user context.
     */
    public function test_delete_for_user_spares_foreign_contexts_and_foreign_user_contexts(): void {
        global $DB;

        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $othercontext = \context_user::instance($other->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $othercontext->id, 'component' => context_files::LEGACY_COMPONENT,
            'filearea' => context_files::LEGACY_FILEAREA, 'itemid' => context_files::ITEMID,
            'filepath' => '/coursepilot/', 'filename' => 'fremd.md',
        ], 'inhalt');
        foreach ([$other->id, $course->id] as $id) {
            $DB->insert_record('local_coursepilot_context_mark', (object) [
                'userid' => $id, 'path' => "m-$id.md", 'pathhash' => sha1("m-$id.md"), 'filesize' => 1,
                'timemodified' => 1, 'etag' => 'e', 'ismarked' => 1,
            ]);
        }
        $fakeuser = (object) ['id' => $course->id];

        provider::delete_data_for_user($this->approved_contexts($target, [$othercontext->id]));
        provider::delete_data_for_user($this->approved_contexts($fakeuser, [\context_course::instance($course->id)->id]));

        $this->assertTrue(get_file_storage()->file_exists(
            $othercontext->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'fremd.md'
        ));
        $this->assertSame(2, $DB->count_records('local_coursepilot_context_mark'));
    }

    /**
     * Deleting a user's data in a module context removes that user's history states there and no one else's.
     */
    public function test_delete_for_user_in_a_module_context_removes_only_own_states(): void {
        global $DB;

        $this->resetAfterTest();
        $author = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id);
        foreach ([[$author->id, 10], [$other->id, 11]] as [$userid, $version]) {
            $DB->insert_record('local_coursepilot_cm_version', (object) [
                'cmid' => $cm->id, 'courseid' => $course->id, 'version' => $version, 'source' => 'moodle', 'userid' => $userid,
                'moduleinfo_json' => '{}', 'coursemodule_json' => '{}', 'timecreated' => 1,
            ]);
        }

        provider::delete_data_for_user($this->approved_contexts($author, [\context_module::instance($cm->id)->id]));

        $remaining = array_map('intval', $DB->get_fieldset_select('local_coursepilot_cm_version', 'userid', 'cmid = ?', [$cm->id]));
        $this->assertContains((int) $other->id, $remaining);
        $this->assertNotContains((int) $author->id, $remaining);
    }
}
