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
 * The location-selection controller preserves standalone and OAuth navigation.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(location_selection_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_coursepilot\output\location_selection::class)]
final class location_selection_page_test extends \advanced_testcase {
    use \local_coursepilot\tests\webdav\webdav_instance_fixture;

    public function test_standalone_page_keeps_locations_without_submitting_a_change(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $response = location_selection_page::response(false, false);
        $this->assertSame('local_coursepilot/location_selection_page', $response->template);
        $this->assertNull($response->data['finishresult']);
        $this->assertNull($response->data['oauthreturn']);
    }
    public function test_finish_reports_no_change_and_keeps_errors_on_the_editor(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();
        try {
            $response = location_selection_page::response(false, true);
            $this->assertSame('alert-info', $response->data['finishresult']['class']);
            $this->assertSame(
                get_string('locationselectionfinishnochange', 'local_coursepilot'),
                $response->data['finishresult']['text']
            );
            $_POST['context_area_type'] = 'unknown';
            $invalid = location_selection_page::response(false, true);
            $this->assertSame('alert-danger', $invalid->data['finishresult']['class']);
            $this->assertNull($invalid->redirecturl);
        } finally {
            unset($_POST['sesskey'], $_POST['context_area_type']);
        }
    }

    public function test_valid_oauth_return_is_retained_and_finish_redirects_to_consent(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $original = $_POST;
        $_POST = $this->oauth_params() + ['sesskey' => sesskey()];
        try {
            $editor = location_selection_page::response(true, false);
            $this->assertNotNull($editor->data['oauthreturn']);
            $this->assertStringContainsString('state=opaque-state', $editor->data['oauthreturn']['backurl']);
            $finished = location_selection_page::response(true, true);
            $this->assertStringEndsWith('/local/coursepilot/oauth/authorize.php', $finished->redirecturl->get_path());
            $this->assertSame('opaque-state', $finished->redirecturl->get_param('state'));
            $_POST['context_area_type'] = 'unknown';
            $failed = location_selection_page::response(true, true);
            $this->assertSame('alert-danger', $failed->data['finishresult']['class']);
            $this->assertNull($failed->redirecturl);
        } finally {
            $_POST = $original;
        }
    }

    public function test_invalid_oauth_return_keeps_the_standalone_page(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $response = location_selection_page::response(true, false);
        $this->assertNull($response->data['oauthreturn']);
        $this->assertNull($response->redirecturl);
    }

    public function test_external_selection_configures_the_editor_and_reports_provided_templates(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        $instanceid = $this->create_webdav_instance($USER);
        $fake = new \local_coursepilot\tests\webdav\fake_webdav_transport();
        $fake->seed_folder('/Coursepilot');
        \core\di::set(\local_coursepilot\webdav\webdav_transport::class, $fake);
        $original = $_POST;
        $_POST = ['sesskey' => sesskey(), 'context_area_type' => 'external',
            'context_area_instanceid' => $instanceid, 'context_area_path' => 'Lessons'];
        try {
            $changed = location_selection_page::response(false, true);
            $this->assertSame('alert-success', $changed->data['finishresult']['class']);
            $this->assertTrue($changed->data['ready']);
            $this->assertSame('Lessons', \local_coursepilot\location_selection::current('context_area')['path']);
            $this->assertNotEmpty(\core\notification::fetch());
        } finally {
            $_POST = $original;
            \core\di::reset_container();
        }
    }

    /**
     * Registers a real client and produces a valid consent request.
     *
     * @return string[] OAuth authorization fields.
     */
    private function oauth_params(): array {
        $clientid = \local_coursepilot\oauth_lib::handle_registration('POST', json_encode([
            'client_name' => 'Classroom client', 'redirect_uris' => ['https://client.example/cb'],
        ]), '192.0.2.1')['body']['client_id'];
        return ['response_type' => 'code', 'client_id' => $clientid, 'redirect_uri' => 'https://client.example/cb',
            'code_challenge' => str_repeat('a', 43), 'code_challenge_method' => 'S256', 'state' => 'opaque-state'];
    }
}
