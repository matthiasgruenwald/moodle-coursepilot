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

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../lib.php');

/**
 * Course navigation shows the history link only with
 * local/coursepilot:viewhistory (#397).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversFunction('local_coursepilot_extend_navigation_course')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_coursepilot_extend_navigation_user_settings')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_coursepilot_status_checks')]
final class lib_test extends advanced_testcase {
    /**
     * Provides course.
     *
     * @return array{0: stdClass, 1: context_course} Course, course context.
     */
    private function course(): array {
        $course = $this->getDataGenerator()->create_course();
        return [$course, context_course::instance($course->id)];
    }

    /**
     * With permission, exactly one history.php link includes the course ID.
     */
    public function test_adds_node_with_viewhistory_capability(): void {
        $this->resetAfterTest();
        [$course, $context] = $this->course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $navigation = new navigation_node('root');
        local_coursepilot_extend_navigation_course($navigation, $course, $context);

        $node = $navigation->get('local_coursepilot_history');
        $this->assertNotFalse($node);
        $this->assertStringContainsString('history.php', (string) $node->action);
        $this->assertStringContainsString('id=' . $course->id, (string) $node->action);
    }

    /**
     * Without permission, course navigation stays unchanged rather than
     * linking to a page that would reject access.
     */
    public function test_adds_no_node_without_viewhistory_capability(): void {
        $this->resetAfterTest();
        [$course, $context] = $this->course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $navigation = new navigation_node('root');
        local_coursepilot_extend_navigation_course($navigation, $course, $context);

        $this->assertFalse($navigation->get('local_coursepilot_history'));
    }

    /**
     * Admin status callback (#399): one check per cataloged activity type,
     * with unique IDs.
     */
    public function test_status_checks_return_one_check_per_catalogued_activity_type(): void {
        $checks = local_coursepilot_status_checks();

        $driftchecks = array_filter($checks, static fn ($check): bool => $check instanceof \local_coursepilot\check\activity_drift);
        $this->assertCount(count(\local_coursepilot\catalog\registry::known_modnames()), $driftchecks);

        $ids = array_map(static fn (\core\check\check $check): string => $check->get_id(), $checks);
        $this->assertSame($ids, array_unique($ids), 'Check IDs must be unique.');
    }

    /**
     * Register the four WebDAV setup checks alongside activity checks
     * (Issue #499, Spec #486 §12).
     */
    public function test_status_checks_include_the_four_webdav_checks(): void {
        $checks = local_coursepilot_status_checks();

        $classes = array_map(static fn ($check): string => get_class($check), $checks);
        $this->assertContains(\local_coursepilot\check\webdav_repository_check::class, $classes);
        $this->assertContains(\local_coursepilot\check\webdav_user_instances_check::class, $classes);
        $this->assertContains(\local_coursepilot\check\webdav_capability_check::class, $classes);
        $this->assertContains(\local_coursepilot\check\personal_data_hosts_check::class, $classes);
    }

    /**
     * On the current user's settings page, remote access granted through
     * a selected cohort (#579) adds Coursepilot links to location selection
     * and connections (Issue #524, Spec #486 §5, live acceptance #505 finding #12).
     */
    public function test_settings_navigation_adds_coursepilot_block_for_own_page(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $cohort = $this->getDataGenerator()->create_cohort(['contextid' => \context_system::instance()->id]);
        cohort_add_member($cohort->id, $user->id);
        set_config('remoteaccesscohorts', (string) $cohort->id, 'local_coursepilot');
        $this->setUser($user);
        $course = $this->getDataGenerator()->create_course();

        $navigation = new navigation_node('root');
        local_coursepilot_extend_navigation_user_settings(
            $navigation,
            $user,
            \context_user::instance($user->id),
            $course,
            \context_course::instance($course->id)
        );

        $block = $navigation->get('local_coursepilot_settings');
        $this->assertNotFalse($block);
        $this->assertNotFalse($block->get('local_coursepilot_settings_location_selection'));
        $this->assertNotFalse($block->get('local_coursepilot_settings_connections'));
    }

    /**
     * Without Coursepilot permission, user settings stay unchanged.
     */
    public function test_settings_navigation_adds_nothing_without_capability(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $course = $this->getDataGenerator()->create_course();

        $navigation = new navigation_node('root');
        local_coursepilot_extend_navigation_user_settings(
            $navigation,
            $user,
            \context_user::instance($user->id),
            $course,
            \context_course::instance($course->id)
        );

        $this->assertFalse($navigation->get('local_coursepilot_settings'));
    }

    /**
     * When viewing another user's settings, omit the Coursepilot block,
     * including when an administrator views a teacher's settings.
     */
    public function test_settings_navigation_adds_nothing_for_foreign_profile(): void {
        $this->resetAfterTest();
        $viewer = $this->getDataGenerator()->create_user();
        $viewed = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $viewed->id, \context_system::instance()->id);
        $this->setUser($viewer);
        $course = $this->getDataGenerator()->create_course();

        $navigation = new navigation_node('root');
        local_coursepilot_extend_navigation_user_settings(
            $navigation,
            $viewed,
            \context_user::instance($viewed->id),
            $course,
            \context_course::instance($course->id)
        );

        $this->assertFalse($navigation->get('local_coursepilot_settings'));
    }
}
