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
 * Single-use workbench download tickets (#501, Spec #486 §13).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(workbench_ticket::class)]
#[CoversClass(\local_coursepilot\oauth_lib::class)]
final class workbench_ticket_test extends \advanced_testcase {
    public function setUp(): void {
        parent::setUp();
        oauth_lib::reset_current_token_id();
    }

    public function test_issue_and_redeem_delivers_original_bytes_with_matching_sha1(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'hallo welt');

        $link = workbench_ticket::issue('blatt.pdf');

        $this->assertSame('blatt.pdf', $link['name']);
        $this->assertSame(strlen('hallo welt'), $link['size']);
        $this->assertSame(sha1('hallo welt'), $link['sha1']);
        $this->assertStringContainsString('/local/coursepilot/workbench/download.php?ticket=', $link['url']);

        $this->setUser();
        $delivery = workbench_ticket::redeem($this->secret_from_url($link['url']));

        $this->assertSame('hallo welt', $delivery['content']);
        $this->assertSame(sha1($delivery['content']), $link['sha1'], 'SHA-1 of the response must match the delivered bytes.');
    }

    public function test_second_redemption_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        workbench_ticket::redeem($secret);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('workbenchticketinvalid', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    /**
     * Provides cases for requester provider.
     *
     * @return mixed[]
     */
    public static function requester_provider(): array {
        return ['anonymous' => [false], 'another teacher' => [true]];
    }

    #[DataProvider('requester_provider')]
    public function test_redemption_uses_the_owners_selected_location_without_changing_request_identity(
        bool $anotherteacher
    ): void {
        global $USER;
        $this->resetAfterTest();
        $owner = $this->getDataGenerator()->create_user();
        $this->setUser($owner);
        $this->issue_connection((int) $owner->id);
        storage_anchor::write_pointer_document([
            'context_area' => ['location' => 'moodle', 'path' => 'retained/context'],
            'material_store' => ['location' => 'moodle', 'path' => 'retained/material'],
        ]);
        [$directory, $filename] = material_files::resolve_file('selected.txt');
        get_file_storage()->create_file_from_string(
            material_files::filerecord(material_files::own_context()->id, $directory, $filename),
            'Owner selected bytes'
        );
        $secret = $this->secret_from_url(workbench_ticket::issue('selected.txt')['url']);
        $requester = $anotherteacher ? $this->getDataGenerator()->create_user() : null;
        $this->setUser($requester);
        $requestidentity = $USER;

        $delivery = workbench_ticket::redeem($secret);

        $this->assertSame('Owner selected bytes', $delivery['content']);
        $this->assertSame((int) $owner->id, $delivery['userid']);
        $this->assertSame($requestidentity, $USER);
    }

    public function test_expired_ticket_is_rejected(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);
        $DB->set_field(workbench_ticket::TABLE, 'expires', time() - 1, ['tickethash' => hash('sha256', $secret)]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('workbenchticketexpired', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    public function test_changed_contenthash_is_rejected(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'urspruenglich');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        // Change the file after issuing the ticket but before redemption.
        $this->store('blatt.pdf', 'geaendert', true);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('workbenchticketcontentchanged', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    /**
     * Bind the ticket to its issuing user, not the session active at redemption.
     * Another logged-in teacher with a same-named file cannot receive its contents.
     */
    public function test_ticket_stays_bound_to_issuing_person(): void {
        $this->resetAfterTest();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();

        $this->setUser($teacher);
        $this->issue_connection((int) $teacher->id);
        $this->store('blatt.pdf', 'gehoert teacher');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        $this->setUser($other);
        $this->store('blatt.pdf', 'gehoert other');

        $delivery = workbench_ticket::redeem($secret);

        $this->assertSame('gehoert teacher', $delivery['content']);
        $this->assertSame((int) $teacher->id, $delivery['userid']);
    }

    public function test_remoteaccessdisabled_rejects_redemption(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        set_config('remoteaccessenabled', '0', 'local_coursepilot');

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/' . preg_quote(get_string('remoteaccessdisabled', 'local_coursepilot'), '/') . '/');
        workbench_ticket::redeem($secret);
    }

    public function test_revoked_connection_rejects_redemption(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->store('blatt.pdf', 'inhalt');
        $tokenid = $this->issue_connection((int) $user->id);

        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        $this->assertTrue(oauth_lib::revoke_token($tokenid));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('workbenchticketconnectionrevoked', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    public function test_suspended_account_rejects_redemption(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);

        try {
            workbench_ticket::redeem($secret);
            $this->fail('Erwartete workbench_ticket_redemption_failed ausgeblieben.');
        } catch (workbench_ticket_redemption_failed $e) {
            $this->assertSame('workbenchticketaccountinactive', $e->errorcode);
            $this->assertSame('blatt.pdf', $e->path, 'Failure retains the path for access_log (Spec #486 §13).');
        }
    }

    public function test_deleted_account_rejects_redemption(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        $DB->set_field('user', 'deleted', 1, ['id' => $user->id]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('workbenchticketaccountinactive', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    /**
     * Without an MCP dispatcher/authenticate_access_token() call, oauthtokenid
     * is null. The ticket remains bounded by the user's connections (#512,
     * Spec #486 §13): require any active connection, failing if none remains.
     */
    public function test_redemption_without_a_known_issuing_connection_needs_some_active_connection(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('workbenchticketconnectionrevoked', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    /**
     * Same null-oauthtokenid case with another active connection: redemption succeeds.
     */
    public function test_redemption_without_a_known_issuing_connection_succeeds_with_another_active_connection(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        // Create a connection after issuance without authenticating this request;
        // the ticket's oauthtokenid remains null.
        $this->issue_connection((int) $user->id);
        oauth_lib::reset_current_token_id();

        $delivery = workbench_ticket::redeem($secret);

        $this->assertSame('inhalt', $delivery['content']);
    }

    /**
     * Two redemptions deliver the file at most once (#512). Parallel DB
     * connections are impractical here, so this checks two sequential calls.
     * The next test checks the atomic claim mechanism.
     */
    public function test_concurrent_redemption_delivers_file_at_most_once(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        $first = workbench_ticket::redeem($secret);
        $this->assertSame('inhalt', $first['content'], 'The first redemption must deliver the file.');

        try {
            workbench_ticket::redeem($secret);
            $this->fail('The second concurrent redemption must fail.');
        } catch (workbench_ticket_redemption_failed $e) {
            $this->assertSame(
                'workbenchticketinvalid',
                $e->errorcode,
                'The second redemption must report unknown, like a ticket never issued.'
            );
        }
    }

    /**
     * Test atomic claim safety (#512, review finding). Simulate another
     * connection claiming the same row using the same UPDATE/old tickethash
     * condition as workbench_ticket::claim(). Our subsequent redemption finds
     * no matching row. A SELECT-then-DELETE implementation would incorrectly
     * find the changed row and deliver content.
     */
    public function test_claim_loses_to_a_rival_update_that_already_changed_the_tickethash(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->issue_connection((int) $user->id);
        $this->store('blatt.pdf', 'inhalt');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);
        $hash = hash('sha256', $secret);

        // Simulate a competing connection winning the atomic UPDATE claim
        // that workbench_ticket::claim() would perform.
        $rivalclaim = hash('sha256', 'rival');
        $DB->set_field_select(
            workbench_ticket::TABLE,
            'tickethash',
            $rivalclaim,
            'tickethash = :hash',
            ['hash' => $hash]
        );

        try {
            workbench_ticket::redeem($secret);
            $this->fail('Redemption must fail after the ticket hash changes.');
        } catch (workbench_ticket_redemption_failed $e) {
            $this->assertSame('workbenchticketinvalid', $e->errorcode);
        }

        // Preserve the rival's winning row; our failed attempt neither deletes
        // it nor reads its data.
        $this->assertTrue(
            $DB->record_exists(workbench_ticket::TABLE, ['tickethash' => $rivalclaim]),
            'Failed redemption must preserve the winning row.'
        );
    }

    public function test_cohort_removal_blocks_anonymous_redemption_with_active_token(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $cohort = $this->getDataGenerator()->create_cohort(['contextid' => \context_system::instance()->id]);
        cohort_add_member($cohort->id, $user->id);
        set_config('remoteaccesscohorts', (string) $cohort->id, 'local_coursepilot');
        $tokenid = $this->issue_connection((int) $user->id, false);
        $this->store('blatt.pdf', 'private bytes');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        cohort_remove_member($cohort->id, $user->id);
        $this->setUser();
        $this->assertTrue(oauth_lib::connection_active($tokenid));
        $this->expectException(workbench_ticket_redemption_failed::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('remoteaccessnotgranted', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    public function test_capability_removal_blocks_redemption_despite_granted_request_user(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'editingteacher');
        $roleid = $this->grant_remote_access((int) $user->id);
        $this->setUser($user);
        $tokenid = $this->issue_connection((int) $user->id, false);
        $this->store('blatt.pdf', 'private bytes');
        $secret = $this->secret_from_url(workbench_ticket::issue('blatt.pdf')['url']);

        unassign_capability(remote_access::CAPABILITY, $roleid, \context_system::instance()->id);
        accesslib_clear_all_caches(true);
        $this->setAdminUser();
        $this->assertTrue(remote_access::is_granted());
        $this->assertTrue(oauth_lib::connection_active($tokenid));
        $this->assertTrue(has_capability('local/coursepilot:use', \context_course::instance($course->id), $user->id));
        $this->expectException(workbench_ticket_redemption_failed::class);
        $this->expectExceptionMessageMatches(
            '/' . preg_quote(get_string('remoteaccessnotgranted', 'local_coursepilot'), '/') . '/'
        );
        workbench_ticket::redeem($secret);
    }

    /**
     * Provides grant remote access.
     *
     * @param int $userid The userid.
     * @return int
     */
    private function grant_remote_access(int $userid): int {
        $roleid = create_role('Remote access', 'remote' . $userid, '', '');
        assign_capability(remote_access::CAPABILITY, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $userid, \context_system::instance()->id);
        return $roleid;
    }

    /**
     * Provides secret from url.
     *
     * @param string $url The url.
     * @return string
     */
    private function secret_from_url(string $url): string {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str((string) $query, $params);
        return (string) $params['ticket'];
    }

    /**
     * Provides issue connection.
     *
     * @param int $userid The userid.
     * @param bool $grant The grant.
     * @return int
     */
    private function issue_connection(int $userid, bool $grant = true): int {
        global $DB;

        if ($grant) {
            $this->grant_remote_access($userid);
        }
        $accesstoken = oauth_lib::random_token(32);
        $record = new \stdClass();
        $record->accesstokenhash = hash('sha256', $accesstoken);
        $record->refreshtokenhash = hash('sha256', oauth_lib::random_token(32));
        $record->clientid = 'test-client';
        $record->userid = $userid;
        $record->expires = time() + oauth_lib::ACCESS_TOKEN_TTL;
        $record->refreshexpires = time() + oauth_lib::REFRESH_TOKEN_TTL;
        $record->revoked = 0;
        $record->timecreated = time();
        $record->connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $record->userid, 'clientid' => $record->clientid, 'revoked' => $record->revoked,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => $record->timecreated,
        ]);
        $id = (int) $DB->insert_record('local_coursepilot_oauth_token', $record);

        oauth_lib::authenticate_access_token($accesstoken);

        return $id;
    }

    /**
     * Stores the workbench ticket test.
     *
     * @param string $filename The filename.
     * @param string $content The content.
     * @param bool $overwrite The overwrite.
     */
    private function store(string $filename, string $content, bool $overwrite = false): void {
        $fs = get_file_storage();
        $filerecord = [
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ];
        if ($overwrite) {
            $existing = $fs->get_file(
                $filerecord['contextid'],
                $filerecord['component'],
                $filerecord['filearea'],
                $filerecord['itemid'],
                $filerecord['filepath'],
                $filerecord['filename']
            );
            if ($existing) {
                $existing->delete();
            }
        }
        $fs->create_file_from_string($filerecord, $content);
    }
}
