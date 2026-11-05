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


namespace local_coursepilot\output;

use local_coursepilot\admin\connection_storage_location;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact rows, URLs and write guards of the administrator connections page.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(admin_connections_page::class)]
final class admin_connections_page_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Issues a connection token for a user.
     *
     * @param \stdClass $user
     * @param string $clientid
     */
    private function connect(\stdClass $user, string $clientid): void {
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = \local_coursepilot\connection::issue_code($clientid, (int) $user->id, 'https://client.example/cb', $challenge);
        \local_coursepilot\connection::exchange_code($code, $clientid, 'https://client.example/cb', $verifier);
    }

    public function test_every_token_becomes_one_exact_row(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(
            ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'email' => 'ada@example.test']
        );
        $this->connect($user, 'unregistered-client');
        $tokens = \local_coursepilot\connection::active_tokens();
        $token = reset($tokens);

        $data = admin_connections_page::page_data($tokens);

        $this->assertSame(['empty', 'rows', 'revokeallurl', 'sesskey', 'revokeallonsubmit'], array_keys($data));
        $this->assertFalse($data['empty']);
        $this->assertCount(1, $data['rows']);
        $row = $data['rows'][0];
        $this->assertSame(
            ['person', 'clientname', 'since', 'expires', 'storagelocationlines', 'revokeurl'],
            array_keys($row)
        );
        $this->assertSame('Ada Lovelace (ada@example.test)', $row['person']);
        $this->assertSame('unregistered-client', $row['clientname']);
        $this->assertSame(userdate($token->timecreated), $row['since']);
        $this->assertSame(userdate($token->expires), $row['expires']);
        $location = connection_storage_location::describe((int) $user->id);
        $expected = array_map(
            static fn(string $line): array => ['text' => $line],
            array_merge(array_values($location['targets']), $location['markers'])
        );
        $this->assertSame($expected, $row['storagelocationlines']);
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/admin/connections.php', ['revoke' => $token->id, 'sesskey' => sesskey()]))->out(
                false
            ),
            $row['revokeurl']
        );
    }

    public function test_a_registered_client_name_wins_over_its_id(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $clientid = \local_coursepilot\oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Classroom client',
            'redirect_uris' => ['https://client.example/cb'],
        ]), '192.0.2.1')['body']['client_id'];
        $this->connect($user, $clientid);

        $data = admin_connections_page::page_data(\local_coursepilot\connection::active_tokens());

        $this->assertSame('Classroom client', $data['rows'][0]['clientname']);
    }

    public function test_the_overview_without_tokens_offers_the_unescaped_revoke_all_form(): void {
        $this->resetAfterTest();

        $data = admin_connections_page::page_data([]);

        $this->assertTrue($data['empty']);
        $this->assertSame([], $data['rows']);
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/admin/connections.php'))->out(false),
            $data['revokeallurl']
        );
        $this->assertSame(sesskey(), $data['sesskey']);
        $this->assertSame(
            'return confirm(' . json_encode(get_string('connectionrevokeallconfirm', 'local_coursepilot')) . ');',
            $data['revokeallonsubmit']
        );
    }

    public function test_only_site_administrators_see_or_change_connections(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $e = $this->caught(fn() => admin_connections_page::response(0, false));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_a_revocation_needs_the_session_key_and_then_redirects_to_the_overview(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->connect($user, 'client');
        $this->connect($user, 'other');
        $tokens = \local_coursepilot\connection::active_tokens();
        $first = reset($tokens);
        $this->setAdminUser();
        $overview = (new \moodle_url('/local/coursepilot/admin/connections.php'))->out(false);

        $one = $this->caught(fn() => admin_connections_page::response((int) $first->id, false));
        $all = $this->caught(fn() => admin_connections_page::response(0, true));

        $this->assertInstanceOf(\moodle_exception::class, $one);
        $this->assertInstanceOf(\moodle_exception::class, $all);
        $this->assertCount(2, \local_coursepilot\connection::active_tokens());

        $_POST['sesskey'] = sesskey();
        try {
            $revoked = admin_connections_page::response((int) $first->id, false);
            $this->assertSame($overview, $revoked->redirecturl->out(false));
            $this->assertCount(1, \local_coursepilot\connection::active_tokens());
            $everything = admin_connections_page::response(0, true);
            $this->assertSame($overview, $everything->redirecturl->out(false));
            $this->assertCount(0, \local_coursepilot\connection::active_tokens());
        } finally {
            unset($_POST['sesskey']);
        }
    }

    public function test_the_overview_is_the_template_with_the_current_tokens(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $response = admin_connections_page::response(0, false);

        $this->assertSame('local_coursepilot/admin_connections', $response->template);
        $this->assertTrue($response->data['empty']);
        $this->assertNull($response->redirecturl);
    }
}
