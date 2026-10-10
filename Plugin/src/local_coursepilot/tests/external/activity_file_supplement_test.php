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
use local_coursepilot\activity_backup;
use local_coursepilot\material_files;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * File supplements through the registered public external function, with the real module.
 *
 * @package local_coursepilot
 * @copyright 2026 Coursepilot
 * @license https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(create_activity_from_xml::class)]
#[CoversClass(\local_coursepilot\activity_file_supplement::class)]
#[CoversClass(\local_coursepilot\activity_files\lightboxgallery::class)]
final class activity_file_supplement_test extends \advanced_testcase {
    public function test_three_material_images_and_captions_are_displayed_with_native_thumbnails(): void {
        global $DB, $CFG;
        [$course, $xml] = $this->gallery_fixture();
        $xml = file_get_contents(__DIR__ . '/../fixtures/lightboxgallery.xml');
        $entries = [];
        foreach ([[360, 120], [120, 360], [180, 180]] as $i => [$width, $height]) {
            $filename = 'image' . $i . '.png';
            $this->material_image($filename, $width, $height);
            $entries[] = ['path' => $filename, 'filearea' => 'gallery_images', 'caption' => 'Scene ' . $i];
        }

        $result = $this->call_create($course->id, $xml, $entries);
        $cm = get_fast_modinfo($course->id)->get_cm($result['cmid']);
        $this->assertTrue((bool) $cm->visible);
        $context = \context_module::instance($cm->id);
        $gallery = $DB->get_record('lightboxgallery', ['id' => $cm->instance], '*', MUST_EXIST);
        $fs = get_file_storage();
        $files = array_values($fs->get_area_files(
            $context->id,
            'mod_lightboxgallery',
            'gallery_images',
            0,
            'filename',
            false
        ));
        $this->assertCount(3, $files);
        // Inspect thumbnails before constructing display objects: creation must already have generated them.
        $thumbs = $fs->get_area_files($context->id, 'mod_lightboxgallery', 'gallery_thumbs', 0, 'filename', false);
        $this->assertCount(3, $thumbs);
        foreach ($thumbs as $thumb) {
            $info = $thumb->get_imageinfo();
            $this->assertSame(162, $info['width']);
            $this->assertSame(132, $info['height']);
        }
        require_once($CFG->dirroot . '/mod/lightboxgallery/imageclass.php');
        foreach ($files as $i => $file) {
            $image = new \lightboxgallery_image($file, $gallery, $cm);
            $this->assertSame('Scene ' . $i, $image->get_image_caption());
            $this->assertStringContainsString('Scene ' . $i, $image->get_image_display_html());
            $this->assertSame('image' . $i . '.png', $file->get_filename());
        }
        $this->assertSame(0, $DB->count_records('lightboxgallery_comments', ['gallery' => $cm->instance]));
        $this->assertSame(1, $DB->count_records('local_coursepilot_cm_version', ['cmid' => $cm->id]));
        $record = get_coursemodule_from_id('lightboxgallery', $cm->id, $course->id, false, MUST_EXIST);
        $this->assertStringContainsString('<description>Scene 0</description>', activity_backup::export($record));
    }

    /**
     * Provides gallery fixture.
     *
     * @return mixed[]
     */
    private function gallery_fixture(): array {
        if (!\core_plugin_manager::instance()->get_plugin_info('mod_lightboxgallery')) {
            $this->markTestSkipped('Requires real mod_lightboxgallery; run the isolated optional-plugin suite.');
        }
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $gallery = $this->getDataGenerator()->create_module(
            'lightboxgallery',
            ['course' => $course->id, 'captionfull' => 1, 'captionpos' => 0]
        );
        $cm = get_coursemodule_from_id('lightboxgallery', $gallery->cmid, $course->id, false, MUST_EXIST);
        return [$course, activity_backup::export($cm)];
    }

    public function test_missing_later_path_discards_only_the_new_activity_and_keeps_course_unchanged(): void {
        [$course, $xml] = $this->gallery_fixture();
        $this->material_image('present.png');
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $oldcmid = array_key_first(get_fast_modinfo($course->id)->cms);
        $before = $this->durable_state();
        $response = $this->call_response($course->id, $xml, [
            ['path' => 'present.png', 'filearea' => 'gallery_images', 'caption' => 'Present'],
            ['path' => 'missing.png', 'filearea' => 'gallery_images', 'caption' => 'Missing'],
        ], ['replaces_cmid' => $oldcmid]);
        $this->assertTrue($response['error']);
        $this->assertSame('materialfilenotfound', $response['exception']->errorcode);
        $this->assertStringContainsString('missing.png', $response['exception']->message);
        $this->assertEquals($before, $this->durable_state());
    }

    /**
     * Provides durable state.
     *
     * @return mixed[]
     */
    private function durable_state(): array {
        global $DB;
        $state = [];
        foreach (
            ['course_modules', 'course_sections', 'lightboxgallery', 'lightboxgallery_image_meta',
                'lightboxgallery_comments', 'files', 'local_coursepilot_cm_version', 'tool_recyclebin_course'] as $table
        ) {
            $state[$table] = $DB->get_records($table, null, 'id');
        }
        // Native restore/deletion may update section bookkeeping timestamps, like access logs.
        foreach ($state['course_sections'] as $section) {
            unset($section->timemodified);
        }
        return $state;
    }

