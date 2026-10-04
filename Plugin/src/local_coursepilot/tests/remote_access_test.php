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

/**
 * Remote access for course teachers without a global teacher role
 * (#579, ADR 0026). Authorize members of administrator-selected system
 * cohorts or users with local/coursepilot:useremote in an existing system
 * role. Coursepilot creates neither cohorts nor roles.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(dispatcher::class)]
#[CoversClass(remote_access::class)]
#[CoversClass(admin\remoteaccesscohorts_setting::class)]
final class remote_access_test extends \advanced_testcase {

    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/cohort/lib.php');
    }

    /**
     * Create a system cohort selected by administration for remote access.
     *
     * @param int ...$userids Members.
     * @return \stdClass The cohort.
     */
    private function create_selected_cohort(int ...$userids): \stdClass {
        $cohort = $this->getDataGenerator()->create_cohort(['contextid' => \context_system::instance()->id]);
        foreach ($userids as $userid) {
            cohort_add_member($cohort->id, $userid);
        }
        $selected = array_filter(explode(',', (string) get_config('local_coursepilot', 'remoteaccesscohorts')));
        $selected[] = $cohort->id;
        set_config('remoteaccesscohorts', implode(',', $selected), 'local_coursepilot');
        return $cohort;
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass} Course teacher without a global role, and their course.
     */
    private function create_course_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        return [$teacher, $course];
    }

    /**
     * @param int $userid
     * @return string The access token.
     */
    private function issue_access_token(int $userid): string {
        global $DB;

        $accesstoken = oauth_lib::random_token(32);
        $connectionid = $DB->insert_record('local_coursepilot_oauth_grant', (object) [
            'userid' => $userid, 'clientid' => 'test-client', 'revoked' => 0,
            'statehash' => bin2hex(random_bytes(32)), 'timecreated' => time(),
        ]);
        $DB->insert_record('local_coursepilot_oauth_token', (object) [
            'connectionid' => $connectionid,
            'accesstokenhash' => hash('sha256', $accesstoken),
            'refreshtokenhash' => hash('sha256', oauth_lib::random_token(32)),
            'clientid' => 'test-client',
            'userid' => $userid,
            'expires' => time() + oauth_lib::ACCESS_TOKEN_TTL,
            'refreshexpires' => time() + oauth_lib::REFRESH_TOKEN_TTL,
            'revoked' => 0,
            'timecreated' => time(),
        ]);
        return $accesstoken;
    }

    private function headers(): array {
        return ['origin' => null, 'pathinfo' => '', 'method' => 'POST'];
    }

    private function initialize(string $token): array {
        return dispatcher::handle(['id' => 1, 'method' => 'initialize'], $token, $this->headers());
    }

    private function get_sections(string $token, int $courseid): array {
        return dispatcher::handle(
            [
                'id' => 2,
                'method' => 'tools/call',
                'params' => ['name' => 'coursepilot_get_sections', 'arguments' => ['courseid' => $courseid]],
            ],
            $token,
            $this->headers()
        );
    }

    /**
     * Reject editingteachers without authorization and name both
     * authorization paths.
     */
    public function test_course_only_teacher_without_grant_is_rejected(): void {
        $this->resetAfterTest();
        [$teacher] = $this->create_course_teacher();
        $this->create_selected_cohort();

        $response = $this->initialize($this->issue_access_token($teacher->id));

        $this->assertSame(403, $response['status']);
        $this->assertStringContainsString('local/coursepilot:useremote', $response['body']['error']['message']);
        $this->assertStringContainsString('cohort', $response['body']['error']['message']);
        $this->assertFalse(remote_access::is_granted((int) $teacher->id));
    }

    /**
     * Selected-cohort members connect and act within their course without
     * receiving an additional role.
     */
    public function test_selected_cohort_member_connects_and_uses_own_course(): void {
        $this->resetAfterTest();
        [$teacher, $course] = $this->create_course_teacher();
        $this->create_selected_cohort($teacher->id);
        $token = $this->issue_access_token($teacher->id);

        $this->assertSame(200, $this->initialize($token)['status']);
        $toolresponse = $this->get_sections($token, $course->id);
        $this->assertSame(200, $toolresponse['status']);
        $this->assertArrayNotHasKey('isError', $toolresponse['body']['result']);
        $this->assertFalse(has_capability('local/coursepilot:useremote', \context_system::instance(), $teacher->id));
    }

    /**
     * Remote authorization grants no course rights; unrelated courses stay blocked.
     */
    public function test_cohort_grant_does_not_unlock_foreign_courses(): void {
        $this->resetAfterTest();
        [$teacher] = $this->create_course_teacher();
        $foreigncourse = $this->getDataGenerator()->create_course();
        $this->create_selected_cohort($teacher->id);

        $response = $this->get_sections($this->issue_access_token($teacher->id), $foreigncourse->id);

        $this->assertSame(200, $response['status']);
        $this->assertTrue($response['body']['result']['isError']);
    }

    /**
     * Removing cohort membership rejects the existing connection’s token
     * while preserving course permissions.
     */
    public function test_removal_from_cohort_blocks_existing_connection(): void {
        $this->resetAfterTest();
        [$teacher, $course] = $this->create_course_teacher();
        $cohort = $this->create_selected_cohort($teacher->id);
        $token = $this->issue_access_token($teacher->id);
        $this->assertSame(200, $this->initialize($token)['status']);

        cohort_remove_member($cohort->id, $teacher->id);

        $this->assertSame(403, $this->initialize($token)['status']);
        $this->assertTrue(has_capability('local/coursepilot:use', \context_course::instance($course->id), $teacher->id));
    }

    /**
     * Removing enrollment blocks course tools while preserving remote authorization.
     */
    public function test_revoking_course_enrolment_blocks_course_tool_but_keeps_remote_access(): void {
        $this->resetAfterTest();
        [$teacher, $course] = $this->create_course_teacher();
        $this->create_selected_cohort($teacher->id);
        $token = $this->issue_access_token($teacher->id);

        $this->unenrol_teacher($teacher->id, $course->id);

        $this->assertSame(200, $this->initialize($token)['status']);
        $toolresponse = $this->get_sections($token, $course->id);
        $this->assertSame(200, $toolresponse['status']);
        $this->assertTrue($toolresponse['body']['result']['isError']);
    }

    /**
     * Unselected cohorts grant no remote access.
     */
    public function test_member_of_unselected_cohort_is_rejected(): void {
        $this->resetAfterTest();
        [$teacher] = $this->create_course_teacher();
        $cohort = $this->getDataGenerator()->create_cohort(['contextid' => \context_system::instance()->id]);
        cohort_add_member($cohort->id, $teacher->id);
        $this->create_selected_cohort();

        $this->assertFalse(remote_access::is_granted((int) $teacher->id));
    }

    /**
     * Category cohorts grant no remote access even if their IDs appear in
     * settings, because category managers control them (ADR 0026).
     */
    public function test_category_cohort_never_grants(): void {
        $this->resetAfterTest();
        [$teacher] = $this->create_course_teacher();
        $category = $this->getDataGenerator()->create_category();
        $cohort = $this->getDataGenerator()->create_cohort(['contextid' => \context_coursecat::instance($category->id)->id]);
        cohort_add_member($cohort->id, $teacher->id);
        set_config('remoteaccesscohorts', (string) $cohort->id, 'local_coursepilot');

        $this->assertFalse(remote_access::is_granted((int) $teacher->id));
    }

    /**
     * Ignore deleted cohorts still in settings; other selected cohorts remain active.
     */
    public function test_deleted_cohort_is_ignored_and_reported(): void {
        $this->resetAfterTest();
        [$teacher] = $this->create_course_teacher();
        $gone = $this->create_selected_cohort($teacher->id);
        $kept = $this->create_selected_cohort($teacher->id);
        cohort_delete_cohort($gone);

        $this->assertTrue(remote_access::is_granted((int) $teacher->id));
        $this->assertSame([(int) $gone->id], remote_access::missing_cohort_ids());
        $this->assertArrayHasKey((int) $kept->id, remote_access::selected_cohort_member_counts());
    }

    /**
     * Show member counts for selected cohorts on the settings page.
     */
    public function test_member_counts_for_selected_cohorts(): void {
        $this->resetAfterTest();
        $one = $this->getDataGenerator()->create_user();
        $two = $this->getDataGenerator()->create_user();
        $cohort = $this->create_selected_cohort($one->id, $two->id);

        $counts = remote_access::selected_cohort_member_counts();

        $this->assertSame(2, $counts[(int) $cohort->id]->members);
        $this->assertSame($cohort->name, $counts[(int) $cohort->id]->name);
    }

    /**
     * Offer only system cohorts; display counts and deleted cohorts below the field.
     */
    public function test_setting_offers_system_cohorts_and_shows_counts(): void {
        $this->resetAfterTest();
        $member = $this->getDataGenerator()->create_user();
        $selected = $this->create_selected_cohort($member->id);
        $gone = $this->create_selected_cohort();
        cohort_delete_cohort($gone);
        $category = $this->getDataGenerator()->create_category();
        $categorycohort = $this->getDataGenerator()->create_cohort(
            ['contextid' => \context_coursecat::instance($category->id)->id]
        );

        $setting = new admin\remoteaccesscohorts_setting();
        $setting->load_choices();
        $html = $setting->output_html($setting->get_setting());

        $this->assertArrayHasKey((int) $selected->id, $setting->choices);
        $this->assertArrayNotHasKey((int) $categorycohort->id, $setting->choices);
        $this->assertStringContainsString($selected->name . ': 1', $html);
        $this->assertStringContainsString((string) $gone->id, $html);
    }

    /**
     * Existing school-wide roles with useremote authorize without a selected cohort.
     */
    public function test_capability_in_existing_system_role_grants(): void {
        $this->resetAfterTest();
        [$teacher] = $this->create_course_teacher();
        $roleid = create_role('Lehrkraft (schulweit)', 'schoolteacher', '', '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        assign_capability('local/coursepilot:useremote', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $teacher->id, \context_system::instance()->id);

        $this->assertSame(200, $this->initialize($this->issue_access_token($teacher->id))['status']);
    }

    /**
     * No archetype defaults (ADR 0026): globally assigning a teacher role
     * does not implicitly grant remote access.
     */
    public function test_teacher_archetypes_do_not_default_to_remote_access(): void {
        $this->resetAfterTest();

        $this->assertArrayNotHasKey('local/coursepilot:useremote', get_default_capabilities('editingteacher'));
        $this->assertArrayNotHasKey('local/coursepilot:useremote', get_default_capabilities('teacher'));
    }

    /**
     * Remove manual enrollment; the generator has no shorthand for this.
     *
     * @param int $userid
     * @param int $courseid
     */
    private function unenrol_teacher(int $userid, int $courseid): void {
        global $DB;

        $enrolinstance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', MUST_EXIST);
        $plugin = enrol_get_plugin('manual');
        $plugin->unenrol_user($enrolinstance, $userid);
    }

}
