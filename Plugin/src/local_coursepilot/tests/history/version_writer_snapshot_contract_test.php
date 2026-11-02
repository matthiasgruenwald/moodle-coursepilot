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
namespace local_coursepilot\history;

use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Stored arrangement text, grade fields per activity and file metadata keys of version_writer (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(version_writer::class)]
final class version_writer_snapshot_contract_test extends \advanced_testcase {
    use caught_throwable;

    public function test_the_stored_quiz_arrangement_keeps_unicode_and_slashes_readable(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $DB->set_field('quiz_sections', 'heading', 'Übung/Test', ['quizid' => $quiz->id]);

        $versionid = version_writer::capture((int) $quiz->cmid, 2);

        $json = $DB->get_field('local_coursepilot_cm_version', 'arrangement_json', ['id' => $versionid]);
        $this->assertStringContainsString('"heading":"Übung/Test"', $json);
    }

    public function test_grade_fields_ignore_the_grade_item_of_another_module_with_the_same_instance_id(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id, 'gradepass' => 40]);
        // Instance ids are only unique per module: a quiz row with the same id owns a second item.
        $item = $DB->get_record('grade_items', [
            'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $course->id,
        ], '*', MUST_EXIST);
        unset($item->id);
        $item->itemmodule = 'quiz';
        $item->gradepass = 70;
        $item->itemname = 'Quiz twin';
        $DB->insert_record('grade_items', $item);

        $info = json_decode($DB->get_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            ['id' => version_writer::capture((int) $assign->cmid, 2)]
        ), true);

        $this->assertSame(format_float(40, 2), $info['gradepass']);
    }

    /**
     * Captures a version and turns a crash into a failed assertion, closing the transaction the crash left open.
     *
     * @param int $cmid Course module id.
     * @return int Version id.
     */
    private function capture_or_fail(int $cmid): int {
        global $DB;
        try {
            return version_writer::capture($cmid, 2);
        } catch (\Throwable $e) {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback();
            }
            $this->fail('capture was expected to succeed but threw ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    public function test_a_second_grade_item_of_an_activity_is_named_after_its_component(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        // Creation triggers the history observer too; its failure must be an assertion failure.
        $forum = $this->succeeded(fn() => $this->getDataGenerator()->create_module('forum', [
            'course' => $course->id, 'grade_forum' => 100, 'gradepass_forum' => 55,
        ]));

        $info = json_decode($DB->get_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            ['id' => $this->capture_or_fail((int) $forum->cmid)]
        ), true);

        $this->assertSame(format_float(55, 2), $info['gradepass_forum']);
    }

    public function test_an_extra_grade_item_gradepass_is_keyed_by_its_component(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $item = new \grade_item([
            'courseid' => $course->id,
            'categoryid' => \grade_category::fetch_course_category($course->id)->id,
            'itemtype' => 'mod',
            'itemmodule' => 'forum',
            'iteminstance' => $forum->id,
            'itemnumber' => 1,
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'gradepass' => 40,
        ], false);
        $item->insert();

        $info = json_decode($DB->get_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            ['id' => $this->capture_or_fail((int) $forum->cmid)]
        ), true);

        $key = \core_grades\component_gradeitems::get_field_name_for_itemnumber('mod_forum', 1, 'gradepass');
        $this->assertArrayHasKey($key, $info);
        $this->assertSame(format_float(40, 2), $info[$key]);
    }

    public function test_files_with_equal_content_at_different_paths_keep_their_own_metadata(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $fs = get_file_storage();
        foreach (['one.png', 'two.png'] as $name) {
            $fs->create_file_from_string([
                'contextid' => \context_module::instance($page->cmid)->id, 'component' => 'mod_page',
                'filearea' => 'intro', 'itemid' => 0, 'filepath' => '/', 'filename' => $name,
            ], 'same content');
        }

        version_writer::capture((int) $page->cmid, 2);

        $names = $DB->get_fieldset_select('local_coursepilot_cm_file', 'filename', '1 = 1 ORDER BY filename');
        $this->assertSame(['one.png', 'two.png'], $names);
    }

    public function test_empty_idnumber_and_language_of_a_restored_module_are_stored_as_empty_text(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        // A module restored from a backup carries NULL here, not an empty string.
        $DB->set_field('course_modules', 'idnumber', null, ['id' => $page->cmid]);
        $DB->set_field('course_modules', 'lang', null, ['id' => $page->cmid]);

        $info = json_decode($DB->get_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            ['id' => version_writer::capture((int) $page->cmid, 2)]
        ), true);

        $this->assertSame('', $info['cmidnumber']);
        $this->assertSame('', $info['lang']);
    }
}
