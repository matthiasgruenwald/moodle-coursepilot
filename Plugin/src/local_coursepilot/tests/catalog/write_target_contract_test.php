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


namespace local_coursepilot\catalog;

use local_coursepilot\material_files;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact rule messages, derived values and native-sequence details of write_target (Spec 0028 F11, #646, #647).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(write_target::class)]
final class write_target_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * Calls a private static method of write_target.
     *
     * @param string $method
     * @param mixed ...$arguments
     * @return mixed
     */
    private function call(string $method, mixed ...$arguments): mixed {
        return (new \ReflectionMethod(write_target::class, $method))->invoke(null, ...$arguments);
    }

    /**
     * Calls a private static method whose last parameter is taken by reference.
     *
     * @param string $method
     * @param mixed[] $leading Arguments before the by-reference one.
     * @param mixed[] $fields The by-reference array, changed in place.
     */
    private function call_by_reference(string $method, array $leading, array &$fields): void {
        (new \ReflectionMethod(write_target::class, $method))->invokeArgs(null, [...$leading, &$fields]);
    }

    public function test_defaults_are_the_target_values_no_change_names(): void {
        $target = write_target::update(
            choice::class,
            ['name' => 'New'],
            ['name' => 'Old', 'timeopen' => 0, 'showresults' => 1],
            []
        );

        $this->assertSame(['timeopen' => 0, 'showresults' => 1], $target->defaults());
        $this->assertSame(['name' => 'New', 'timeopen' => 0, 'showresults' => 1], $target->state);
    }

    public function test_a_dependent_list_must_match_its_reference_list_with_an_exact_message(): void {
        $e = $this->caught(fn() => write_target::update(
            choice::class,
            ['limit' => [1]],
            ['option' => ['a', 'b'], 'limit' => [1, 2]],
            []
        ));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('combinationruleviolation', $e->errorcode);
        $this->assertSame(['modname' => 'choice', 'message' => '"limit" must have as many entries as "option".'], $e->a);
    }

    public function test_the_list_length_rule_judges_only_two_lists_that_are_named_changes_of_a_list_pair(): void {
        $equal = write_target::update(choice::class, ['limit' => [1, 2]], ['option' => ['a', 'b']], []);
        $referencescalar = write_target::update(choice::class, ['limit' => [1, 2]], ['option' => 'ab'], []);
        $fieldscalar = write_target::update(choice::class, ['limit' => 3], ['option' => ['a', 'b']], []);
        $unnamed = write_target::update(choice::class, ['name' => 'x'], ['option' => ['a', 'b'], 'limit' => [1]], []);

        foreach ([$equal, $referencescalar, $fieldscalar, $unnamed] as $target) {
            $this->assertInstanceOf(write_target::class, $target);
        }
    }

    public function test_an_unset_date_counts_as_no_date_for_the_rule(): void {
        // Only one side of the pair exists in the state: nothing to compare, nothing to refuse.
        $fieldonly = write_target::update(assign::class, ['duedate' => 1], [], []);
        $referenceonly = write_target::update(assign::class, ['allowsubmissionsfromdate' => 5], [], []);
        $choicefield = write_target::update(choice::class, ['timeclose' => 1], [], ['timeclose']);
        $choicereference = write_target::update(choice::class, ['timeopen' => 5], [], []);

        foreach ([$fieldonly, $referenceonly, $choicefield, $choicereference] as $target) {
            $this->assertInstanceOf(write_target::class, $target);
        }
    }

    public function test_the_missing_required_fields_are_reported_together_in_catalog_order(): void {
        $e = $this->caught(fn() => write_target::create(choice::class, [], []));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('requiredfieldwithoutdefault', $e->errorcode);
        $this->assertSame('choice', $e->a['modname']);
        $this->assertSame('"name", "intro", "option"', $e->a['field']);
    }

    public function test_admin_defaults_become_zero_or_one_whatever_the_configured_text_is(): void {
        $this->resetAfterTest();
        set_config('default', '7', 'assignsubmission_file');
        set_config('default', '0', 'assignsubmission_onlinetext');
        unset_config('default', 'assignfeedback_comments');

        $target = write_target::create(assign::class, ['name' => 'A', 'intro' => 'x'], []);

        $this->assertSame(1, $target->state['assignsubmission_file_enabled']);
        $this->assertSame(0, $target->state['assignsubmission_onlinetext_enabled']);
        $this->assertSame(0, $target->state['assignfeedback_comments_enabled']);
    }

    public function test_a_single_limit_is_repeated_for_every_option_as_integers(): void {
        $fields = ['option' => ['a', 'b', 'c'], 'limit' => '5'];
        $this->call_by_reference('expand_scalar_to_repeated', [['limit' => 'option']], $fields);
        $this->assertSame([5, 5, 5], $fields['limit']);

        $list = ['option' => ['a', 'b'], 'limit' => [1, 2]];
        $this->call_by_reference('expand_scalar_to_repeated', [['limit' => 'option']], $list);
        $this->assertSame([1, 2], $list['limit']);

        $noreference = ['option' => 'a', 'limit' => '5'];
        $this->call_by_reference('expand_scalar_to_repeated', [['limit' => 'option']], $noreference);
        $this->assertSame('5', $noreference['limit']);

        $empty = ['option' => ['a'], 'limit' => 0];
        $this->call_by_reference('expand_scalar_to_repeated', [['limit' => 'option']], $empty);
        $this->assertSame([0], $empty['limit']);
    }

    public function test_editor_columns_are_derived_only_where_the_flat_columns_are_absent(): void {
        $derived = ['page' => ['text' => 5, 'format' => '2']];
        $this->call_by_reference('derive_editor_columns', [['page' => ['content', 'contentformat']]], $derived);
        $this->assertSame('5', $derived['content']);
        $this->assertSame(2, $derived['contentformat']);

        $given = ['page' => ['text' => 'T', 'format' => 2], 'content' => 'own', 'contentformat' => 4];
        $this->call_by_reference('derive_editor_columns', [['page' => ['content', 'contentformat']]], $given);
        $this->assertSame('own', $given['content']);
        $this->assertSame(4, $given['contentformat']);

        $bare = ['page' => []];
        $this->call_by_reference('derive_editor_columns', [['page' => ['content', 'contentformat']]], $bare);
        $this->assertSame('', $bare['content']);
        $this->assertSame((int) FORMAT_HTML, $bare['contentformat']);

        $plain = ['page' => 'text'];
        $this->call_by_reference('derive_editor_columns', [['page' => ['content', 'contentformat']]], $plain);
        $this->assertSame(['page' => 'text'], $plain);
    }

    public function test_embed_images_must_be_a_list_of_allowed_image_paths(): void {
        $this->resetAfterTest();
        $allowed = implode(', ', material_files::allowed_embed_image_extensions());

        $notlist = $this->caught(fn() => $this->call('assert_embed_images', 'introimages', ['a' => 'x.png']));
        $this->assertInstanceOf(\moodle_exception::class, $notlist);
        $this->assertSame('invalidmaterialreferencelist', $notlist->errorcode);
        $this->assertSame('introimages', $notlist->a);

        $badext = $this->caught(fn() => $this->call('assert_embed_images', 'introimages', ['ok.png', 'run.exe']));
        $this->assertInstanceOf(\moodle_exception::class, $badext);
        $this->assertSame('materialfiledisallowedtype', $badext->errorcode);
        $this->assertSame('run.exe', $badext->a->filename);
        $this->assertSame($allowed, $badext->a->allowed);

        $nonstring = $this->caught(fn() => $this->call('assert_embed_images', 'introimages', [5]));
        $this->assertInstanceOf(\moodle_exception::class, $nonstring);
        $this->assertSame('', $nonstring->a->filename);
        $this->assertSame($allowed, $nonstring->a->allowed);

        $this->assertNull($this->call('assert_embed_images', 'introimages', ['a.png', 'b.JPG']));
    }

    /**
     * Creates an assign activity and its course.
     *
     * @param string $name
     * @param string $idnumber
     * @param \stdClass|null $course
     * @return \stdClass Module record with cmid.
     */
    private function assign_activity(string $name, string $idnumber, ?\stdClass $course = null): \stdClass {
        $course ??= $this->getDataGenerator()->create_course();
        /** @var \mod_assign_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        return $generator->create_instance(['course' => $course->id, 'name' => $name, 'idnumber' => $idnumber]);
    }

    public function test_a_missing_assignment_grade_item_is_seeded_for_the_right_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $first = $this->assign_activity('First', 'ID-1', $course);
        $second = $this->assign_activity('Second', 'ID-2', $course);
        $DB->delete_records('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $second->id]);

        $this->call('ensure_assign_grade_item', 'assign', (int) $second->cmid);

        $item = $DB->get_record('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $second->id], '*', MUST_EXIST);
        $this->assertSame('Second', $item->itemname);
        $this->assertSame('ID-2', $item->idnumber);
        $this->assertSame(1, $DB->count_records('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $first->id]));
        $this->assertSame(1, $DB->count_records('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $second->id]));
    }

    public function test_an_existing_single_grade_item_and_other_activity_types_are_left_alone(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->assign_activity('Only', 'ID-1');
        $before = $DB->get_records('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $activity->id]);

        $this->call('ensure_assign_grade_item', 'assign', (int) $activity->cmid);
        $this->call('ensure_assign_grade_item', 'page', 987654);

        $this->assertEquals(
            $before,
            $DB->get_records('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $activity->id])
        );
    }

    public function test_two_grade_items_for_one_assignment_are_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $activity = $this->assign_activity('Twice', 'ID-1');
        $item = $DB->get_record('grade_items', ['itemmodule' => 'assign', 'iteminstance' => $activity->id], '*', MUST_EXIST);
        unset($item->id);
        $item->itemnumber = 0;
        $DB->insert_record('grade_items', $item);

        $e = $this->caught(fn() => $this->call('ensure_assign_grade_item', 'assign', (int) $activity->cmid));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('assignmentgradeitemfailed', $e->errorcode);
    }

    /**
     * Logs in an editing teacher of a new course.
     *
     * @return \stdClass The course.
     */
    private function teacher_course(): \stdClass {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Stores a file in the material store.
     *
     * @param string $path
     * @param string $content
     */
    private function material(string $path, string $content): void {
        $record = material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', $path);
        material_files::replace(null, $record, $content);
    }

    public function test_the_intro_text_and_format_survive_an_embedded_image_on_create(): void {
        global $DB;
        $course = $this->teacher_course();
        $image = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($image);
        $this->material('pic.png', ob_get_clean());

        $result = write_target::create_activity(
            assign::class,
            $course,
            1,
            [
                'name' => 'With image',
                'intro' => '<p>Hello</p> @@PLUGINFILE@@/pic.png',
                'introformat' => 4,
                'introimages' => ['pic.png'],
            ],
            material_files::LOCATION_STORE,
            []
        );

        $cm = get_coursemodule_from_id('assign', $result['cmid'], 0, false, MUST_EXIST);
        $assignment = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->assertSame('<p>Hello</p> @@PLUGINFILE@@/pic.png', $assignment->intro);
        $this->assertSame('4', (string) $assignment->introformat);
    }

    public function test_creation_never_trashes_files_that_only_share_the_course_context(): void {
        $course = $this->teacher_course();
        $this->material('wurzel.pdf', 'content');
        $coursecontext = \context_course::instance($course->id);
        get_file_storage()->create_file_from_string([
            'contextid' => $coursecontext->id,
            'component' => 'mod_folder',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'wurzel.pdf',
        ], 'planted');

        write_target::create_activity(
            folder::class,
            $course,
            1,
            ['name' => 'Folder', 'files' => ['wurzel.pdf']],
            material_files::LOCATION_STORE,
            []
        );

        $trashed = get_file_storage()->get_area_files(
            $coursecontext->id,
            \local_coursepilot\activity_file_trash::COMPONENT,
            \local_coursepilot\activity_file_trash::FILEAREA,
            false,
            'filename',
            false
        );
        $this->assertCount(0, $trashed);
    }

    public function test_replacing_a_file_trashes_the_old_one_by_the_name_of_the_new_path(): void {
        $course = $this->teacher_course();
        $this->material('x.pdf', 'old');
        $created = write_target::create_activity(
            folder::class,
            $course,
            1,
            ['name' => 'Folder', 'files' => ['x.pdf']],
            material_files::LOCATION_STORE,
            []
        );
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/dir/', 'x.pdf'),
            'new'
        );
        $cm = get_coursemodule_from_id('folder', $created['cmid'], 0, false, MUST_EXIST);

        $this->call(
            'resolve_draft',
            \context_module::instance($cm->id),
            ['component' => 'mod_folder', 'filearea' => 'content'],
            ['dir/x.pdf'],
            material_files::LOCATION_STORE,
            true
        );

        $trashed = get_file_storage()->get_area_files(
            \context_module::instance($cm->id)->id,
            \local_coursepilot\activity_file_trash::COMPONENT,
            \local_coursepilot\activity_file_trash::FILEAREA,
            (int) $cm->id,
            'filename',
            false
        );
        $this->assertCount(1, $trashed);
        $this->assertSame('x.pdf', reset($trashed)->get_filename());
    }

    public function test_a_page_text_given_as_plain_string_is_written_as_the_editor_content(): void {
        global $DB;
        $course = $this->teacher_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'content' => '<p>Old</p>']);
        $cm = get_coursemodule_from_id('page', $page->cmid, 0, false, MUST_EXIST);
        $current = (new \ReflectionMethod(module_settings::class, 'read_settings'))->invoke(null, (int) $cm->id);

        $patch = write_target::update_activity(
            page::class,
            $cm,
            $course,
            ['page' => '<p>New</p>'],
            $current,
            material_files::LOCATION_STORE,
            []
        );

        $this->assertSame(['page' => ['text' => '<p>New</p>', 'format' => FORMAT_HTML, 'itemid' => 0]], $patch);
        $this->assertSame('<p>New</p>', $DB->get_field('page', 'content', ['id' => $cm->instance]));
    }
}
