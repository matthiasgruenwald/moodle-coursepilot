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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Template data preparation for oauth/authorize.php (#552, Spec 0023 part 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(authorize_page::class)]
final class authorize_page_test extends \advanced_testcase {
    public function test_consent_text_names_the_client_and_contains_markup(): void {
        $this->resetAfterTest();

        $data = authorize_page::page_data(
            'Claude Desktop',
            ['client_id' => 'abc', 'redirect_uri' => 'https://example.test/cb'],
            'thestate',
            new \moodle_url('/local/coursepilot/oauth/authorize.php'),
            new \moodle_url('/local/coursepilot/location_selection.php')
        );

        $this->assertStringContainsString('Claude Desktop', $data['consenttext']);
        $this->assertStringContainsString('<strong>', $data['consenttext']);
    }

    public function test_hidden_fields_carry_all_params_and_state(): void {
        $this->resetAfterTest();

        $data = authorize_page::page_data(
            'Claude Desktop',
            ['client_id' => 'abc', 'redirect_uri' => 'https://example.test/cb'],
            'thestate',
            new \moodle_url('/local/coursepilot/oauth/authorize.php'),
            new \moodle_url('/local/coursepilot/location_selection.php')
        );

        $names = array_column($data['hiddenfields'], 'name');
        $this->assertContains('client_id', $names);
        $this->assertContains('redirect_uri', $names);
        $this->assertContains('state', $names);

        $byname = array_combine($names, array_column($data['hiddenfields'], 'value'));
        $this->assertSame('thestate', $byname['state']);
    }

    public function test_actions_offer_allow_and_deny(): void {
        $this->resetAfterTest();

        $data = authorize_page::page_data(
            'Claude Desktop',
            [],
            '',
            new \moodle_url('/local/coursepilot/oauth/authorize.php'),
            new \moodle_url('/local/coursepilot/location_selection.php')
        );

        $actionvalues = array_column($data['actions'], 'actionvalue');
        $this->assertSame(['allow', 'deny'], $actionvalues);
    }
    public function test_response_rejects_unverified_redirects_instead_of_redirecting(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Authorization error');
        authorize_page::response(['client_id' => 'unknown', 'response_type' => 'code',
            'redirect_uri' => 'https://unverified.example/cb', 'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256'], '', 'allow');
    }
    public function test_consent_response_preserves_state_and_issues_only_confirmed_codes(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $clientid = \local_coursepilot\oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Classroom client', 'redirect_uris' => ['https://client.example/cb'],
        ]), '192.0.2.1')['body']['client_id'];
        $verifier = str_repeat('v', 43);
        $params = ['response_type' => 'code', 'client_id' => $clientid, 'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256'];
        $consent = authorize_page::response($params, 'opaque-state', '');
        $this->assertSame('local_coursepilot/authorize', $consent->template);
        $this->assertStringContainsString('Classroom client', $consent->heading);
        $this->assertStringContainsString('oauthflow=1', $consent->data['locationselectionurl']);
        $_POST['sesskey'] = sesskey();
        try {
            $denied = authorize_page::response($params, 'opaque-state', 'deny');
            $this->assertSame('access_denied', $denied->redirecturl->get_param('error'));
            $this->assertSame('opaque-state', $denied->redirecturl->get_param('state'));
            $this->assertNull(authorize_page::response($params, '', 'deny')->redirecturl->get_param('state'));
            $allowed = authorize_page::response($params, 'opaque-state', 'allow');
            $this->assertSame('opaque-state', $allowed->redirecturl->get_param('state'));
            $tokens = \local_coursepilot\oauth_lib::exchange_code(
                $allowed->redirecturl->get_param('code'),
                $clientid,
                'https://client.example/cb',
                $verifier
            );
            $this->assertSame((int) $USER->id, \local_coursepilot\oauth_lib::authenticate_access_token($tokens['access_token']));
            $this->assertNull(authorize_page::response($params, '', 'allow')->redirecturl->get_param('state'));
        } finally {
            unset($_POST['sesskey']);
        }
    }
}
