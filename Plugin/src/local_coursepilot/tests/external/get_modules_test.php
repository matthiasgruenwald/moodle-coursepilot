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

/**
 * Server-side course/section activities (#342), matching the local
 * tool field contract.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_modules::class)]
final class get_modules_test extends \advanced_testcase {
    /**
     * Return cmid, type and name for every activity.
     */
    public function test_returns_cmid_type_and_name_for_each_activity(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Testseite',
        ]);

        $result = get_modules::execute($course->id);
        $result = external_api::clean_returnvalue(get_modules::execute_returns(), $result);

        $module = self::find_module($result, (int) $page->cmid);
        $this->assertNotNull($module, 'The created page must appear in the module list.');
        $this->assertSame('page', $module['modname']);
        $this->assertSame('Testseite', $module['name']);
        $this->assertArrayHasKey('visible', $module);
        $this->assertArrayHasKey('sectionnum', $module);
    }

    /**
     * sectionnum filters to a single section.
     */
    public function test_sectionnum_filters_to_one_section(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'section' => 1,
        ]);

        $result = get_modules::execute($course->id, 1);
        $result = external_api::clean_returnvalue(get_modules::execute_returns(), $result);

        foreach ($result as $module) {
            $this->assertSame(1, $module['sectionnum']);
        }
    }

    /**
     * Reject enrolled users lacking local/coursepilot:use.
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

        $this->expectException(\required_capability_exception::class);

        get_modules::execute($course->id);
    }

    /**
     * Unenrolled users receive no data.
     */
    public function test_rejects_user_without_enrolment(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\moodle_exception::class);

        get_modules::execute($course->id);
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
     * Finds module.
     *
     * @param mixed[] $result
     * @param int $cmid
     * @return mixed[]|null
     */
    private static function find_module(array $result, int $cmid): ?array {
        foreach ($result as $module) {
            if ((int) $module['cmid'] === $cmid) {
                return $module;
            }
        }
        return null;
    }
}
