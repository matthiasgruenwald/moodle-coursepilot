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
use local_coursepilot\catalog\write_target;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Assignment writes leave a usable primary grade item for enrolled learners.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(write_target::class)]
#[CoversClass(create_module::class)]
#[CoversClass(update_module_settings::class)]
final class assign_grade_item_write_test extends \advanced_testcase {
    /** @var \stdClass Test course. */
    private \stdClass $course;
    /** @var \stdClass Enrolled editing teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Enrolled learner. */
    private \stdClass $student;

    protected function setUp(): void {
        global $CFG, $PAGE;
        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        require_once($CFG->dirroot . '/mod/assign/externallib.php');
        $PAGE->set_url('/mod/assign/view.php');
        set_config('feedback_plugin_for_gradebook', 'assignfeedback_comments', 'assign');
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($this->teacher);
    }

    /**
     * @return array<string, array{int, string, bool, int}>
     */
    public static function grade_configurations(): array {
        return [
            'ungraded, comments off' => [0, 'comments', false, 0],
            'ungraded, comments on' => [0, 'comments', true, 3],
            'graded, comments off' => [1, 'comments', false, 1],
            'graded, comments on' => [1, 'comments', true, 1],
            'ungraded, file off' => [0, 'file', false, 0],
            'ungraded, file on' => [0, 'file', true, 3],
            'graded, file off' => [1, 'file', false, 1],
            'graded, file on' => [1, 'file', true, 1],
        ];
    }

    /**
     * @param int $grade
     * @return array<string, mixed>
     */
    private function fields(int $grade): array {
        return [
            'name' => 'Assignment', 'intro' => 'Practice', 'grade' => $grade,
            'assignfeedback_comments_enabled' => 0,
            'assignfeedback_editpdf_enabled' => 1,
            'assignfeedback_file_enabled' => 0,
            'assignfeedback_offline_enabled' => 0,
            'assignsubmission_file_enabled' => 0,
            'assignsubmission_onlinetext_enabled' => 0,
            'assignsubmission_comments_enabled' => 0,
        ];
    }

    /**
     * Checks the primary item and the learner paths that require it, including Moodle Mobile.
     *
     * @param int $cmid
     * @param int $grade
     * @param int $gradetype
     */
    private function assert_learner_can_read(int $cmid, int $grade, int $gradetype): void {
        global $DB;
        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $this->setUser($this->student);
        $assignment = new \assign(\context_module::instance($cmid), $cm, $this->course);
        $item = $assignment->get_grade_item();
        $this->assertEquals($gradetype, $item->gradetype);
        $this->assertEquals($grade, $assignment->get_instance()->grade);
        $this->assertEquals(1, $DB->count_records('grade_items', [
            'courseid' => $this->course->id, 'itemtype' => 'mod', 'itemmodule' => 'assign',
            'iteminstance' => $cm->instance, 'itemnumber' => 0,
        ]));
        $this->assertSame([], $assignment->get_assign_attempt_history_renderable($this->student)->grades);
        $mobile = external_api::clean_returnvalue(\mod_assign_external::get_submission_status_returns(),
            \mod_assign_external::get_submission_status($cm->instance));
        $this->assertSame([], $mobile['warnings']);
        $this->assertSame('notgraded', $mobile['lastattempt']['gradingstatus']);
        $this->setUser($this->teacher);
    }

    /**
     * @param int $grade
     * @param string $feedbackplugin
     * @param bool $enabled
     * @param int $gradetype
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grade_configurations')]
    public function test_create_has_usable_primary_item(int $grade, string $feedbackplugin, bool $enabled, int $gradetype): void {
        $fields = $this->fields($grade);
        set_config('feedback_plugin_for_gradebook', 'assignfeedback_' . $feedbackplugin, 'assign');
        $fields['assignfeedback_' . $feedbackplugin . '_enabled'] = (int) $enabled;
        $result = external_api::clean_returnvalue(create_module::execute_returns(), create_module::execute(
            $this->course->id, 0, 'assign', json_encode($fields)
        ));
        $this->assert_learner_can_read($result['cmid'], $grade, $gradetype);
    }

    /**
     * @param int $grade
     * @param string $feedbackplugin
     * @param bool $enabled
     * @param int $gradetype
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('grade_configurations')]
    public function test_update_repairs_missing_item_once(int $grade, string $feedbackplugin, bool $enabled, int $gradetype): void {
        global $DB;
        $fields = $this->fields($grade);
        set_config('feedback_plugin_for_gradebook', 'assignfeedback_' . $feedbackplugin, 'assign');
        $fields['assignfeedback_' . $feedbackplugin . '_enabled'] = (int) $enabled;
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id] + $fields);
        foreach (\grade_item::fetch_all(['itemtype' => 'mod', 'itemmodule' => 'assign',
                'iteminstance' => $instance->id]) ?: [] as $item) {
            $item->delete();
        }
        $before = $DB->get_records('assign_plugin_config', ['assignment' => $instance->id], 'id');
        for ($i = 0; $i < 2; $i++) {
            external_api::clean_returnvalue(update_module_settings::execute_returns(),
                update_module_settings::execute($instance->cmid, json_encode(['intro' => 'Changed instructions'])));
            $this->assert_learner_can_read($instance->cmid, $grade, $gradetype);
        }
        $this->assertEquals($before, $DB->get_records('assign_plugin_config', ['assignment' => $instance->id], 'id'));
        $this->assertSame('Changed instructions', $DB->get_field('assign', 'intro', ['id' => $instance->id]));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function repair_failures(): array {
        return [
            'create, API refuses locked item' => ['lock_seed', false],
            'update, API refuses locked item' => ['lock_seed', true],
            'create, successful status but missing final item' => ['remove_final', false],
            'update, successful status but missing final item' => ['remove_final', true],
        ];
    }

    /**
     * A real gradebook observer makes the API refuse the seeded item.
     *
     * @param \core\event\base $event
     */
    public static function lock_seed(\core\event\base $event): void {
        // Updated and deleted events inherit from created, but only the seed should be locked.
        if ($event::class !== \core\event\grade_item_created::class) {
            return;
        }
        $item = \grade_item::fetch(['id' => $event->objectid]);
        if ($item && $item->itemmodule === 'assign' && (int) $item->gradetype === GRADE_TYPE_TEXT) {
            $item->needsupdate = 0;
            $item->set_locked(1);
        }
    }

