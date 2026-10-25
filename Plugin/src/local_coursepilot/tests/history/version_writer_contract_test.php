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
 * Stored content, numbering, file metadata and failure behaviour of version_writer.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(version_writer::class)]
final class version_writer_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /**
     * Course and admin user.
     */
    private function setup_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
    }

    /**
     * Newest stored row for an activity.
     *
     * @param int $cmid
     * @return \stdClass
     */
    private function newest(int $cmid): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_coursepilot_cm_version', ['cmid' => $cmid], 'version DESC', '*', 0, 1);
        return reset($rows);
    }

    public function test_the_stored_module_state_has_exact_types_and_unescaped_text(): void {
        global $DB;
        $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id, 'name' => 'Größe/Ä', 'idnumber' => 'cm-1', 'showdescription' => 1, 'visible' => 1,
        ], ['section' => 2]);
        $DB->set_field('course_modules', 'lang', 'de', ['id' => $page->cmid]);
        \core_tag_tag::set_item_tags('core', 'course_modules', $page->cmid, \context_module::instance($page->cmid), ['alpha']);

        $versionid = version_writer::capture((int) $page->cmid, (int) get_admin()->id, 'moodle', null);

        $row = $DB->get_record('local_coursepilot_cm_version', ['id' => $versionid], '*', MUST_EXIST);
        $this->assertStringContainsString('"name":"Größe/Ä"', $row->moduleinfo_json);
        $info = json_decode($row->moduleinfo_json, true);
        $cm = $DB->get_record('course_modules', ['id' => $page->cmid], '*', MUST_EXIST);
        $this->assertSame((int) $page->cmid, $info['coursemodule']);
        $this->assertSame(2, $info['section']);
        $this->assertSame(1, $info['visible']);
        $this->assertSame(1, $info['visibleoncoursepage']);
        $this->assertSame('cm-1', $info['cmidnumber']);
        $this->assertSame(0, $info['groupmode']);
        $this->assertSame(0, $info['groupingid']);
        $this->assertSame((int) $this->course->id, $info['course']);
        $this->assertSame((int) $cm->module, $info['module']);
        $this->assertSame('page', $info['modulename']);
        $this->assertSame((int) $page->id, $info['instance']);
        $this->assertSame(1, $info['showdescription']);
        $this->assertArrayHasKey('downloadcontent', $info);
        $this->assertEquals($cm->downloadcontent, $info['downloadcontent']);
        $this->assertSame('de', $info['lang']);
        $this->assertSame(['alpha'], array_values($info['tags']));
        $this->assertSame((int) $this->course->id, (int) $row->courseid);
        $stored = json_decode($row->coursemodule_json, true);
        $this->assertEquals($cm->id, $stored['id']);
        $this->assertNull($row->arrangement_json);
    }

    public function test_availability_conditions_are_stored_only_when_restrictions_are_enabled(): void {
        global $DB;
        $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $conditions = json_encode(['op' => '&', 'c' => [['type' => 'date', 'd' => '>=', 't' => 5]], 'showc' => [true]]);
        $DB->set_field('course_modules', 'availability', $conditions, ['id' => $page->cmid]);

        set_config('enableavailability', 1);
        $with = version_writer::capture((int) $page->cmid, 2);
        set_config('enableavailability', 0);
        $without = version_writer::capture((int) $page->cmid, 2);
        set_config('enableavailability', 1);
        $DB->set_field('course_modules', 'availability', null, ['id' => $page->cmid]);
        $empty = version_writer::capture((int) $page->cmid, 2);

        $load = static fn(int $id): array => json_decode(
            $DB->get_field('local_coursepilot_cm_version', 'moduleinfo_json', ['id' => $id]),
            true
        );
        $this->assertSame($conditions, $load($with)['availabilityconditionsjson']);
        $this->assertArrayNotHasKey('availabilityconditionsjson', $load($without));
        $this->assertSame('', $load($empty)['availabilityconditionsjson']);
    }

    public function test_versions_are_numbered_per_activity_and_keep_their_origin(): void {
        global $DB;
        $this->setup_course();
        $first = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $second = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->delete_records('local_coursepilot_cm_version');

        version_writer::capture((int) $first->cmid, 5, 'moodle');
        version_writer::capture((int) $first->cmid, 6, 'cloned', (int) $second->cmid);
        version_writer::capture((int) $second->cmid, 7, 'moodle');

        $rows = $DB->get_records('local_coursepilot_cm_version', null, 'cmid, version');
        $summary = array_map(static fn($r) => [
            (int) $r->cmid,
            (int) $r->version,
            $r->source,
            $r->sourcecmid === null ? null : (int) $r->sourcecmid,
            (int) $r->userid,
        ], array_values($rows));
        $this->assertSame([
            [(int) $first->cmid, 1, 'moodle', null, 5],
            [(int) $first->cmid, 2, 'cloned', (int) $second->cmid, 6],
            [(int) $second->cmid, 1, 'moodle', null, 7],
        ], $summary);
        $this->assertFalse($DB->is_transaction_started());
    }

    public function test_an_activity_without_history_gets_a_discovered_version_first(): void {
        global $DB;
        $this->setup_course();
        $withhistory = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $bare = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $bare->cmid]);
        $this->assertGreaterThan(0, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $withhistory->cmid]));

        $latest = version_writer::capture_on_update((int) $bare->cmid, 3);

        $rows = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $bare->cmid], 'version'));
        $this->assertSame(['discovered', 'moodle'], array_column($rows, 'source'));
        $this->assertEquals($latest, $rows[1]->id);
        $this->assertFalse($DB->is_transaction_started());

        version_writer::capture_on_update((int) $bare->cmid, 3);
        $this->assertSame(3, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $bare->cmid]));
    }

    public function test_a_capture_that_fails_rolls_back_and_rethrows(): void {
        global $DB;
        $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $DB->delete_records('page', ['id' => $page->id]);
        $before = $DB->count_records('local_coursepilot_cm_version');

        $direct = $this->caught(fn() => version_writer::capture((int) $page->cmid, 2));
        $this->assertInstanceOf(\dml_exception::class, $direct);
        $this->assertFalse($DB->is_transaction_started());

        $DB->delete_records('local_coursepilot_cm_version', ['cmid' => $page->cmid]);
        $before = $DB->count_records('local_coursepilot_cm_version');
        $update = $this->caught(fn() => version_writer::capture_on_update((int) $page->cmid, 2));
        $this->assertInstanceOf(\dml_exception::class, $update);
        $this->assertFalse($DB->is_transaction_started());
        $this->assertSame($before, $DB->count_records('local_coursepilot_cm_version'));
    }

    public function test_expired_states_of_the_activity_go_with_the_new_capture(): void {
        global $DB;
        $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $old = $this->newest((int) $page->cmid);
        $DB->set_field('local_coursepilot_cm_version', 'timecreated', time() - 400 * DAYSECS, ['id' => $old->id]);

        version_writer::capture((int) $page->cmid, 2);

        $this->assertFalse($DB->record_exists('local_coursepilot_cm_version', ['id' => $old->id]));
    }

    public function test_only_allowed_file_areas_are_recorded_and_a_disallowed_one_does_not_stop_the_scan(): void {
        global $DB;
        $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $context = \context_module::instance($page->cmid);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'not-design.png',
        ], 'private');
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'intro', 'itemid' => 0,
            'filepath' => '/', 'filename' => 'design.png',
        ], 'design');

        $versionid = version_writer::capture((int) $page->cmid, 2);

        $linked = $DB->get_records_sql(
            'SELECT f.filename, f.filepath, f.filesize, f.mimetype, vf.gap
               FROM {local_coursepilot_cm_version_file} vf JOIN {local_coursepilot_cm_file} f ON f.id = vf.fileid
              WHERE vf.versionid = ?',
            [$versionid]
        );
        $this->assertSame(['design.png'], array_column($linked, 'filename'));
        $file = reset($linked);
        $this->assertSame('/', $file->filepath);
        $this->assertEquals(6, $file->filesize);
        $this->assertEquals(0, $file->gap);
        $this->assertSame('image/png', $file->mimetype);
    }

    public function test_a_replaced_file_gets_its_own_metadata_row(): void {
        global $DB;
        $this->setup_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $record = [
            'contextid' => \context_module::instance($page->cmid)->id, 'component' => 'mod_page', 'filearea' => 'intro',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'design.png',
        ];
        $fs = get_file_storage();
        $fs->create_file_from_string($record, 'first');
        version_writer::capture((int) $page->cmid, 2);
        $fs->get_file(...array_values($record))->delete();
        $fs->create_file_from_string($record, 'second version');
        version_writer::capture((int) $page->cmid, 2);

        $sizes = $DB->get_fieldset_select('local_coursepilot_cm_file', 'filesize', 'filename = ? ORDER BY id', ['design.png']);
        $this->assertEquals([5, 14], $sizes);
    }

    public function test_grade_fields_come_from_the_grade_items_of_this_activity_only(): void {
        global $DB;
        $this->setup_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'gradepass' => 40]);
        $other = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id, 'gradepass' => 70]);
        $item = \grade_item::fetch(
            ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $this->course->id]
        );

        $info = json_decode($DB->get_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            ['id' => version_writer::capture((int) $assign->cmid, 2)]
        ), true);

        $this->assertSame(format_float(40, $item->get_decimals()), $info['gradepass']);
        $this->assertEquals($item->categoryid, $info['gradecat']);
        $this->assertArrayNotHasKey('outcome_77', $info);
    }

    public function test_an_outcome_item_is_recorded_as_an_outcome_flag(): void {
        global $DB;
        $this->setup_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $DB->set_field('grade_items', 'outcomeid', 77, [
            'itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $this->course->id,
        ]);

        $info = json_decode($DB->get_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            ['id' => version_writer::capture((int) $assign->cmid, 2)]
        ), true);

        $this->assertSame(1, $info['outcome_77']);
        $this->assertArrayNotHasKey('gradepass', $info);
    }

    public function test_grade_items_of_one_number_in_different_categories_set_no_category(): void {
        global $DB;
        $this->setup_course();
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id]);
        $item = \grade_item::fetch(
            ['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id, 'courseid' => $this->course->id]
        );
        $twin = clone $item->get_record_data();
        unset($twin->id);
        $twin->categoryid = (int) $item->categoryid + 1000;
        $DB->insert_record('grade_items', $twin);

        $info = json_decode($DB->get_field(
            'local_coursepilot_cm_version',
            'moduleinfo_json',
            ['id' => version_writer::capture((int) $assign->cmid, 2)]
        ), true);

        $this->assertArrayNotHasKey('gradecat', $info);
    }
}
