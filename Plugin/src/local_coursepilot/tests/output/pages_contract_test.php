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

use local_coursepilot\instance_check;
use local_coursepilot\pointer_location;
use local_coursepilot\privacy_surface;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact template data and request reading of the surface, connections and location selection pages.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(surface_page::class)]
#[CoversClass(connections_page::class)]
#[CoversClass(location_selection_page::class)]
final class pages_contract_test extends \advanced_testcase {
    use caught_throwable;
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    protected function tearDown(): void {
        \core\di::reset_container();
        parent::tearDown();
    }

    /**
     * Wraps strings the way the list templates expect them.
     *
     * @param string[] $items
     * @return array<int, array{text: string}>
     */
    private function items(array $items): array {
        $rows = [];
        foreach ($items as $item) {
            $rows[] = ['text' => $item];
        }
        return $rows;
    }

    public function test_the_surface_data_lists_everything_in_its_order_and_hides_extra_violation_fields(): void {
        $this->resetAfterTest();
        $violations = [
            ['type' => 'forbidden_token', 'name' => 'tool_a', 'detail' => 'd1', 'extra' => 'dropped'],
            ['type' => 'other', 'name' => 'tool_b', 'detail' => 'd2'],
        ];

        $data = surface_page::page_data($violations, ['one', 'two'], ['ok' => true, 'detail' => '', 'url' => 'https://x.test/y']);

        $this->assertSame(true, $data['hasviolations']);
        $this->assertSame([
            ['type' => 'forbidden_token', 'name' => 'tool_a', 'detail' => 'd1'],
            ['type' => 'other', 'name' => 'tool_b', 'detail' => 'd2'],
        ], $data['violations']);
        $tools = [];
        foreach (privacy_surface::allowed_tools() as $tool => $function) {
            $tools[] = ['tool' => $tool, 'function' => $function];
        }
        $this->assertGreaterThan(1, count($tools));
        $this->assertSame($tools, $data['allowedtools']);
        $this->assertSame($this->items(['one', 'two']), $data['registered']);
        $this->assertSame($this->items(privacy_surface::FORBIDDEN_TOKENS), $data['forbidden']);
        $requirements = array_map(
            static fn(string $r): string => get_string('surfacereq' . $r, 'local_coursepilot'),
            instance_check::REQUIREMENTS
        );
        $this->assertSame($this->items($requirements), $data['requirements']);
        $this->assertSame(get_string('selfcheckurl', 'local_coursepilot', 'https://x.test/y'), $data['selfcheckurltext']);
        $this->assertTrue($data['selfcheckok']);
        $this->assertNull($data['selfcheckerror']);
        $this->assertNull($data['emergencyexithint']);
    }

    public function test_a_failed_self_check_names_its_error_and_the_emergency_exit(): void {
        $this->resetAfterTest();

        $data = surface_page::page_data([], [], ['ok' => false, 'detail' => 'surfaceinstanceintro', 'url' => 'u']);

        $this->assertFalse($data['hasviolations']);
        $this->assertSame([], $data['violations']);
        $this->assertFalse($data['selfcheckok']);
        $this->assertSame(get_string('surfaceinstanceintro', 'local_coursepilot'), $data['selfcheckerror']);
        $this->assertSame(
            get_string('surfaceinstanceemergencyexit', 'local_coursepilot', instance_check::EMERGENCY_EXIT_RULE),
            $data['emergencyexithint']
        );
    }

