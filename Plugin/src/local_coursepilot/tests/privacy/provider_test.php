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
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursepilot\context_files;
use local_coursepilot\oauth_lib;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Complete privacy provider (#345): connections/tokens (#338), context
 * files (#343) and proof that core logstore_standard handles audit events
 * (#339) without duplicate exports. Moodle’s
 * core_privacy\tests\provider_testcase supplies the native privacy tests.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Declare external storage using add_external_location_link (#500,
     * ADR 0021, Spec #486 §11), even though Coursepilot cannot export
     * WebDAV data outside Moodle.
     */
    public function test_metadata_declares_the_external_webdav_location(): void {
        $collection = provider::get_metadata(new collection('local_coursepilot'));

        $externallocations = array_values(array_filter(
            $collection->get_collection(),
            static fn ($type): bool => $type instanceof external_location
        ));

        $this->assertCount(1, $externallocations);
        $this->assertSame('webdav_external_storage', $externallocations[0]->get_name());
        $this->assertSame('privacy:metadata:webdav_external_storage', $externallocations[0]->get_summary());
        $this->assertArrayHasKey('path', $externallocations[0]->get_privacy_fields());
        $this->assertArrayHasKey('content', $externallocations[0]->get_privacy_fields());
    }

    /**
     * Create an OAuth token record, as in oauth_lib_test.
     *
     * @param int $userid
     * @param string $clientid
     * @return \stdClass
     */
    private function issue_token(int $userid, string $clientid = 'test-client'): \stdClass {
        global $DB;

        $accesstoken = oauth_lib::random_token(32);
        $refreshtoken = oauth_lib::random_token(32);
        $record = new \stdClass();
        $record->accesstokenhash = hash('sha256', $accesstoken);
        $record->refreshtokenhash = hash('sha256', $refreshtoken);
        $record->clientid = $clientid;
        $record->userid = $userid;
        $record->expires = time() + oauth_lib::ACCESS_TOKEN_TTL;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = time();
        $record->connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $record->userid, 'clientid' => $record->clientid, 'revoked' => $record->revoked,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => $record->timecreated,
        ]);
        $record->id = $DB->insert_record('local_coursepilot_oauth_token', $record);
        $record->accesstoken = $accesstoken;
        $record->refreshtoken = $refreshtoken;
        return $record;
    }

    /**
     * Create context files in the private user context, as context_files does.
     *
     * @param int $userid
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    private function create_context_file(int $userid, string $filename, string $content = 'Inhalt'): \stored_file {
        $fs = get_file_storage();
        $context = \context_user::instance($userid);
        $filerecord = [
            'contextid' => $context->id,
            'component' => context_files::LEGACY_COMPONENT,
            'filearea' => context_files::LEGACY_FILEAREA,
            'itemid' => context_files::ITEMID,
            'filepath' => '/coursepilot/',
            'filename' => $filename,
        ];
        return $fs->create_file_from_string($filerecord, $content);
    }

    /**
     * Exports preserve user connections and tokens without regressions (#338).
     */
    public function test_export_contains_oauth_connections_and_tokens(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $DB->insert_record('local_coursepilot_oauth_code', (object) [
            'code' => 'code-' . $user->id,
            'clientid' => 'test-client',
            'userid' => $user->id,
            'redirecturi' => 'https://example.test/callback',
            'codechallenge' => str_repeat('a', 43),
            'expires' => time() + 600,
            'used' => 0,
        ]);
        $token = $this->issue_token($user->id);

        $this->export_context_data_for_user($user->id, \context_system::instance(), 'local_coursepilot');

        $data = writer::with_context(\context_system::instance())->get_data([get_string('pluginname', 'local_coursepilot')]);
        $this->assertNotEmpty($data->oauth_codes);
        $this->assertNotEmpty($data->oauth_tokens);
        $this->assertSame('test-client', $data->oauth_tokens[0]->clientid);

        // Exports contain no plaintext access secrets.
        $encoded = json_encode($data);
        $this->assertStringNotContainsString($token->accesstoken, $encoded);
        $this->assertStringNotContainsString($token->refreshtoken, $encoded);
    }

    /**
     * Create a mark-cache entry as mark_memory does (#493).
     *
     * @param int $userid
     * @param string $path
     * @param bool $marked
     * @return \stdClass
     */
    private function create_mark_entry(int $userid, string $path, bool $marked = true): \stdClass {
        global $DB;

        $record = (object) [
            'userid' => $userid,
            'path' => $path,
            'pathhash' => sha1($path),
            'filesize' => 42,
            'timemodified' => 100,
            'etag' => 'etag-1',
            'ismarked' => $marked ? 1 : 0,
        ];
        $record->id = $DB->insert_record('local_coursepilot_context_mark', $record);
        return $record;
    }

    /**
     * Exports include the user’s context files, closing this issue’s gap.
     */
    public function test_export_contains_context_files(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_context_file($user->id, 'vorlagen.md');

        $context = \context_user::instance($user->id);
        $contextlist = $this->get_contexts_for_userid($user->id, 'local_coursepilot');
        $this->assertContains($context->id, array_map('intval', $contextlist->get_contextids()));

        $this->export_context_data_for_user($user->id, $context, 'local_coursepilot');

        $files = writer::with_context($context)->get_files([get_string('pluginname', 'local_coursepilot')]);
        $this->assertCount(1, $files);
        $this->assertSame('vorlagen.md', reset($files)->get_filename());
    }

    /**
     * Find context owners only when actual context files exist, rather
     * than matching every user context.
     */
    public function test_get_users_in_context_requires_actual_files(): void {
        $this->resetAfterTest();
        $withfile = $this->getDataGenerator()->create_user();
        $withoutfile = $this->getDataGenerator()->create_user();
        $this->create_context_file($withfile->id, 'vorlagen.md');

        $userlist = new userlist(\context_user::instance($withfile->id), 'local_coursepilot');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$withfile->id], $userlist->get_userids());

        $emptyuserlist = new userlist(\context_user::instance($withoutfile->id), 'local_coursepilot');
        provider::get_users_in_context($emptyuserlist);
        $this->assertEquals([], $emptyuserlist->get_userids());
    }

    /**
     * Report contexts containing only mark-cache entries even without
     * context files (#493).
     */
    public function test_get_contexts_for_userid_finds_mark_memory_only_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_mark_entry($user->id, 'lerngruppe.md');

        $context = \context_user::instance($user->id);
        $contextlist = $this->get_contexts_for_userid($user->id, 'local_coursepilot');

        $this->assertContains($context->id, array_map('intval', $contextlist->get_contextids()));
    }

    /**
     * Find the same user through mark-cache entries without context files.
     */
    public function test_get_users_in_context_finds_mark_memory_only_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_mark_entry($user->id, 'lerngruppe.md');

        $userlist = new userlist(\context_user::instance($user->id), 'local_coursepilot');
        provider::get_users_in_context($userlist);

        $this->assertEquals([$user->id], $userlist->get_userids());
    }

    /**
     * Export mark-cache paths and flags, never file content.
     */
    public function test_export_contains_mark_memory_entries(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_mark_entry($user->id, 'lerngruppe.md');
        $context = \context_user::instance($user->id);

        $this->export_context_data_for_user($user->id, $context, 'local_coursepilot');

        $data = writer::with_context($context)->get_data(
            [get_string('pluginname', 'local_coursepilot'), get_string('privacy:metadata:context_mark', 'local_coursepilot')]
        );
        $this->assertNotEmpty($data->entries);
        $this->assertSame('lerngruppe.md', $data->entries[0]->path);
    }

    /**
     * Delete the target user’s mark cache while preserving another user’s data.
     */
    public function test_delete_data_for_user_removes_mark_memory_and_spares_others(): void {
        global $DB;
        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_mark_entry($target->id, 'lerngruppe.md');
        $this->create_mark_entry($other->id, 'lerngruppe.md');

        $contextlist = $this->get_contexts_for_userid($target->id, 'local_coursepilot');
        $approvedcontextlist = new \core_privacy\tests\request\approved_contextlist(
            \core_user::get_user($target->id),
            'local_coursepilot',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approvedcontextlist);

        $this->assertFalse($DB->record_exists('local_coursepilot_context_mark', ['userid' => $target->id]));
        $this->assertTrue($DB->record_exists('local_coursepilot_context_mark', ['userid' => $other->id]));
    }

    /**
     * Delete target connections, tokens and context files while preserving
     * another user’s data.
     */
    public function test_delete_data_for_user_removes_all_bestaende_and_spares_others(): void {
        global $DB;

        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->issue_token($target->id);
        $this->issue_token($other->id);
        $this->create_context_file($target->id, 'vorlagen.md');
        $this->create_context_file($other->id, 'notiz.md');

        $contextlist = $this->get_contexts_for_userid($target->id, 'local_coursepilot');
        $approvedcontextlist = new \core_privacy\tests\request\approved_contextlist(
            \core_user::get_user($target->id),
            'local_coursepilot',
            $contextlist->get_contextids()
        );
        provider::delete_data_for_user($approvedcontextlist);

        $this->assertFalse($DB->record_exists('local_coursepilot_oauth_token', ['userid' => $target->id]));
        $this->assertTrue($DB->record_exists('local_coursepilot_oauth_token', ['userid' => $other->id]));

        $fs = get_file_storage();
        $this->assertFalse($fs->file_exists(
            \context_user::instance($target->id)->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'vorlagen.md'
        ));
        $this->assertTrue($fs->file_exists(
            \context_user::instance($other->id)->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'notiz.md'
        ));
    }

    /**
     * Bulk deletion via approved_userlist/core_userlist_provider removes
     * context files in the user context.
     */
    public function test_delete_data_for_users_removes_context_files(): void {
        $this->resetAfterTest();
        $target = $this->getDataGenerator()->create_user();
        $this->create_context_file($target->id, 'vorlagen.md');
        $context = \context_user::instance($target->id);

        $approveduserlist = new approved_userlist($context, 'local_coursepilot', [$target->id]);
        provider::delete_data_for_users($approveduserlist);

        $fs = get_file_storage();
        $this->assertFalse($fs->file_exists(
            $context->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'vorlagen.md'
        ));
    }

    /**
     * delete_data_for_all_users_in_context removes user-context files too.
     */
    public function test_delete_data_for_all_users_in_context_removes_context_files(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_context_file($user->id, 'vorlagen.md');
        $context = \context_user::instance($user->id);

        provider::delete_data_for_all_users_in_context($context);

        $fs = get_file_storage();
        $this->assertFalse($fs->file_exists(
            $context->id,
            context_files::LEGACY_COMPONENT,
            context_files::LEGACY_FILEAREA,
            context_files::ITEMID,
            '/coursepilot/',
            'vorlagen.md'
        ));
    }

    /**
     * Audit events expose userid, contextid and component for core
     * logstore_standard export/deletion. Coursepilot adds no conflicting
     * export or deletion code.
     */
    public function test_access_log_events_carry_attribution_for_core_logstore_handling(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $sink = $this->redirectEvents();

        \local_coursepilot\access_log::log_success('coursepilot_list_courses');

        $event = $sink->get_events()[0];
        $this->assertSame((int) $user->id, (int) $event->userid);
        $this->assertSame(\context_system::instance()->id, $event->contextid);
        $this->assertSame('local_coursepilot', $event->component);
        $sink->close();
    }
}
