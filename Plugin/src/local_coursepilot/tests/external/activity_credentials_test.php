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

namespace local_coursepilot\external;

use core_external\external_api;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Credentials stay inside Moodle, including for teachers who cannot edit activities.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(\local_coursepilot\dispatcher::class)]
#[CoversClass(get_module_settings::class)]
#[CoversClass(compare_activity_versions::class)]
#[CoversClass(list_activity_versions::class)]
#[CoversClass(\local_coursepilot\history\version_history::class)]
#[CoversClass(\local_coursepilot\catalog\activity_credentials::class)]
#[CoversClass(\local_coursepilot\catalog\module_state::class)]
final class activity_credentials_test extends \advanced_testcase {
    /**
     * Installed activity types and both teacher archetypes share the privacy contract.
     *
     * @return array<string, array{string, string, string[]}>
     */
    public static function credentials(): array {
        $cases = [];
        foreach (['editingteacher', 'teacher'] as $role) {
            foreach (
                [
                    'lti' => ['password', 'resourcekey', 'servicesalt'],
                    'lesson' => ['password'],
                    'bigbluebuttonbn' => ['moderatorpass', 'viewerpass', 'guestpassword', 'guestlinkuid'],
                ] as $modname => $fields
            ) {
                $cases[$role . '-' . $modname] = [$role, $modname, $fields];
            }
        }
        return $cases;
    }

