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

use local_coursepilot\history\version_history;
use local_coursepilot\tests\native_override;
use local_coursepilot\tests\recording_database;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/native_override_functions.php');

/**
 * Derivations, types and boundaries of the OAuth token lifecycle, download tickets and version metadata (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(connection::class)]
#[CoversClass(workbench_ticket::class)]
#[CoversClass(version_history::class)]
final class oauth_tokens_674_last24_contract_test extends \advanced_testcase {
    /** @var string Redirect URI of the test client. */
    private const REDIRECT = 'https://client.example/cb';

    /** @var string PKCE challenge of the verifier "abc". */
    private const CHALLENGE = 'ungWv48Bz-pBQUDeXa4iI7ADYaOWF3qctBD_YfIAFa0';

    /**
     * Restores the real random_bytes(), random_string() and time().
     */
    protected function tearDown(): void {
        native_override::reset();
        parent::tearDown();
    }

    /**
     * Issues a code and exchanges it for a token pair.
     *
     * @param int $userid Consenting owner.
     * @param string $code Receives the authorization code.
     * @return mixed[] The token response.
     */
    private function exchange(int $userid, string &$code = ''): array {
        $code = connection::issue_code('client', $userid, self::REDIRECT, self::CHALLENGE);
        $tokens = connection::exchange_code($code, 'client', self::REDIRECT, 'abc');
        $this->assertNotNull($tokens);
        return $tokens;
    }

    /**
     * The claim marker of a used code is the SHA-256 of the code, a bar and 16 random bytes.
     */
    public function test_code_claim_marker_derivation(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        native_override::$bytes[16] = str_repeat("\x01\x02", 8);

        $code = '';
        $this->exchange((int) $user->id, $code);

        $expected = hash('sha256', $code . '|' . bin2hex(str_repeat("\x01\x02", 8)));
        $this->assertTrue($DB->record_exists('local_coursepilot_oauth_code', ['code' => $expected, 'used' => 1]));
    }

    /**
     * A new grant stores a 64 character hexadecimal state hash.
     */
    public function test_new_grant_has_a_full_length_state_hash(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();

        $this->exchange((int) $user->id);

        $hash = $DB->get_field('local_coursepilot_oauth_grant', 'statehash', ['userid' => $user->id], MUST_EXIST);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    /**
     * The grant lock marker is 64 hexadecimal characters.
     *
     * Reflection: lock_connection() is private and its marker is overwritten by the caller flow, so only a direct call observes it.
     */
    public function test_lock_marker_is_full_length(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->exchange((int) $user->id);
        $id = (int) $DB->get_field('local_coursepilot_oauth_grant', 'id', ['userid' => $user->id], MUST_EXIST);

        $locked = (new \ReflectionMethod(connection::class, 'lock_connection'))->invoke(null, $id);

        $this->assertTrue($locked);
        $hash = $DB->get_field('local_coursepilot_oauth_grant', 'statehash', ['id' => $id], MUST_EXIST);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    /**
     * A revoked access token is refused while its grant is still active.
     */
    public function test_revoked_access_token_is_refused_with_an_active_grant(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $tokens = $this->exchange((int) $user->id);
        $this->assertSame((int) $user->id, connection::authenticate_access_token($tokens['access_token']));

        $DB->set_field('local_coursepilot_oauth_token', 'revoked', 1, ['userid' => $user->id]);

        $this->assertNull(connection::authenticate_access_token($tokens['access_token']));
    }

    /**
     * An access token is still valid in the very second it expires.
     */
    public function test_access_token_is_valid_at_its_expiry_second(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $tokens = $this->exchange((int) $user->id);
        $DB->set_field('local_coursepilot_oauth_token', 'expires', 2000000000, ['userid' => $user->id]);

        native_override::$now = 2000000000;
        $valid = connection::authenticate_access_token($tokens['access_token']);
        native_override::$now = 2000000001;
        $expired = connection::authenticate_access_token($tokens['access_token']);

        $this->assertSame((int) $user->id, $valid);
        $this->assertNull($expired);
    }

    /**
     * A refresh token can still be rotated in the very second it expires.
     */
    public function test_refresh_token_rotates_at_its_expiry_second(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $tokens = $this->exchange((int) $user->id);
        $DB->set_field('local_coursepilot_oauth_token', 'refreshexpires', 2000000000, ['userid' => $user->id]);

        native_override::$now = 2000000000;
        $rotated = connection::rotate_refresh_token($tokens['refresh_token'], 'client');

        $this->assertNotNull($rotated);
    }

    /**
     * The download ticket claim marker is the SHA-256 of the ticket hash, a bar and 20 random characters.
     *
     * Reflection: claim() deletes the row at once, so only the recorded set_field_select argument shows the marker.
     */
    public function test_ticket_claim_marker_derivation(): void {
        global $DB;
        $this->resetAfterTest();
        native_override::$strings[20] = 'abcdefghij0123456789';
        $realdb = $DB;
        $recorder = new recording_database($realdb);
        $DB = $recorder;
        try {
            (new \ReflectionMethod(workbench_ticket::class, 'claim'))->invoke(null, 'tickethash');
        } finally {
            $DB = $realdb;
        }

        $claims = array_values(array_filter($recorder->calls, static fn(array $call): bool => $call[0] === 'set_field_select'));
        $this->assertCount(1, $claims);
        $this->assertSame(hash('sha256', 'tickethash|abcdefghij0123456789'), $claims[0][1][2]);
    }

    /**
     * The version metadata reports the author and the creation time as integers.
     */
    public function test_version_metadata_has_integer_author_and_time(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        $version = version_history::list_versions($cm->id)['versions'][0];
        $compared = version_history::compare($cm->id, 1, 1);

        $this->assertSame((int) $teacher->id, $version['userid']);
        $this->assertIsInt($version['timestamp']);
        $this->assertSame((int) $teacher->id, $compared['before']['userid']);
        $this->assertIsInt($compared['after']['timestamp']);
    }
}
