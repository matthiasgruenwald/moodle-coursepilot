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
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_coursepilot\context_files;
use local_coursepilot\oauth_lib;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Voller Privacy-Provider (#345): Verbindungen/Tokens (#338), Kontextdateien
 * (#343) und der Nachweis, dass Protokollereignisse (#339) durch Moodle-Cores
 * eigenen logstore_standard-Provider abgedeckt sind statt hier verdoppelt zu
 * werden.
 *
 * Basisklasse `\core_privacy\tests\provider_testcase` ist Moodles eigener
 * Testhelper fuer Privacy-Provider - das erfuellt das Akzeptanzkriterium
 * "Moodle-eigene Datenschutz-Tests laufen fuer das Plugin durch".
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {

    /**
     * Der Datenschutz-Provider benennt den externen Ablageort per
     * `add_external_location_link` (Issue #500, ADR 0021, Spec #486 §11) -
     * eine Auskunft darf ihn nicht verschweigen, auch wenn Coursepilot dort
     * selbst nichts exportiert (das WebDAV-Ziel liegt ausserhalb Moodles).
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
     * Legt einen OAuth-Token-Datensatz an (analog zu oauth_lib_test).
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
        $record->id = $DB->insert_record('local_coursepilot_oauth_token', $record);
        $record->accesstoken = $accesstoken;
        $record->refreshtoken = $refreshtoken;
        return $record;
    }

    /**
     * Legt eine Kontextdatei im privaten Nutzerkontext an, wie es
     * classes/context_files.php tut.
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
     * Auskunft enthaelt die Verbindungen und Tokens der Person (#338, weiter
     * gruen zu halten - keine Regression durch diese Erweiterung).
     */
    public function test_export_contains_oauth_connections_and_tokens(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $DB->insert_record('local_coursepilot_oauth_code', (object) [
            'code' => 'code-'.$user->id,
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

        // Kein Zugangsgeheimnis im Klartext in der Auskunft.
        $encoded = json_encode($data);
        $this->assertStringNotContainsString($token->accesstoken, $encoded);
        $this->assertStringNotContainsString($token->refreshtoken, $encoded);
    }

    /**
     * Legt einen Eintrag im Markierungsgedaechtnis an (Issue #493), wie es
     * classes/mark_memory.php tut.
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
     * Auskunft enthaelt die Kontextdateien der Person - die eigentliche
     * Luecke, die dieses Issue schliesst.
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
     * get_users_in_context findet die Eigentuemerin eines Nutzerkontexts nur,
     * wenn dort tatsaechlich Kontextdateien liegen - kein blinder Treffer auf
     * jeden beliebigen Nutzerkontext.
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
     * Der Nutzerkontext taucht auch dann auf, wenn dort nur Eintraege im
     * Markierungsgedaechtnis liegen, keine Kontextdateien (Issue #493) - das
     * Gedaechtnis fuehrt der Datenschutz-Provider mit.
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
     * get_users_in_context findet dieselbe Person ueber das
     * Markierungsgedaechtnis, auch ohne Kontextdateien.
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
     * Die Auskunft enthaelt die Eintraege des Markierungsgedaechtnisses -
     * nie den Dateiinhalt, nur Pfad und Bit.
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
     * Loeschung entfernt auch das Markierungsgedaechtnis der betroffenen
     * Person, laesst eine zweite Person unberuehrt.
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
     * Loeschung entfernt Verbindungen/Tokens UND Kontextdateien der
     * betroffenen Person, laesst eine zweite Person unberuehrt.
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
     * Bulk-Loeschung (approved_userlist, core_userlist_provider) entfernt die
     * Kontextdateien fuer den Nutzerkontext.
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
     * delete_data_for_all_users_in_context loescht auf Nutzerkontextebene
     * ebenfalls die Kontextdateien.
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
     * Nachweis fuer das Protokollereignis-Kriterium: die Ereignisse tragen
     * die Merkmale (userid, contextid, component), die Moodle-Cores eigener
     * logstore_standard-Provider fuer Export/Loeschung braucht - kein
     * eigener, mit Core kollidierender Export-/Loeschcode in local_coursepilot.
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
