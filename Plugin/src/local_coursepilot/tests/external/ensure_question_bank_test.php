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
 * Ensure-Anlegen einer Fragensammlung (Spec 0017 §1, Ticket #412).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ensure_question_bank::class)]
final class ensure_question_bank_test extends \advanced_testcase {

    /**
     * Neuanlage: keine gleichnamige Fragensammlung im Kurs vorhanden.
     */
    public function test_creates_new_question_bank(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $result = ensure_question_bank::execute($course->id, 'Biologie 9a - Immunsystem');
        $result = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $result);

        $this->assertTrue($result['created']);
        $this->assertGreaterThan(0, $result['questionbankid']);
        $this->assertGreaterThan(0, $result['contextid']);
        $this->assertGreaterThan(0, $result['topcategoryid']);
        $this->assertSame('Biologie 9a - Immunsystem', $result['name']);
        $this->assertStringContainsString('angelegt', $result['message']);
    }

    /**
     * Wiederverwendung: ein zweiter Lauf mit demselben Namen legt nichts
     * doppelt an, sondern liefert dieselbe Bank mit angelegt=false.
     */
    public function test_reuses_existing_question_bank_with_same_name(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $first = ensure_question_bank::execute($course->id, 'Biologie 9a - Immunsystem');
        $first = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $first);

        $second = ensure_question_bank::execute($course->id, 'Biologie 9a - Immunsystem');
        $second = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $second);

        $this->assertFalse($second['created']);
        $this->assertSame($first['questionbankid'], $second['questionbankid']);
        $this->assertSame($first['contextid'], $second['contextid']);
        $this->assertSame($first['topcategoryid'], $second['topcategoryid']);
        $this->assertStringContainsString('wiederverwendet', $second['message']);

        global $DB;
        $modulename = \core_question\local\bank\question_bank_helper::get_default_question_bank_activity_name();
        $count = $DB->count_records_sql(
            "SELECT COUNT(*) FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND m.name = :modulename",
            ['courseid' => $course->id, 'modulename' => $modulename]
        );
        $this->assertSame(1, $count);
    }

    /**
     * Fall ohne native Berechtigung: moodle/course:manageactivities fehlt.
     */
    public function test_rejects_user_without_manageactivities_capability(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'moodle/course:manageactivities',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );
        $this->setUser($teacher);

        $this->expectException(\required_capability_exception::class);

        ensure_question_bank::execute($course->id, 'Biologie 9a - Immunsystem');
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