    public function test_plugin_files_without_an_installed_module_do_not_open_the_kind_gate(): void {
        global $DB;
        [$course, $xml] = $this->gallery_fixture();
        $DB->delete_records('modules', ['name' => 'lightboxgallery']);
        $this->assertSame(
            \local_coursepilot\catalog\activity_kind::EXCLUDED,
            \local_coursepilot\catalog\registry::kind('lightboxgallery')->kind
        );
        $before = $this->durable_state();
        $response = $this->call_response($course->id, $xml, []);
        $this->assertTrue($response['error']);
        $this->assertSame('kindexcludednobackup', $response['exception']->errorcode);
        $this->assertEquals($before, $this->durable_state());
    }

    public function test_invalid_area_duplicate_filename_and_non_image_leave_no_activity(): void {
        [$course, $xml] = $this->gallery_fixture();
        $this->material_image('image.png');
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'invalid.png'),
            'This is not an image'
        );
        $before = $this->durable_state();
        foreach (
            [
            'activityfileinvalidarea' => [['path' => 'image.png', 'filearea' => 'gallery_thumbs']],
            'activityfileduplicate' => [
                ['path' => 'image.png', 'filearea' => 'gallery_images'],
                ['path' => 'other/image.png', 'filearea' => 'gallery_images'],
            ],
            'activityfileinvalidimage' => [['path' => 'invalid.png', 'filearea' => 'gallery_images']],
            ] as $code => $entries
        ) {
            $response = $this->call_response($course->id, $xml, $entries);
            $this->assertTrue($response['error']);
            $this->assertSame($code, $response['exception']->errorcode);
            $this->assertEquals($before, $this->durable_state(), $code);
        }
    }

    public function test_native_image_permission_is_required_and_failure_keeps_the_course(): void {
        global $DB;
        [$course, $xml] = $this->gallery_fixture();
        $this->material_image('image.png');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('mod/lightboxgallery:addimage', CAP_PROHIBIT, $roleid, \context_course::instance($course->id));
        $before = $this->durable_state();
        $response = $this->call_response(
            $course->id,
            $xml,
            [['path' => 'image.png', 'filearea' => 'gallery_images']]
        );
        $this->assertTrue($response['error']);
        $this->assertSame('nopermissions', $response['exception']->errorcode);
        $this->assertEquals($before, $this->durable_state());
    }

    public function test_rejected_file_input_is_refused_before_any_restore_happens(): void {
        global $DB;
        [$course, $xml] = $this->gallery_fixture();
        $this->material_image('image.png');
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $cases = [
            'activityfileinvalidarea' => [[['path' => 'image.png', 'filearea' => 'gallery_thumbs']], false],
            'activityfileduplicate' => [[
                ['path' => 'image.png', 'filearea' => 'gallery_images'],
                ['path' => 'other/image.png', 'filearea' => 'gallery_images'],
            ], false],
            'nopermissions' => [[['path' => 'image.png', 'filearea' => 'gallery_images']], true],
        ];
        foreach ($cases as $code => [$entries, $prohibit]) {
            if ($prohibit) {
                assign_capability(
                    'mod/lightboxgallery:addimage',
                    CAP_PROHIBIT,
                    $roleid,
                    \context_course::instance($course->id)
                );
            }
            $sink = $this->redirectEvents();
            $response = $this->call_response($course->id, $xml, $entries);
            $events = array_map(static fn($event) => $event::class, $sink->get_events());
            $sink->close();
            $this->assertTrue($response['error']);
            $this->assertSame($code, $response['exception']->errorcode);
            $this->assertSame([], $events, $code . ' must not restore before validation');
        }
    }

    public function test_hidden_workbench_image_and_empty_file_list_keep_existing_creation_options(): void {
        global $DB;
        [$course, $xml] = $this->gallery_fixture();
        $this->material_image('image.png');
        $result = $this->call_response(
            $course->id,
            $xml,
            [['path' => 'image.png', 'filearea' => 'gallery_images', 'location' => 'workbench']],
            ['hidden' => true]
        );
        $this->assertFalse($result['error'], json_encode($result));
        $cm = get_fast_modinfo($course->id)->get_cm($result['data']['cmid']);
        $this->assertFalse((bool) $cm->visible);
        $this->assertSame('', $DB->get_field('lightboxgallery_image_meta', 'description', ['gallery' => $cm->instance]));
        $empty = $this->call_create($course->id, $xml, []);
        $this->assertTrue((bool) get_fast_modinfo($course->id)->get_cm($empty['cmid'])->visible);
    }

    /**
     * Provides material image.
     *
     * @param string $filename The filename.
     * @param int $width The width.
     * @param int $height The height.
     */
    private function material_image(string $filename, int $width = 360, int $height = 120): void {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        imagedestroy($image);
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', $filename),
            $content
        );
    }

    /**
     * Calls create.
     *
     * @param int $courseid The courseid.
     * @param string $xml The xml.
     * @param mixed[] $files The files.
     * @return mixed[]
     */
    private function call_create(int $courseid, string $xml, array $files): array {
        $response = $this->call_response($courseid, $xml, $files);
        $this->assertFalse($response['error'], json_encode($response));
        return $response['data'];
    }

    /**
     * Calls response.
     *
     * @param int $courseid The courseid.
     * @param string $xml The xml.
     * @param mixed[] $files The files.
     * @param mixed[] $extra The extra.
     * @return mixed[]
     */
    private function call_response(int $courseid, string $xml, array $files, array $extra = []): array {
        $_POST['sesskey'] = sesskey();
        $response = external_api::call_external_function('local_coursepilot_create_activity_from_xml', [
            'courseid' => $courseid, 'modname' => 'lightboxgallery', 'section' => 1,
            'activity_xml' => $xml, 'files' => $files,
        ] + $extra);
        unset($_POST['sesskey']);
        return $response;
    }
}
