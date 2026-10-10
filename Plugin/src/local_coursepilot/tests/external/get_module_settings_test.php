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

/**
 * Complete current activity state (Spec 0015 §3.2, issue #384).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_module_settings::class)]
final class get_module_settings_test extends \advanced_testcase {
    /**
     * Return raw instance fields, such as intro, without a custom Coursepilot schema.
     */
    public function test_returns_full_instance_state_as_json(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Testseite',
            'content' => 'Voller Seiteninhalt.',
        ]);

        $result = get_module_settings::execute($page->cmid);
        $result = external_api::clean_returnvalue(get_module_settings::execute_returns(), $result);

        $this->assertSame((int) $page->cmid, $result['cmid']);
        $this->assertSame('page', $result['modname']);

        $settings = json_decode($result['settings_json'], true);
        $this->assertSame('Testseite', $settings['name']);
        $this->assertStringContainsString('Voller Seiteninhalt', $settings['content']);
        $this->assertSame((int) $course->id, $settings['course']);
        $this->assertSame('page', $settings['modulename']);
    }

    /**
     * coursepagevisibility, visibleoncoursepage and availability_status
     * match get_modules and get_course_catalog in names and values for
     * the same cmid (Spec 0015 §3.5, one vocabulary).
     */
    public function test_visibility_vocabulary_matches_read_tools(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        set_coursemodule_visible($page->cmid, 1, 0); // Stealth: visible, but absent from the course page.

        $modules = external_api::clean_returnvalue(
            get_modules::execute_returns(),
            get_modules::execute($course->id)
        );
        $modulesrow = self::find_in_list($modules, (int) $page->cmid);

        $catalog = external_api::clean_returnvalue(
            get_course_catalog::execute_returns(),
            get_course_catalog::execute($course->id)
        );
        $catalogrow = self::find_in_catalog($catalog, (int) $page->cmid);

        $settingsresult = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($page->cmid)
        );
        $settings = json_decode($settingsresult['settings_json'], true);

        foreach (['visibleoncoursepage', 'coursepagevisibility', 'availability_status'] as $field) {
            $this->assertArrayHasKey($field, $modulesrow, "get_modules: $field fehlt.");
            $this->assertArrayHasKey($field, $catalogrow, "get_course_catalog: $field fehlt.");
            $this->assertArrayHasKey($field, $settings, "get_module_settings: $field fehlt.");
            $this->assertSame(
                (string) $modulesrow[$field],
                (string) $catalogrow[$field],
                "$field differs between get_modules and get_course_catalog."
            );
            $this->assertSame(
                (string) $modulesrow[$field],
                (string) $settings[$field],
                "$field differs between get_modules and get_module_settings."
            );
        }
        $this->assertSame('stealth', $settings['coursepagevisibility']);
        $this->assertSame('stealth', $settings['availability_status']);
    }

    /**
     * The describe_module_fields catalog also exposes coursepagevisibility
     * and availability_status under the same names as all three read tools
     * (Spec 0015 §3.5).
     */
    public function test_field_catalog_lists_same_vocabulary(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());

        $result = external_api::clean_returnvalue(
            describe_module_fields::execute_returns(),
            describe_module_fields::execute('page', true)
        );

        $pseudonames = array_column($result['module']['pseudo_fields'], 'name');
        $this->assertContains('coursepagevisibility', $pseudonames);
        $this->assertContains('availability_status', $pseudonames);
    }

    /**
     * Mask profile condition values while preserving type, field and
     * operator (ADR 0011).
     */
    public function test_profile_restriction_value_is_masked(): void {
        global $DB;

        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $availability = json_encode([
            'op' => '&',
            'c' => [
                ['type' => 'profile', 'sf' => 'department', 'op' => 'contains', 'v' => 'Mathe-FG'],
            ],
            'showc' => [true],
        ]);
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $page->cmid]);

        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($page->cmid)
        );
        $settings = json_decode($result['settings_json'], true);

        $this->assertArrayHasKey('availabilityconditionsjson', $settings);
        $decoded = json_decode($settings['availabilityconditionsjson'], true);
        $condition = $decoded['c'][0];

        $this->assertSame('profile', $condition['type']);
        $this->assertSame('department', $condition['sf']);
        $this->assertSame('contains', $condition['op']);
        $this->assertSame('***', $condition['v']);
        $this->assertStringNotContainsString('Mathe-FG', $result['settings_json']);
    }

    /**
     * Reading requires local/coursepilot:use, not moodle/course:manageactivities.
     */
    public function test_usable_without_edit_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $roleid = $this->get_role_id('student');
        assign_capability('local/coursepilot:use', CAP_ALLOW, $roleid, \context_course::instance($course->id)->id, true);
        $this->setUser($student);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $this->assertFalse(has_capability('moodle/course:manageactivities', \context_module::instance($page->cmid)));

        $result = get_module_settings::execute($page->cmid);
        $result = external_api::clean_returnvalue(get_module_settings::execute_returns(), $result);
        $this->assertSame((int) $page->cmid, $result['cmid']);
    }

    /**
     * Reject users without local/coursepilot:use.
     */
    public function test_rejects_user_without_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:use',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $this->expectException(\required_capability_exception::class);

        get_module_settings::execute($page->cmid);
    }

    /**
     * Returns role id.
     *
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * Finds in list.
     *
     * @param mixed[] $result
     * @param int $cmid
     * @return mixed[]|null
     */
    private static function find_in_list(array $result, int $cmid): ?array {
        foreach ($result as $module) {
            if ((int) $module['cmid'] === $cmid) {
                return $module;
            }
        }
        return null;
    }

    /**
     * Finds in catalog.
     *
     * @param mixed[] $result
     * @param int $cmid
     * @return mixed[]|null
     */
    private static function find_in_catalog(array $result, int $cmid): ?array {
        foreach ($result['sections'] as $section) {
            foreach ($section['modules'] as $module) {
                if ((int) $module['cmid'] === $cmid) {
                    return $module;
                }
            }
        }
        return null;
    }

    /**
     * Report existing learner restrictions alongside current state so
     * courses can be checked without database access (#583).
     */
    public function test_reports_existing_learner_locks(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'attemptreopenmethod' => 'manual',
            'submissiondrafts' => 0,
        ]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'quizpassword' => 'geheim']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $assignresult = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($assign->cmid)
        );
        $quizresult = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($quiz->cmid)
        );
        $pageresult = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($page->cmid)
        );

        $assignlocks = array_column($assignresult['learner_locks'], null, 'field');
        $this->assertArrayHasKey('attemptreopenmethod', $assignlocks);
        $this->assertArrayNotHasKey('submissiondrafts', $assignlocks);
        $this->assertSame('"manual"', $assignlocks['attemptreopenmethod']['value_json']);
        $this->assertNotSame('', $assignlocks['attemptreopenmethod']['reason']);
        $this->assertContains('quizpassword', array_column($quizresult['learner_locks'], 'field'));
        $this->assertSame([], $pageresult['learner_locks']);
    }
}
