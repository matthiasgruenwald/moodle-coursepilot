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
 * "Three versions ago it was better" as a write (Spec 0015 §10.7,
 * ticket #395): carry forward instead of rewinding.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(restore_activity_version::class)]
final class restore_activity_version_test extends \advanced_testcase {
    /**
     * Provides course with editing teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass} Course, teacher (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        set_config('enablecompletion', COMPLETION_ENABLED);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
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
     * Seeds completion data.
     *
     * @param int $cmid
     * @param int $userid
     * @return void
     */
    private function seed_completion_data(int $cmid, int $userid): void {
        global $DB;
        $DB->insert_record('course_modules_completion', [
            'coursemoduleid' => $cmid,
            'userid' => $userid,
            'completionstate' => COMPLETION_COMPLETE,
            'timemodified' => time(),
        ]);
    }

    /**
     * Acceptance criterion 1: a restore creates a new latest version
     * instead of a rewind - the cmid stays unchanged, the old field value
     * appears as the new current state.
     */
    public function test_restore_writes_target_state_forward_keeping_cmid(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;

        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));
        $this->assertSame('Neu', $this->read($cmid)['name']);

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertSame($cmid, $result['cmid']);
        $this->assertSame('page', $result['modname']);
        $this->assertSame('Alt', $this->read($cmid)['name']);

        // Carried forward, not rewound: three states (creation, change,
        // restore), none deleted/overwritten.
        $this->assertSame(3, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]));
    }

    /**
     * The page content sits in the version state as "content", but when writing
     * Moodle reads it exclusively from the editor pseudofield "page".
     * A restore must therefore not carry the current content forward.
     */
    public function test_restore_writes_back_page_content(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'content' => '<p>Version eins</p>',
            'contentformat' => FORMAT_PLAIN,
        ]);
        $cmid = $page->cmid;

        update_module_settings::execute($cmid, json_encode([
            'page' => ['text' => '<p>Version zwei</p>', 'format' => FORMAT_HTML, 'itemid' => 0],
        ]));
        $this->assertSame('<p>Version zwei</p>', $this->read($cmid)['content']);

        restore_activity_version::execute($cmid, 1);

        $this->assertSame('<p>Version eins</p>', $this->read($cmid)['content']);
        $this->assertSame(FORMAT_PLAIN, $this->read($cmid)['contentformat']);
    }

    /**
     * The activity text of an assignment is also taken over by Moodle from the
     * activityeditor pseudofield.
     */
    public function test_restore_writes_back_assign_activity_content(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        update_module_settings::execute($cmid, json_encode([
            'activity' => '<p>Version eins</p>',
            'activityformat' => FORMAT_PLAIN,
        ]));
        $this->assertSame('<p>Version eins</p>', $this->read($cmid)['activity']);
        $this->assertSame(FORMAT_PLAIN, $this->read($cmid)['activityformat']);

        update_module_settings::execute($cmid, json_encode(['activity' => '<p>Version zwei</p>']));
        $this->assertSame('<p>Version zwei</p>', $this->read($cmid)['activity']);

        restore_activity_version::execute($cmid, 2);

        $this->assertSame('<p>Version eins</p>', $this->read($cmid)['activity']);
    }

    /**
     * Acceptance criterion 2: after a restore no additional activity
     * appears in the course.
     */
    public function test_restore_creates_no_additional_activity(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));

        $before = $DB->count_records('course_modules', ['course' => $course->id]);
        restore_activity_version::execute($cmid, 1);
        $after = $DB->count_records('course_modules', ['course' => $course->id]);

        $this->assertSame($before, $after);
    }

    /**
     * Acceptance criterion 3 (proxy): the cmid - and with it every link/every
     * prerequisite that references it - stays reachable unchanged.
     */
    public function test_restore_keeps_cmid_reachable(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));

        restore_activity_version::execute($cmid, 1);

        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $this->assertSame($cmid, (int) $cm->id);
    }

    /**
     * Acceptance criterion 4+5: without "confirmed" the completion fields
     * stay untouched if writing back would delete existing completion
     * data - the message is set_completion's real data-loss warning
     * with the number of affected learners, not an invention of its own.
     */
    public function test_restore_without_confirmation_leaves_completion_fields_untouched_on_data_loss_risk(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = $page->cmid;

        // Version 2: completion set to automatic (no learner data yet,
        // runs through without the two-step flow).
        set_completion::execute($cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertSame(COMPLETION_TRACKING_AUTOMATIC, $this->read($cmid)['completion']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $cmid]));
        foreach ($result['changes'] as $change) {
            $this->assertNotSame('completion', $change['field']);
        }
        $this->assertStringContainsString('confirmed', $result['message']);
        // The real number of affected learners from set_completion's two-step flow, not just
        // a generic warning (the test environment runs in English).
        $this->assertStringContainsString('1 learner', $result['message']);
    }

    /**
     * Acceptance criterion 5+6: only the two-step flow with "confirmed": true writes back
     * completion fields that would delete existing completion data -
     * via set_completion, not via a mechanism of its own.
     */
    public function test_restore_with_confirmation_writes_back_completion_fields(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = $page->cmid;

        set_completion::execute($cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));
        $student = $this->getDataGenerator()->create_user();
        $this->seed_completion_data($cmid, $student->id);

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1, true)
        );

        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($cmid)['completion']);
        $fields = array_column($result['changes'], 'field');
        $this->assertContains('completion', $fields);
    }

    /**
     * Without data-loss risk (no existing completion data) the restore of
     * the completion fields runs through immediately, like any other
     * set_completion() call without risk - "confirmed" is not needed
     * here, restore does not invent an additional hurdle of its own.
     */
    public function test_restore_writes_back_completion_fields_without_confirmation_when_no_data_at_risk(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $cmid = $page->cmid;

        // Version 2, no learner data present.
        set_completion::execute($cmid, json_encode(['completion' => COMPLETION_TRACKING_AUTOMATIC]));

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertSame(COMPLETION_TRACKING_MANUAL, $this->read($cmid)['completion']);
        $fields = array_column($result['changes'], 'field');
        $this->assertContains('completion', $fields);
    }

    /**
     * Without an actual difference (target = current state) nothing is
     * written - no new history version, clear "no change" message.
     */
    public function test_restore_to_current_version_is_a_noop(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertEmpty($result['changes']);
        $this->assertStringContainsString('No change', $result['message']);
        $this->assertSame(1, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]));
    }

    /**
     * Acceptance criterion: an unknown target version fails clearly.
     */
    public function test_unknown_target_version_is_rejected(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);

        try {
            restore_activity_version::execute($page->cmid, 99);
            $this->fail('Expected moodle_exception was not thrown.');
        } catch (\moodle_exception $e) {
            $this->assertSame('versionnotfound', $e->errorcode);
        }
    }

    /**
     * Acceptance criterion 7: local/coursepilot:restoreversion is checked.
     */
    public function test_rejects_user_without_own_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        update_module_settings::execute($page->cmid, json_encode(['name' => 'Neu']));

        $roleid = $this->get_role_id('editingteacher');
        assign_capability(
            'local/coursepilot:restoreversion',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id,
            true
        );

        $this->expectException(\required_capability_exception::class);
        restore_activity_version::execute($page->cmid, 1);
    }

    /**
     * Acceptance criterion 7: moodle/course:manageactivities is additionally
     * checked - a non-editing teacher (has local/coursepilot:restoreversion by
     * default, but not manageactivities) fails.
     */
    public function test_rejects_user_without_native_manageactivities_capability(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance(['course' => $course->id]);
        update_module_settings::execute($page->cmid, json_encode(['name' => 'Neu']));

        $nonedit = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($nonedit->id, $course->id, 'teacher');
        $this->setUser($nonedit);

        $this->expectException(\required_capability_exception::class);
        restore_activity_version::execute($page->cmid, 1);
    }

    /**
     * Acceptance criterion: the write-back itself creates a version in the
     * change history - via the same course_module_updated observer
     * as any other update_moduleinfo() call.
     */
    public function test_restore_creates_history_version(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));
        $before = $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]);

        restore_activity_version::execute($cmid, 1);

        $this->assertGreaterThan($before, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cmid]));
    }

    /**
     * Acceptance criterion: the response is the change message.
     */
    public function test_response_message_names_changed_field(): void {
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Alt',
        ]);
        $cmid = $page->cmid;
        update_module_settings::execute($cmid, json_encode(['name' => 'Neu']));

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertStringContainsString('name', $result['message']);
        $this->assertStringContainsString('version 1', $result['message']);
    }

    /**
     * Creates a material file for the currently logged-in user,
     * replacing it if it already exists - the same storage location
     * upload_material_file writes to (issue #428).
     *
     * @param string $path
     * @param string $content
     * @return void
     */
    private function create_material_file(string $path, string $content): void {
        $filerecord = \local_coursepilot\material_files::filerecord(
            \local_coursepilot\material_files::own_context()->id,
            '/coursepilot-material/',
            $path
        );
        $existing = get_file_storage()->get_file(
            $filerecord['contextid'],
            $filerecord['component'],
            $filerecord['filearea'],
            $filerecord['itemid'],
            $filerecord['filepath'],
            $filerecord['filename']
        );
        \local_coursepilot\material_files::replace($existing ?: null, $filerecord, $content);
    }

    /**
     * Rollback brings back a replaced activity file: version 1 attaches file A,
     * version 2 replaces it with
     * file B (same file name, different content) - the restore to
     * version 1 must have file A attached to the activity again, not
     * the gap from Spec 0015 §10.4.
     */
    public function test_restore_brings_back_a_replaced_activity_file(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance(
            ['course' => $course->id] + ((int) $CFG->branch >= 502 ? [
                'markingworkflow' => 1, 'markingallocation' => 1, 'markercount' => 2,
                'multimarkmethod' => 'average', 'multimarkrounding' => 2,
            ] : [])
        );
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;
        $modulecontext = \context_module::instance($cmid);
        set_config('allowpersonaldata', 1, 'local_coursepilot');
        get_file_storage()->create_file_from_string([
            'contextid' => $modulecontext->id, 'component' => 'mod_assign', 'filearea' => 'intro',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'design.png',
        ], 'Intro design');

        $this->create_material_file('blatt.pdf', 'Fassung A');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']]));
        // Version 2.

        $this->create_material_file('blatt.pdf', 'Fassung B');
        update_module_settings::execute($cmid, json_encode(['introattachments' => ['blatt.pdf']]));
        // Version 3.

        $replaced = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf');
        $this->assertSame('Fassung B', $replaced->get_content());

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 2)
        );

        $restored = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'introattachment', 0, '/', 'blatt.pdf');
        $this->assertNotFalse($restored);
        $this->assertSame('Fassung A', $restored->get_content());
        $intro = get_file_storage()->get_file($modulecontext->id, 'mod_assign', 'intro', 0, '/', 'design.png');
        $this->assertNotFalse($intro);
        $this->assertSame('Intro design', $intro->get_content());
        if ((int) $CFG->branch >= 502) {
            $after = $DB->get_record('assign', ['id' => $assign->id], '*', MUST_EXIST);
            $this->assertEquals(2, $after->markercount);
            $this->assertEquals(1, $after->markingallocation);
            $this->assertSame('average', $after->multimarkmethod);
            $this->assertEquals(2, $after->multimarkrounding);
        }
        $versions = \local_coursepilot\history\version_history::list_versions($cmid)['versions'];
        $this->assertSame([1, 2, 3, 4], array_column($versions, 'version'));
        $files = \local_coursepilot\history\version_history::files_at($cmid, 4);
        $this->assertContains('design.png', array_column($files, 'filename'));
        $this->assertContains('blatt.pdf', array_column($files, 'filename'));
        $this->assertStringContainsString('blatt.pdf', $result['message']);
        $this->assertStringContainsString('recycle bin', $result['message']);
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
     * The module-specific completion field also comes back - via
     * set_completion, not via the generic patch (there it is on the
     * blocklist of assign/choice, ticket #461).
     */
    public function test_restore_writes_back_completionsubmit(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();
        $assign = $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_instance([
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
        ]);
        $cmid = (int) get_coursemodule_from_instance('assign', $assign->id)->id;

        set_completion::execute($cmid, json_encode(['completionsubmit' => 1]));
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));

        $result = external_api::clean_returnvalue(
            restore_activity_version::execute_returns(),
            restore_activity_version::execute($cmid, 1)
        );

        $this->assertEquals(0, $DB->get_field('assign', 'completionsubmit', ['id' => $assign->id]));
        $this->assertContains('completionsubmit', array_column($result['changes'], 'field'));
    }
}
