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

use local_coursepilot\location_selection;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The exact data and guards of the OAuth consent page: texts in their order, URLs unescaped for the
 * template, the sesskey, and the checks before any code is issued or a denial is redirected.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(authorize_page::class)]
final class authorize_page_contract_test extends \advanced_testcase {
    /**
     * Valid authorization request parameters of a freshly registered client.
     *
     * @return array<string, string>
     */
    private function params(): array {
        $clientid = \local_coursepilot\oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Classroom client', 'redirect_uris' => ['https://client.example/cb'],
        ]), '192.0.2.1')['body']['client_id'];
        return ['response_type' => 'code', 'client_id' => $clientid, 'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256'];
    }

    /**
     * The consent text names the client and runs through the fixed sequence of statements.
     */
    public function test_page_data_is_exact(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $form = new \moodle_url('/local/coursepilot/oauth/authorize.php', ['a' => '1', 'b' => '2']);
        $selection = new \moodle_url('/local/coursepilot/location_selection.php', ['x' => 'y', 'z' => 'w']);
        $ca = location_selection::current('context_area');
        $ms = location_selection::current('material_store');

        foreach ([0, 1] as $personal) {
            set_config('allowpersonaldata', $personal, 'local_coursepilot');
            $data = authorize_page::page_data('Klasse 7', ['client_id' => 'abc'], 'st', $form, $selection);
            $pd = get_string($personal ? 'consentpersonaldataon' : 'consentpersonaldataoff', 'local_coursepilot');
            $s = static fn (string $key, $a = null): string => get_string($key, 'local_coursepilot', $a);

            $this->assertSame([
                'consenttext', 'context_area', 'material_store', 'locationselectionurl', 'formurl', 'hiddenfields', 'sesskey',
                'actions',
            ], array_keys($data));
            $this->assertSame(
                $s('consentintro', 'Klasse 7') . '<br><br>' . $s('consentgranted') . '<br>' . $s('consentdenied') . '<br><br>'
                    . $s('consenttransfer') . '<br><br>' . $pd . '<br><br>' . $s('consentabbreviate') . '<br><br>'
                    . $s('consentrevoke'),
                $data['consenttext']
            );
            $this->assertSame(
                ['text' => $s('consentlocationcontextareacurrent', $ca['display'])
                    . ' — ' . location_selection::allowed_label($ca)],
                $data['context_area']
            );
            $this->assertSame(
                ['text' => $s('consentlocationmaterialstorecurrent', $ms['display'])
                    . ' — ' . location_selection::allowed_label($ms)],
                $data['material_store']
            );
            $this->assertSame($selection->out(false), $data['locationselectionurl']);
            $this->assertSame($form->out(false), $data['formurl']);
            $this->assertStringContainsString('z=w', $data['locationselectionurl']);
            $this->assertStringNotContainsString('&amp;', $data['formurl']);
            $this->assertSame(
                [['name' => 'client_id', 'value' => 'abc'], ['name' => 'state', 'value' => 'st']],
                $data['hiddenfields']
            );
            $this->assertSame(sesskey(), $data['sesskey']);
            $this->assertSame([
                ['actionvalue' => 'allow', 'label' => $s('consentconfirm')],
                ['actionvalue' => 'deny', 'label' => $s('consentdeny')],
            ], $data['actions']);
        }
    }

    /**
     * The consent view carries the client in the title, the state and the OAuth flow marker in the selection link.
     */
    public function test_consent_view_title_and_selection_link(): void {
        global $PAGE;

        $this->resetAfterTest();
        $this->setAdminUser();
        $params = $this->params();

        $view = authorize_page::response($params, 'abc-state', '');

        $title = get_string('authorizetitleclient', 'local_coursepilot', 'Classroom client');
        $this->assertSame($title, $view->heading);
        $this->assertStringContainsString($title, $PAGE->title);
        $selection = new \moodle_url($view->data['locationselectionurl']);
        $this->assertSame('abc-state', $selection->get_param('state'));
        $this->assertSame('1', $selection->get_param('oauthflow'));
        $this->assertSame($params['client_id'], $selection->get_param('client_id'));
    }

    /**
     * Without the remote-access grant nothing is shown or issued.
     */
    public function test_remote_access_grant_is_required(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->expectException(\moodle_exception::class);
        authorize_page::response($this->params(), '', '');
    }

    /**
     * Allow and deny both need a valid sesskey; without it no code is issued and nothing is redirected.
     */
    public function test_allow_and_deny_require_a_sesskey(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $params = $this->params();

        foreach (['allow', 'deny'] as $action) {
            try {
                authorize_page::response($params, 's', $action);
                $this->fail("{$action} without sesskey must be refused.");
            } catch (\moodle_exception $e) {
                $this->assertContains($e->errorcode, ['missingparam', 'invalidsesskey'], $action);
            }
        }
        $this->assertSame(0, $DB->count_records('local_coursepilot_oauth_code'));
    }

    /**
     * The issued code belongs to the acting user and the confirmed client, and the state is carried through.
     */
    public function test_allow_issues_a_code_for_the_acting_user(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        $params = $this->params();
        $_POST['sesskey'] = sesskey();
        try {
            $allowed = authorize_page::response($params, 'keep', 'allow');
        } finally {
            unset($_POST['sesskey']);
        }

        $issued = $allowed->redirecturl->get_param('code');
        $code = $DB->get_record('local_coursepilot_oauth_code', ['code' => $issued], '*', MUST_EXIST);
        $this->assertSame((int) $USER->id, (int) $code->userid);
        $this->assertSame($params['client_id'], $code->clientid);
        $this->assertSame('keep', $allowed->redirecturl->get_param('state'));
        $this->assertStringStartsWith('https://client.example/cb?', $allowed->redirecturl->out(false));
    }
}
