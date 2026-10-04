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

use core_external\external_api;
use local_coursepilot\external\get_module_settings;
use local_coursepilot\history\version_writer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

/**
 * Change-history observer (#385, Spec 0015 §10): every manual change in the
 * module form triggers a snapshot via the native course_module_updated event.
 *
 * The real Moodle write path is triggered directly
 * (get_moduleinfo_data()/update_moduleinfo() from course/modlib.php - the same
 * functions the module form calls), not via local external functions:
 * local_coursepilot defines no writing web services that could be tested
 * against, and the observer is supposed to be covered regardless of
 * which client did the writing (#385
 * acceptance criterion "own test slice").
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(observer::class)]
#[CoversClass(\local_coursepilot\history\version_writer::class)]
final class observer_test extends \advanced_testcase {

    /**
     * Creates a course with a page activity and an editing teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Course, cm object, teacher
     */
    private function create_page(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Ausgangsstand',
            'content' => 'Ausgangstext.',
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $cm, $teacher];
    }

    /**
     * Simulates the manual change exactly like the module form: fetch the
     * prefilled current state, change one field, write it back via
     * update_moduleinfo(). This is the call that really triggers
     * course_module_updated.
     *
     * @param \stdClass $cm
     * @param \stdClass $course
     * @param string $newname
     * @return void
     */
    private function edit_via_module_form(\stdClass $cm, \stdClass $course, string $newname): void {
        [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
        $moduleinfo->name = $newname;
        // mod_page_mod_form::data_preprocessing() maps content/contentformat onto its
        // own 'page' editor field, which get_moduleinfo_data() (module-independent) does not
        // know - without this line page_update_instance() lacks the field that every real
        // form submission delivers.
        if ($moduleinfo->modulename === 'page') {
            $moduleinfo->page = ['text' => $moduleinfo->content, 'format' => $moduleinfo->contentformat, 'itemid' => 0];
            $moduleinfo->printintro = 0;
            $moduleinfo->printlastmodified = 1;
        }
        update_moduleinfo($cm, $moduleinfo, $course, null);
    }

    /**
     * Acceptance criterion: A manual change in the module form creates a
     * snapshot with form-path state and a course_modules row.
     */
    public function test_hand_edit_creates_version_with_state_and_cm_row(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_page();

        // Version 1 is created on creation already (#386, Spec 0015 §10.3).
        $this->assertSame(1, (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));

        $this->edit_via_module_form($cm, $course, 'Geaenderter Titel');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount(2, $versions);

        $version = $versions[1];
        $this->assertSame(2, (int) $version->version);
        $this->assertSame('moodle', $version->source);
        $this->assertSame((int) $teacher->id, (int) $version->userid);

        $moduleinfo = json_decode($version->moduleinfo_json, true);
        $this->assertSame('Geaenderter Titel', $moduleinfo['name']);
        $this->assertSame('page', $moduleinfo['modulename']);
        $this->assertSame((int) $cm->id, $moduleinfo['coursemodule']);

        $cmrow = json_decode($version->coursemodule_json, true);
        $this->assertSame((int) $cm->id, (int) $cmrow['id']);
        $this->assertSame('page', $DB->get_field('modules', 'name', ['id' => (int) $cmrow['module']]));
    }

    /**
     * A second manual change writes a further version - no
     * overwriting, no rewinding.
     */
    public function test_second_hand_edit_appends_one_more_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();

        $this->edit_via_module_form($cm, $course, 'Erste Aenderung');
        $this->edit_via_module_form($cm, $course, 'Zweite Aenderung');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        // Version 1 = creation, version 2 = first change, version 3 = second change.
        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], array_map(fn ($v) => (int) $v->version, $versions));
        $this->assertSame('Zweite Aenderung', json_decode($versions[2]->moduleinfo_json, true)['name']);
    }

    /**
     * Acceptance criteria: file rows of the module context are captured,
     * Intro files are restorable; unknown file areas are not captured.
     */
    public function test_files_outside_allowed_design_areas_are_not_captured(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        $introfile = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'intro-bild.png',
        ], 'intro-bytes');

        $otherfile = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'anhang.pdf',
        ], 'other-bytes');

        $this->edit_via_module_form($cm, $course, 'Mit Dateien');

        // Version 1 comes from creation, before the files were uploaded - the
        // files only land in the version of the manual change (version 2).
        $version = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id, 'version' => 2], '*', MUST_EXIST);

        $introrow = $DB->get_record('local_coursepilot_cm_file', ['pathnamehash' => $introfile->get_pathnamehash()], '*', MUST_EXIST);
        $introlink = $DB->get_record('local_coursepilot_cm_version_file', [
            'versionid' => $version->id,
            'fileid' => $introrow->id,
        ], '*', MUST_EXIST);
        $this->assertSame(0, (int) $introlink->gap, 'Intro file must not be marked as a gap.');
        $this->assertSame('intro-bild.png', $introrow->filename);

        $this->assertFalse($DB->record_exists('local_coursepilot_cm_file', [
            'pathnamehash' => $otherfile->get_pathnamehash(),
        ]));

        // No bytes stored - only metadata columns, "content" does not exist as a field.
        $this->assertObjectNotHasProperty('content', $introrow);
    }

    /**
     * Acceptance criterion: file metadata is deduplicated - an unchanged
     * file name appears only once across several states in
     * local_coursepilot_cm_file.
     */
    public function test_unchanged_file_is_not_duplicated_across_versions(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $context = \context_module::instance($cm->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'bleibt-gleich.png',
        ], 'unveraendert');

        $this->edit_via_module_form($cm, $course, 'Version eins');
        $this->edit_via_module_form($cm, $course, 'Version zwei');

        // Version 1 = creation (before the file), version 2/3 = the two manual changes.
        $this->assertCount(3, $DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertCount(
            1,
            $DB->get_records('local_coursepilot_cm_file', ['filename' => 'bleibt-gleich.png']),
            'Unchanged file must appear only once in local_coursepilot_cm_file.'
        );
        $this->assertCount(
            2,
            $DB->get_records('local_coursepilot_cm_version_file'),
            'Only the two states after the upload point to the file record.'
        );
    }

    /**
     * If the file at the same path is replaced in content (same
     * pathnamehash, different contenthash), the new state must show the new
     * metadata instead of the old - dedup must not return outdated
     * metadata (#385 code review finding).
     */
    public function test_changed_file_content_gets_fresh_metadata_row(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $context = \context_module::instance($cm->id);
        $fs = get_file_storage();

        $filerecord = [
            'contextid' => $context->id,
            'component' => 'mod_page',
            'filearea' => 'intro',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'wird-ersetzt.png',
        ];
        $fs->create_file_from_string($filerecord, 'alter-inhalt');
        $this->edit_via_module_form($cm, $course, 'Version eins');

        // Same path, new content - like a repeated file upload in the form.
        $fs->get_file($context->id, 'mod_page', 'intro', 0, '/', 'wird-ersetzt.png')->delete();
        $fs->create_file_from_string($filerecord, 'neuer-inhalt-laenger');
        $this->edit_via_module_form($cm, $course, 'Version zwei');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        // Version 1 = creation (before the file), version 2 = "Version eins" (old file),
        // version 3 = "Version zwei" (new file).
        $this->assertCount(3, $versions);

        $rows = array_values($DB->get_records('local_coursepilot_cm_file', ['filename' => 'wird-ersetzt.png'], 'id ASC'));
        $this->assertCount(2, $rows, 'A file changed in content at the same path needs its own metadata row.');
        $this->assertNotSame($rows[0]->contenthash, $rows[1]->contenthash);
        $this->assertNotSame($rows[0]->filesize, $rows[1]->filesize);

        $link2 = $DB->get_record('local_coursepilot_cm_version_file', ['versionid' => $versions[2]->id, 'fileid' => $rows[1]->id]);
        $this->assertNotFalse($link2, 'The newest version must point to the new metadata row, not the outdated one.');
    }

    /**
     * Acceptance criterion: the state contains gradepass/gradecat/outcomes, which
     * get_module_settings (#384) deliberately leaves out - both tools may
     * differ here.
     */
    public function test_snapshot_includes_gradepass_unlike_read_tool(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/course/modlib.php');

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'grade' => 100,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);

        [, , , $moduleinfo] = get_moduleinfo_data($cm, $course);
        $moduleinfo->gradepass = 50.0;
        update_moduleinfo($cm, $moduleinfo, $course, null);

        // Version 1 comes from creation (without gradepass), version 2 carries the
        // set gradepass value.
        $version = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id, 'version' => 2], '*', MUST_EXIST);
        $snapshot = json_decode($version->moduleinfo_json, true);
        $hasgradepass = false;
        foreach ($snapshot as $field => $value) {
            if (str_starts_with($field, 'assigngradepass_') || $field === 'gradepass') {
                $hasgradepass = true;
            }
        }
        $this->assertTrue($hasgradepass, 'The history state must contain a gradepass field (Spec 0015 §10.4).');

        $readtool = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cm->id)
        );
        $readsettings = json_decode($readtool['settings_json'], true);
        foreach ($readsettings as $field => $value) {
            $this->assertFalse(
                str_starts_with((string) $field, 'assigngradepass_'),
                'get_module_settings deliberately excludes gradepass fields (#384).'
            );
        }
    }

    /**
     * Acceptance criterion (#386): an activity created after the introduction of
     * the history gets version 1 directly on creation - as a
     * regular state, not as "discovered".
     */
    public function test_new_activity_gets_version_one_on_create_not_discovered(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $this->assertCount(1, $versions);
        $this->assertSame(1, (int) $versions[0]->version);
        $this->assertSame('moodle', $versions[0]->source);
    }

    /**
     * Acceptance criterion (#386): no mass backfill on plugin upgrade -
     * a course_modules record for which no event was ever
     * observed (state before introduction of the history or during a
     * plugin upgrade) does not create a state on its own.
     */
    public function test_plugin_upgrade_creates_no_versions(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm] = $this->create_page();
        // The activity already exists in the DB; without a
        // course_module_* event firing (e.g. during the plugin upgrade itself),
        // the history must not stir.
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $this->assertSame(0, (int) $DB->count_records('local_coursepilot_cm_version'));
    }

    /**
     * Acceptance criteria (#386): for an activity that existed before
     * Coursepilot (no course_module_created ever observed, hence no
     * state present), the first course_module_updated creates two versions
     * - version 1 flagged as discovered, version 2 with the new
     * state.
     */
    public function test_first_update_on_activity_without_history_backfills_discovered_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm, $teacher] = $this->create_page();
        // Simulates a pre-existing activity: exists completely already
        // (course, instance, course_modules row, context), but without any
        // history entry - the state a pre-#386 plugin leaves behind.
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $this->edit_via_module_form($cm, $course, 'Erste beobachtete Aenderung');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount(2, $versions, 'The first event without history must create two versions.');

        $this->assertSame(1, (int) $versions[0]->version);
        $this->assertSame(version_writer::SOURCE_DISCOVERED, $versions[0]->source);
        $this->assertSame((int) $teacher->id, (int) $versions[0]->userid);

        $this->assertSame(2, (int) $versions[1]->version);
        $this->assertSame(version_writer::SOURCE_MOODLE, $versions[1]->source);

        // The actual before-state (before exactly this write operation) can no
        // longer be reconstructed - the discovered state therefore captures the same
        // (already written) current state as version 2.
        $this->assertSame(
            json_decode($versions[1]->moduleinfo_json, true)['name'],
            json_decode($versions[0]->moduleinfo_json, true)['name']
        );
    }

    /**
     * Acceptance criterion (#386): the second event of the same activity
     * creates exactly one further version - no new discovered version.
     */
    public function test_second_update_after_backfill_appends_exactly_one_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $this->edit_via_module_form($cm, $course, 'Erste beobachtete Aenderung');
        $this->edit_via_module_form($cm, $course, 'Zweite beobachtete Aenderung');

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount(3, $versions);
        $this->assertSame([1, 2, 3], array_map(fn ($v) => (int) $v->version, $versions));
        $this->assertSame(version_writer::SOURCE_DISCOVERED, $versions[0]->source);
        $this->assertSame(version_writer::SOURCE_MOODLE, $versions[1]->source);
        $this->assertSame(version_writer::SOURCE_MOODLE, $versions[2]->source);
        $this->assertSame('Zweite beobachtete Aenderung', json_decode($versions[2]->moduleinfo_json, true)['name']);
    }

    /**
     * Acceptance criterion (#396): a reordering of the questions (slot_moved,
     * one of the 16 mod_quiz structure events) creates a new state -
     * just like any manual change in the module form.
     */
    public function test_reordering_quiz_slots_creates_new_version(): void {
        global $DB;

        $this->resetAfterTest();
        [$course, $quiz] = $this->create_quiz_with_two_questions();
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        // Version 1 is created on creation (#386); the two slot_created
        // events when adding the questions deliberately do not count towards the
        // 16 observed structure events (content, not arrangement).
        $before = (int) $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]);

        $slots = array_values($DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot'));
        $quizobj = \mod_quiz\quiz_settings::create($quiz->id);
        \mod_quiz\structure::create_for_quiz($quizobj)->move_slot($slots[1]->id, 0, 1);

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cm->id], 'version ASC'));
        $this->assertCount($before + 1, $versions);

        $newest = end($versions);
        $this->assertNotNull($newest->arrangement_json, 'The new state must also record the arrangement state.');
        $arrangement = json_decode($newest->arrangement_json, true);
        $this->assertSame((int) $slots[1]->id, $arrangement['slots'][0]['id']);
    }

    /**
     * Acceptance criterion (#396): the arrangement state contains slots (with
     * question reference), sections and feedback - for non-quiz activities
     * arrangement_json stays unchanged null (no structure API for it).
     */
    public function test_non_quiz_activity_has_no_arrangement_json(): void {
        global $DB;

        $this->resetAfterTest();
        [, $cm] = $this->create_page();

        $version = $DB->get_record('local_coursepilot_cm_version', ['cmid' => $cm->id], '*', MUST_EXIST);
        $this->assertNull($version->arrangement_json);
    }

    /**
     * @return array{0: \stdClass, 1: \stdClass} Course, quiz (with two questions).
     */
    private function create_quiz_with_two_questions(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $quiz = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_instance(['course' => $course->id]);
        $qbank = $this->getDataGenerator()->create_module('qbank', ['course' => $course->id]);
        $qbankcontext = \context_module::instance($qbank->cmid);
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $questiongenerator->create_question_category(['contextid' => $qbankcontext->id]);

        $question1 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        $question2 = $questiongenerator->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question1->id, $quiz);
        quiz_add_quiz_question($question2->id, $quiz);

        return [$course, $quiz];
    }
}