    public function test_the_connection_rows_are_exact_and_the_urls_unescaped(): void {
        $this->resetAfterTest();
        $tokens = [
            (object) [
                'id' => 7,
                'clientname' => 'Named',
                'clientid' => 'cid',
                'timecreated' => 1000000000,
                'expires' => 1100000000,
            ],
            (object) [
                'id' => 8,
                'clientname' => '',
                'clientid' => 'plain-id',
                'timecreated' => 1000000001,
                'expires' => 1100000001,
            ],
        ];
        $selection = new \moodle_url('/local/coursepilot/location_selection.php', ['a' => '1', 'b' => '2']);

        $data = connections_page::page_data($tokens, ['context_area' => ['text' => 'x']], $selection);

        $this->assertSame(['currentlocations', 'locationselectionurl', 'empty', 'rows'], array_keys($data));
        $this->assertSame(['context_area' => ['text' => 'x']], $data['currentlocations']);
        $this->assertSame($selection->out(false), $data['locationselectionurl']);
        $this->assertFalse($data['empty']);
        $this->assertSame(['clientname', 'since', 'expires', 'revokeurl'], array_keys($data['rows'][0]));
        $this->assertSame('Named', $data['rows'][0]['clientname']);
        $this->assertSame('plain-id', $data['rows'][1]['clientname']);
        $this->assertSame(userdate(1000000000), $data['rows'][0]['since']);
        $this->assertSame(userdate(1100000000), $data['rows'][0]['expires']);
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/connections.php', ['revoke' => 7, 'sesskey' => sesskey()]))->out(false),
            $data['rows'][0]['revokeurl']
        );
        $this->assertStringContainsString('&sesskey=', $data['rows'][0]['revokeurl']);
        $this->assertTrue(connections_page::page_data([], [], $selection)['empty']);
    }

    public function test_a_user_revokes_only_their_own_connections_and_needs_the_session_key(): void {
        $this->resetAfterTest();
        $mine = $this->getDataGenerator()->create_user();
        $theirs = $this->getDataGenerator()->create_user();
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        foreach ([$mine, $theirs] as $user) {
            $code = \local_coursepilot\connection::issue_code('client', (int) $user->id, 'https://client.example/cb', $challenge);
            \local_coursepilot\connection::exchange_code($code, 'client', 'https://client.example/cb', $verifier);
        }
        $theirtokens = \local_coursepilot\connection::active_tokens_for_user((int) $theirs->id);
        $theirtoken = reset($theirtokens);
        global $DB;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($mine);

        $nosesskey = $this->caught(fn() => connections_page::response((int) $theirtoken->id));
        $this->assertInstanceOf(\moodle_exception::class, $nosesskey);
        $_POST['sesskey'] = sesskey();
        try {
            $response = connections_page::response((int) $theirtoken->id);
        } finally {
            unset($_POST['sesskey']);
        }

        $this->assertSame(
            (new \moodle_url('/local/coursepilot/connections.php'))->out(false),
            $response->redirecturl->out(false)
        );
        $this->assertCount(1, \local_coursepilot\connection::active_tokens_for_user((int) $theirs->id));
        $own = \local_coursepilot\connection::active_tokens_for_user((int) $mine->id);
        $this->assertCount(1, $own);
        $overview = connections_page::response(0);
        $this->assertSame('local_coursepilot/connections', $overview->template);
        $this->assertCount(1, $overview->data['rows']);
    }

    public function test_the_location_form_is_read_per_target_with_its_defaults(): void {
        $this->resetAfterTest();
        $read = new \ReflectionMethod(location_selection_page::class, 'read_selection');
        $defaults = $read->invoke(null);
        $this->assertSame(['context_area', 'material_store'], array_keys($defaults));
        foreach ($defaults as $selection) {
            $this->assertSame(
                ['type' => pointer_location::MOODLE, 'instanceid' => 0, 'path' => '', 'confirmed' => false],
                $selection
            );
        }

        $_POST = [
            'context_area_type' => pointer_location::EXTERNAL,
            'context_area_instanceid' => '12',
            'context_area_path' => ' Folder ',
            'context_area_confirmed' => '1',
            'material_store_type' => pointer_location::EXTERNAL,
            'material_store_instanceid' => '13',
            'material_store_path' => 'Other',
        ];
        try {
            $filled = $read->invoke(null);
        } finally {
            $_POST = [];
        }

        $this->assertSame(
            ['type' => pointer_location::EXTERNAL, 'instanceid' => 12, 'path' => 'Folder', 'confirmed' => true],
            $filled['context_area']
        );
        $this->assertSame(
            ['type' => pointer_location::EXTERNAL, 'instanceid' => 13, 'path' => 'Other', 'confirmed' => false],
            $filled['material_store']
        );
    }

    public function test_an_unknown_location_type_is_refused_even_for_the_last_target(): void {
        $this->resetAfterTest();
        $_POST = ['material_store_type' => 'carrierpigeon'];
        try {
            $e = $this->caught(fn() => (new \ReflectionMethod(location_selection_page::class, 'read_selection'))->invoke(null));
        } finally {
            $_POST = [];
        }

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('locationselectionselectioninvalid', $e->errorcode);
    }

    public function test_the_location_page_needs_the_remote_access_grant_and_a_session_key_to_finish(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertInstanceOf(\moodle_exception::class, $this->caught(fn() => location_selection_page::response(false, false)));

        $this->setAdminUser();
        $this->assertInstanceOf(\moodle_exception::class, $this->caught(fn() => location_selection_page::response(false, true)));
    }

    public function test_the_location_script_is_loaded_only_when_the_setup_is_ready(): void {
        global $PAGE, $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $PAGE = new \moodle_page();
        $PAGE->set_url('/local/coursepilot/location_selection.php');
        $PAGE->set_context(\context_system::instance());

        $before = location_selection_page::response(false, false);
        $withoutscript = $PAGE->requires->get_end_code();
        $this->assertFalse($before->data['ready']);
        $this->assertStringNotContainsString('local_coursepilot/location_selection', $withoutscript);

        $this->grant_webdav_capability($user);
        $this->create_webdav_instance($user);
        $PAGE = new \moodle_page();
        $PAGE->set_url('/local/coursepilot/location_selection.php');
        $PAGE->set_context(\context_system::instance());
        $ready = location_selection_page::response(false, false);

        $this->assertTrue($ready->data['ready']);
        $this->assertStringContainsString('local_coursepilot/location_selection', $PAGE->requires->get_end_code());
    }

    public function test_finishing_names_the_changed_targets_and_returns_to_consent_in_the_oauth_flow(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->grant_webdav_capability($user);
        $instanceid = $this->create_webdav_instance($user);
        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake->seed_folder('/Coursepilot');
        $fake->seed_folder('/Coursepilot/Lessons');
        $fake->seed_folder('/Coursepilot/Material');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        $_POST = [
            'sesskey' => sesskey(),
            'material_store_type' => pointer_location::EXTERNAL,
            'material_store_instanceid' => (string) $instanceid,
            'material_store_path' => 'Material',
            'material_store_confirmed' => '1',
            'context_area_type' => pointer_location::EXTERNAL,
            'context_area_instanceid' => (string) $instanceid,
            'context_area_path' => 'Lessons',
            'context_area_confirmed' => '1',
        ];
        try {
            $first = location_selection_page::response(false, true);
            $again = location_selection_page::response(false, true);
        } finally {
            $_POST = [];
        }

        $labels = implode(', ', [
            get_string('locationselectiontabcontextarea', 'local_coursepilot'),
            get_string('locationselectiontabmaterialstore', 'local_coursepilot'),
        ]);
        $this->assertSame(
            ['class' => 'alert-success', 'text' => get_string('locationselectionfinishsuccess', 'local_coursepilot', $labels)],
            $first->data['finishresult']
        );
        $this->assertSame(
            ['class' => 'alert-info', 'text' => get_string('locationselectionfinishnochange', 'local_coursepilot')],
            $again->data['finishresult']
        );
        $this->assertNull($first->redirecturl);

        $clientid = \local_coursepilot\oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Classroom client',
            'redirect_uris' => ['https://client.example/cb'],
        ]), '192.0.2.1')['body']['client_id'];
        $oauth = [
            'response_type' => 'code',
            'client_id' => $clientid,
            'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'state' => 'keep',
        ];
        $_POST = ['sesskey' => sesskey()] + $oauth;
        try {
            $returned = location_selection_page::response(true, true);
        } finally {
            $_POST = [];
        }
        $this->assertSame(
            (new \moodle_url('/local/coursepilot/oauth/authorize.php', $oauth))->out(false),
            $returned->redirecturl->out(false)
        );
        $this->assertNull($returned->data['finishresult'] ?? null);
    }
}
