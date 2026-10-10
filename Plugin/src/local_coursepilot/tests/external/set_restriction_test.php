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
 * The only write path for restrictions, from teacher-friendly
 * arguments instead of raw JSON (Spec 0015, Ticket #393).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(set_restriction::class)]
final class set_restriction_test extends \advanced_testcase {
    /**
     * Provides course with editing teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass} Course, teacher (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        set_config('enableavailability', 1);
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * Returns current state, same shape as get_module_settings.
     *
     * @param int $cmid
     * @return array Current state, same shape as get_module_settings.
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * Core criterion: a restriction ("after completion of activity X")
     * can be set without raw JSON and ends up as native availability JSON
     * on the activity.
     */
    public function test_completion_restriction_is_built_without_raw_json(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $lerncheck = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $result = external_api::clean_returnvalue(
            set_restriction::execute_returns(),
            set_restriction::execute($ziel->cmid, json_encode([
                ['type' => 'completion', 'activity_cmid' => $lerncheck->cmid, 'status' => 'pass'],
            ]))
        );

        $this->assertSame($ziel->cmid, $result['cmid']);
        $this->assertStringContainsString('Restriction', $result['message']);

        $availability = json_decode($this->read($ziel->cmid)['availabilityconditionsjson'], true);
        $this->assertSame('&', $availability['op']);
        $this->assertSame('completion', $availability['c'][0]['type']);
        $this->assertSame($lerncheck->cmid, $availability['c'][0]['cm']);
        $this->assertSame(2, $availability['c'][0]['e']); // COMPLETION_COMPLETE_PASS = passed.
    }

    /**
     * Date and group condition, combined (AND) - both remaining practically
     * relevant condition types in one call.
     */
    public function test_date_and_group_restriction_combine_with_and(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $gruppe = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $zeitstempel = time() + 3600;

        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'date', 'direction' => 'from', 'timestamp' => $zeitstempel],
            ['type' => 'group', 'group_id' => $gruppe->id],
        ]));

        $availability = json_decode($this->read($ziel->cmid)['availabilityconditionsjson'], true);
        $this->assertCount(2, $availability['c']);
        $this->assertSame('date', $availability['c'][0]['type']);
        $this->assertSame('>=', $availability['c'][0]['d']);
        $this->assertSame($zeitstempel, $availability['c'][0]['t']);
        $this->assertSame('group', $availability['c'][1]['type']);
        $this->assertSame((int) $gruppe->id, $availability['c'][1]['id']);
    }

    /**
     * "group_id": 0 AND "0" (string) both mean "any group" - a numeric
     * field must not depend, for an AI, on whether it writes the zero as a
     * JSON number or as a JSON string.
     */
    public function test_group_id_zero_as_string_means_any_group(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'group', 'group_id' => '0'],
        ]));

        $availability = json_decode($this->read($ziel->cmid)['availabilityconditionsjson'], true);
        $this->assertSame('group', $availability['c'][0]['type']);
        $this->assertArrayNotHasKey('id', $availability['c'][0]);
    }

    /**
     * An empty array removes all restrictions.
     */
    public function test_empty_array_removes_all_restrictions(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $lerncheck = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        $ziel = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'completion', 'activity_cmid' => $lerncheck->cmid, 'status' => 'complete'],
        ]));
        $this->assertNotSame('', $this->read($ziel->cmid)['availabilityconditionsjson']);

        $result = external_api::clean_returnvalue(
            set_restriction::execute_returns(),
            set_restriction::execute($ziel->cmid, json_encode([]))
        );

        $this->assertStringContainsString('removed', $result['message']);
        $this->assertSame('', $this->read($ziel->cmid)['availabilityconditionsjson']);
    }

    /**
     * Criterion 2: raw availability JSON cannot be set via
     * update_module_settings - the field catalog does not list it at all
     * (#388, already done), verified here rather than repeated.
     */
    public function test_raw_availability_json_is_not_settable_via_update_module_settings(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            update_module_settings::execute($page->cmid, json_encode(['availabilityconditionsjson' => '{"op":"&","c":[]}']));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('availabilityconditionsjson', $e->getMessage());
        }
    }

    /**
     * Criterion 3: an invalid condition (unknown type) fails with a message
     * naming the field - nothing is written, the course page stays
     * reachable (no broken JSON ends up in the DB).
     */
    public function test_invalid_condition_type_fails_with_field_name_and_writes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_restriction::execute($page->cmid, json_encode([
                ['type' => 'unknown'],
            ]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('type', $e->getMessage());
        }

        $raw = $DB->get_field('course_modules', 'availability', ['id' => $page->cmid]);
        $this->assertNull($raw);
        // Course page stays reachable: no core_availability\tree error on construction.
        $info = new \core_availability\info_module(get_fast_modinfo($course)->get_cm($page->cmid));
        $this->assertTrue($info->is_available($ignored));
    }

    /**
     * A reference to a non-existent activity fails likewise before
     * writing, with the field name in the message.
     */
    public function test_completion_condition_with_unknown_activity_fails_with_field_name(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            set_restriction::execute($page->cmid, json_encode([
                ['type' => 'completion', 'activity_cmid' => 999999, 'status' => 'complete'],
            ]));
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString('activity_cmid', $e->getMessage());
        }
        $this->assertSame('', $this->read($page->cmid)['availabilityconditionsjson']);
    }

    /**
     * Criterion 4: writing goes through update_moduleinfo(), not directly
     * into the DB - a parallel, independent change to another field
     * survives.
     */
    public function test_writes_via_update_moduleinfo_not_direct_db(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Unchanged',
        ]);

        set_restriction::execute($page->cmid, json_encode([
            ['type' => 'group'],
        ]));

        $this->assertSame('Unchanged', $this->read($page->cmid)['name']);
    }

    /**
     * Criterion 5: "profile" conditions stay masked on read (ADR 0011) -
     * unchanged by this tool, checked via the existing get_module_settings
     * masking. set_restriction does not offer "profile" itself (deliberate
     * ponytail restriction).
     */
    public function test_profile_condition_from_elsewhere_stays_masked_on_read(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        // Simulates a profile condition set via the native form route
        // (outside of Coursepilot).
        $DB->set_field('course_modules', 'availability', json_encode([
            'op' => '&',
            'c' => [['type' => 'profile', 'sf' => 'department', 'op' => 'isequalto', 'v' => 'Physik']],
            'showc' => [true],
        ]), ['id' => $page->cmid]);
        rebuild_course_cache($course->id, true);

        $availability = json_decode($this->read($page->cmid)['availabilityconditionsjson'], true);
        $this->assertSame('***', $availability['c'][0]['v']);
        $this->assertSame('department', $availability['c'][0]['sf']);
    }

    /**
     * Criterion 6: the operation creates a version in the change history
     * (course_module_updated is observed automatically, #385-387).
     */
    public function test_write_creates_a_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]);

        set_restriction::execute($page->cmid, json_encode([
            ['type' => 'group'],
        ]));

        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]));
    }

    /**
     * Criterion 7: native capability check in the course context - without
     * edit permission the write attempt fails.
     */
    public function test_requires_manageactivities_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'student');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        set_restriction::execute($page->cmid, json_encode([
            ['type' => 'group'],
        ]));
    }

    /**
     * Lock (#583): a grade condition on a teacher-graded assignment makes
     * learners wait for the teacher - rejected without confirmation,
     * nothing written; set with confirmation.
     */
    public function test_grade_condition_on_teacher_graded_assign_needs_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $aufgabe = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $ziel = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $conditions = json_encode([['type' => 'completion', 'activity_cmid' => (int) $aufgabe->cmid, 'status' => 'pass']]);

        try {
            set_restriction::execute($ziel->cmid, $conditions);
            $this->fail('A grade condition on an assignment should have required confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
            $this->assertStringContainsString('teacher_grade:' . $aufgabe->cmid, $e->getMessage());
        }
        $this->assertSame('', (string) ($this->read($ziel->cmid)['availabilityconditionsjson'] ?? ''));

        set_restriction::execute($ziel->cmid, $conditions, ['teacher_grade:' . $aufgabe->cmid]);
        $this->assertStringContainsString('"completion"', $this->read($ziel->cmid)['availabilityconditionsjson']);

        // Unchanged existing condition plus new date condition: no renewed confirmation.
        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'completion', 'activity_cmid' => (int) $aufgabe->cmid, 'status' => 'pass'],
            ['type' => 'date', 'direction' => 'from', 'timestamp' => 1767225600],
        ]));
        $this->assertStringContainsString('"date"', $this->read($ziel->cmid)['availabilityconditionsjson']);
    }

    /**
     * A quiz grades itself - no confirmation needed.
     */
    public function test_grade_condition_on_automatic_quiz_needs_no_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $test = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $ziel = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'completion', 'activity_cmid' => (int) $test->cmid, 'status' => 'pass'],
        ]));

        $this->assertStringContainsString('"completion"', $this->read($ziel->cmid)['availabilityconditionsjson']);
    }

    /**
     * An assignment without grading (grade = 0) waits for no teacher grade.
     */
    public function test_condition_on_ungraded_assign_needs_no_confirmation(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $aufgabe = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'grade' => 0]);
        $ziel = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        set_restriction::execute($ziel->cmid, json_encode([
            ['type' => 'completion', 'activity_cmid' => (int) $aufgabe->cmid, 'status' => 'pass'],
        ]));

        $this->assertStringContainsString('"completion"', $this->read($ziel->cmid)['availabilityconditionsjson']);
    }
}