    /**
     * Settings never return credentials from the underlying instance row.
     *
     * @param string $role
     * @param string $modname
     * @param string[] $fields
     */
    #[DataProvider('credentials')]
    public function test_settings_hide_credentials(string $role, string $modname, array $fields): void {
        global $DB;
        $this->resetAfterTest();
        if (!\core_component::get_plugin_directory('mod', $modname)) {
            $this->markTestSkipped('Activity type is not installed: ' . $modname);
        }
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);
        foreach ($fields as $field) {
            // Synthetic fixture: bypass form normalisation to exercise every stored credential column.
            $DB->set_field($modname, $field, 'synthetic-' . $field, ['id' => $activity->id]);
        }
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $role);
        $this->setUser($teacher);
        $this->assertSame($role === 'editingteacher', has_capability(
            'moodle/course:manageactivities',
            \context_module::instance($activity->cmid)
        ));
        $result = $this->call_tool('coursepilot_get_module_settings', ['cmid' => $activity->cmid]);
        $settings = json_decode($result['settings_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($modname, $result['modname']);
        foreach ($fields as $field) {
            $this->assertArrayNotHasKey($field, $settings);
            $this->assertStringNotContainsString('synthetic-' . $field, json_encode($result));
        }
    }

    /**
     * A secret change remains visible, but neither value leaves Moodle.
     *
     * @param string $role
     * @param string $modname
     * @param string[] $fields
     */
    #[DataProvider('credentials')]
    public function test_comparison_redacts_changed_credentials(string $role, string $modname, array $fields): void {
        global $DB, $USER;
        $this->resetAfterTest();
        if (!\core_component::get_plugin_directory('mod', $modname)) {
            $this->markTestSkipped('Activity type is not installed: ' . $modname);
        }
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);
        // Synthetic fixture: set all credentials before capturing each raw history state.
        foreach ($fields as $field) {
            $DB->set_field($modname, $field, 'synthetic-old-' . $field, ['id' => $activity->id]);
        }
        \local_coursepilot\history\version_writer::capture($activity->cmid, $USER->id);
        foreach ($fields as $field) {
            $DB->set_field($modname, $field, 'synthetic-new-' . $field, ['id' => $activity->id]);
        }
        \local_coursepilot\history\version_writer::capture($activity->cmid, $USER->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $role);
        $this->setUser($teacher);
        $result = $this->call_tool('coursepilot_compare_activity_versions', [
            'cmid' => $activity->cmid, 'from_version' => 2, 'to_version' => 3,
        ]);
        $changes = array_column($result['changes'], null, 'field');
        foreach ($fields as $field) {
            $this->assertSame('"[redacted]"', $changes[$field]['before_json']);
            $this->assertSame('"[redacted]"', $changes[$field]['after_json']);
        }
        $this->assertStringNotContainsString('synthetic-old-', json_encode($result));
        $this->assertStringNotContainsString('synthetic-new-', json_encode($result));
        $list = external_api::clean_returnvalue(
            list_activity_versions::execute_returns(),
            list_activity_versions::execute($activity->cmid)
        );
        $this->assertStringNotContainsString('synthetic-', json_encode($list));
        $this->assertStringContainsString($fields[0], json_encode($list));
        $raw = \local_coursepilot\history\version_history::state_at($activity->cmid, 2);
        $this->assertSame('synthetic-old-' . $fields[0], $raw[$fields[0]]);
    }

    /**
     * Quiz passwords and passing grades remain usable catalog settings after unrelated writes.
     */
    public function test_quiz_password_and_gradepass_remain_readable_after_write(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'quizpassword' => 'synthetic-quiz-access',
        ]);
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($quiz->cmid)
        );
        $settings = json_decode($result['settings_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('synthetic-quiz-access', $settings['password']);
        $this->assertArrayHasKey('gradepass', $settings);
        $this->assertContains('quizpassword', array_column($result['learner_locks'], 'field'));
        external_api::clean_returnvalue(
            update_quiz_settings::execute_returns(),
            update_quiz_settings::execute($quiz->cmid, '{"name":"Renamed quiz"}')
        );
        $after = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($quiz->cmid)
        );
        $this->assertSame('synthetic-quiz-access', json_decode($after['settings_json'], true)['password']);
        $this->assertSame('Renamed quiz', json_decode($after['settings_json'], true)['name']);
    }

    /**
     * Unsupported activity writes and restores reject placeholders without touching stored credentials.
     *
     * @param string $role
     * @param string $modname
     * @param string[] $fields
     */
    #[DataProvider('credentials')]
    public function test_rejected_writes_and_restores_preserve_credentials(string $role, string $modname, array $fields): void {
        global $DB, $USER;
        $this->resetAfterTest();
        if (!\core_component::get_plugin_directory('mod', $modname)) {
            $this->markTestSkipped('Activity type is not installed: ' . $modname);
        }
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module($modname, ['course' => $course->id]);
        foreach ($fields as $field) {
            // Synthetic fixture: native tools must preserve these values even when rejecting a request.
            $DB->set_field($modname, $field, 'synthetic-preserved-' . $field, ['id' => $activity->id]);
        }
        \local_coursepilot\history\version_writer::capture($activity->cmid, $USER->id);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, $role);
        $this->setUser($teacher);
        foreach ([['name' => 'Renamed activity'], [$fields[0] => '[redacted]']] as $patch) {
            try {
                update_module_settings::execute($activity->cmid, json_encode($patch));
                $this->fail('Uncatalogued activity must not become writable.');
            } catch (\moodle_exception $error) {
                $this->assertSame($role === 'editingteacher' ? 'unknownmodname' : 'nopermissions', $error->errorcode);
                $this->assertStringNotContainsString('synthetic-preserved-', $error->getMessage());
            }
        }
        try {
            restore_activity_version::execute($activity->cmid, 2);
            $this->fail('Uncatalogued activity must not become restorable.');
        } catch (\moodle_exception $error) {
            $this->assertSame($role === 'editingteacher' ? 'unknownmodname' : 'nopermissions', $error->errorcode);
            $this->assertStringNotContainsString('synthetic-preserved-', $error->getMessage());
        }
        foreach ($fields as $field) {
            $this->assertSame('synthetic-preserved-' . $field, $DB->get_field($modname, $field, ['id' => $activity->id]));
        }
    }

    /**
     * Quiz history still shows the intentional access password, including an actual change.
     */
    public function test_quiz_comparison_keeps_access_password(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'quizpassword' => 'synthetic-quiz-old',
        ]);
        external_api::clean_returnvalue(
            update_quiz_settings::execute_returns(),
            update_quiz_settings::execute($quiz->cmid, '{"quizpassword":"synthetic-quiz-new"}', '', -1, ['quizpassword'])
        );
        $result = external_api::clean_returnvalue(
            compare_activity_versions::execute_returns(),
            compare_activity_versions::execute($quiz->cmid, 1, 2)
        );
        $changes = array_column($result['changes'], null, 'field');
        $this->assertSame('"synthetic-quiz-old"', $changes['password']['before_json']);
        $this->assertSame('"synthetic-quiz-new"', $changes['password']['after_json']);
    }

    /**
     * Exercise the published MCP response for the current teacher without granting edit rights.
     *
     * @param string $name
     * @param mixed[] $arguments
     * @return mixed[]
     */
    private function call_tool(string $name, array $arguments): array {
        global $DB, $USER;
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        $token = bin2hex(random_bytes(32));
        $grantid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $USER->id, 'clientid' => 'credential-test', 'revoked' => 0,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => time(),
        ]);
        $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'userid' => $USER->id, 'clientid' => 'credential-test', 'connectionid' => $grantid,
            'accesstokenhash' => hash('sha256', $token), 'refreshtokenhash' => hash('sha256', random_bytes(32)),
            'expires' => time() + 3600, 'refreshexpires' => time() + 7200, 'revoked' => 0, 'timecreated' => time(),
        ]);
        $response = \local_coursepilot\dispatcher::handle(
            ['id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments]],
            $token,
            ['origin' => null, 'pathinfo' => '', 'method' => 'POST']
        );
        $this->assertSame(200, $response['status']);
        $this->assertArrayNotHasKey('isError', $response['body']['result'], json_encode($response['body']));
        return $response['body']['result']['structuredContent'];
    }
}
