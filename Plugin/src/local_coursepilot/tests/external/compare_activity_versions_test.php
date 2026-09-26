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
 * Volles Diff zweier frei gewaehlter Staende (Spec 0015 §10.6, #394).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(compare_activity_versions::class)]
final class compare_activity_versions_test extends \advanced_testcase {

    /**
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function create_page(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Erste Fassung',
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $cm];
    }

    /**
     * @param \stdClass $course
     * @param \stdClass $cm
     * @param string $name
     * @return void
     */
    private function update_page(\stdClass $course, \stdClass $cm, string $name): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        [, , , $moduleinfo] = \get_moduleinfo_data($cm, $course);
        $moduleinfo->name = $name;
        $moduleinfo->page = ['text' => $moduleinfo->content, 'format' => $moduleinfo->contentformat, 'itemid' => 0];
        $moduleinfo->printintro = 0;
        $moduleinfo->printlastmodified = 1;
        \update_moduleinfo($cm, $moduleinfo, $course, null);
    }

    /**
     * Abnahmekriterium 3: vergleicht zwei beliebige Staende, hier direkt
     * benachbarte, mit den vollstaendigen Vorher-/Nachher-Werten.
     */
    public function test_compares_two_versions(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->update_page($course, $cm, 'Zweite Fassung');

        $result = compare_activity_versions::execute($cm->id, 1, 2);
        $result = external_api::clean_returnvalue(compare_activity_versions::execute_returns(), $result);

        $this->assertSame(1, $result['before']['version']);
        $this->assertSame(2, $result['after']['version']);

        $namefield = null;
        foreach ($result['changes'] as $change) {
            if ($change['field'] === 'name') {
                $namefield = $change;
            }
        }
        $this->assertNotNull($namefield);
        $this->assertSame(json_encode('Erste Fassung'), $namefield['before_json']);
        $this->assertSame(json_encode('Zweite Fassung'), $namefield['after_json']);
        $this->assertNotEmpty($result['gap_notice']);
    }

    /**
     * Abnahmekriterium 6: local/coursepilot:viewhistory wird geprueft.
     */
    public function test_rejects_user_without_capability(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $this->update_page($course, $cm, 'Zweite Fassung');

        $roleid = (int) $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:viewhistory',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        compare_activity_versions::execute($cm->id, 1, 2);
    }

    /**
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }
}