    /**
     * A real gradebook observer removes the final NONE item despite an OK API status.
     *
     * @param \core\event\base $event
     */
    public static function remove_final(\core\event\base $event): void {
        $item = \grade_item::fetch(['id' => $event->objectid]);
        if ($item && $item->itemmodule === 'assign' && (int) $item->gradetype === GRADE_TYPE_NONE) {
            // Deleting grades emits another update event; inject the disruption only once.
            \core\event\manager::phpunit_replace_observers([]);
            $item->delete();
        }
    }

    /**
     * @param string $callback
     * @param bool $updating
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('repair_failures')]
    public function test_failed_repair_rolls_back_entire_write(string $callback, bool $updating): void {
        global $DB;
        $this->preventResetByRollback();
        $fields = $this->fields(0);
        if ($updating) {
            $instance = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id] + $fields);
        }
        $tables = ['assign', 'assign_plugin_config', 'course_modules', 'course_sections', 'grade_items'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $DB->get_records($table, null, 'id');
        }
        \core\event\manager::phpunit_replace_observers([[
            'eventname' => $callback === 'lock_seed' ? '\core\event\grade_item_created' : '\core\event\grade_item_updated',
            'callback' => self::class . '::' . $callback,
        ]]);
        try {
            if ($updating) {
                update_module_settings::execute($instance->cmid, json_encode(['intro' => 'Must roll back']));
            } else {
                create_module::execute($this->course->id, 3, 'assign', json_encode($fields));
            }
            $this->fail('An assignment with no usable primary item must not report success.');
        } catch (\moodle_exception $e) {
            $this->assertSame('assignmentgradeitemfailed', $e->errorcode);
        } finally {
            \core\event\manager::phpunit_reset();
        }
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], $DB->get_records($table, null, 'id'), $table . ' must roll back.');
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function existing_item_types(): array {
        return ['NONE' => ['none'], 'VALUE' => ['value'], 'SCALE' => ['scale']];
    }

    /**
     * @param string $type
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('existing_item_types')]
    public function test_existing_locked_item_and_grades_are_preserved(string $type): void {
        global $DB;
        $grade = match ($type) {
            'none' => 0,
            'value' => 1,
            'scale' => -$this->getDataGenerator()->create_scale(['scale' => 'Try again,Done'])->id,
        };
        $fields = $this->fields($grade);
        $instance = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id] + $fields);
        if ($type === 'none') {
            \grade_update('mod/assign', $this->course->id, 'mod', 'assign', $instance->id, 0, null,
                ['gradetype' => GRADE_TYPE_TEXT, 'idnumber' => '']);
            $instance->cmidnumber = '';
            \assign_grade_item_update($instance);
        }
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $instance->id]);
        $gradeentry = new \grade_grade(['itemid' => $item->id, 'userid' => $this->student->id, 'rawgrade' => 1,
            'finalgrade' => 1, 'overridden' => 1]);
        $gradeentry->insert();
        $item->gradepass = 0.5;
        $item->needsupdate = 0;
        $item->set_hidden(1);
        $item->set_locked(1);
        $before = $DB->get_record('grade_items', ['id' => $item->id]);
        $beforegrades = $DB->get_records('grade_grades', ['itemid' => $item->id]);

        external_api::clean_returnvalue(update_module_settings::execute_returns(),
            update_module_settings::execute($instance->cmid, json_encode(['intro' => 'Changed instructions'])));

        $this->assertEquals($before, $DB->get_record('grade_items', ['id' => $item->id]));
        $this->assertEquals($beforegrades, $DB->get_records('grade_grades', ['itemid' => $item->id]));
        $this->assertSame('Changed instructions', $DB->get_field('assign', 'intro', ['id' => $instance->id]));
    }
}
