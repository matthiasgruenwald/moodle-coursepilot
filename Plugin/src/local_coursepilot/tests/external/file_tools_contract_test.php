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
use local_coursepilot\activity_file_trash;
use local_coursepilot\material_area;
use local_coursepilot\material_files;
use local_coursepilot\tests\caught_throwable;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Exact reports, guards and storage of the compose tool, the glossary tool and the activity file trash.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(compose_material_file::class)]
#[CoversClass(add_glossary_entries::class)]
#[CoversClass(activity_file_trash::class)]
final class file_tools_contract_test extends \advanced_testcase {
    use caught_throwable;

    /**
     * PNG bytes of a plain image.
     *
     * @param int $width
     * @param int $height
     * @return string
     */
    private function png(int $width, int $height): string {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        return ob_get_clean();
    }

    /**
     * Stores a file in the material store.
     *
     * @param string $filename
     * @param string $content
     */
    private function store(string $filename, string $content): void {
        get_file_storage()->create_file_from_string([
            'contextid' => material_files::own_context()->id,
            'component' => material_files::COMPONENT,
            'filearea' => material_files::FILEAREA,
            'itemid' => material_files::ITEMID,
            'filepath' => '/coursepilot-material/',
            'filename' => $filename,
        ], $content);
    }

    /**
     * Plain user with one stored image.
     */
    private function user_with_image(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->store('part.png', $this->png(20, 10));
    }

    public function test_the_composition_report_is_exact_and_an_uppercase_png_target_is_fine(): void {
        $this->user_with_image();
        $part = ['sourcepath' => 'part.png', 'location' => 'store', 'expected_contenthash' => ''];

        $result = compose_material_file::execute([$part], 'vertical', 'OUT.PNG');

        $this->assertSame(
            ['path', 'sources', 'created', 'width', 'height', 'size', 'contenthash', 'message'],
            array_keys($result)
        );
        $this->assertSame('OUT.PNG', $result['path']);
        $this->assertTrue($result['created']);
        $this->assertSame(
            get_string('materialcompositionwritten', 'local_coursepilot', (object) [
                'path' => 'OUT.PNG',
                'width' => $result['width'],
                'height' => $result['height'],
            ]),
            $result['message']
        );
        $stored = material_area::read_for_location('store', 'OUT.PNG');
        $this->assertSame(sha1($stored['content']), $result['contenthash']);
        $this->assertSame(strlen($stored['content']), $result['size']);
    }

    public function test_a_nearly_full_quota_adds_its_warning_to_the_composition_message(): void {
        global $CFG;
        $this->user_with_image();
        $part = ['sourcepath' => 'part.png', 'location' => 'store', 'expected_contenthash' => ''];
        $first = compose_material_file::execute([$part], 'vertical', 'probe.png');
        $CFG->userquota = (int) \file_get_user_used_space() + 10;

        $second = compose_material_file::execute([$part], 'vertical', 'probe.png');

        $this->assertSame(
            get_string('materialcompositionwritten', 'local_coursepilot', (object) [
                'path' => 'probe.png',
                'width' => $first['width'],
                'height' => $first['height'],
            ]) . ' Note: only 0.0 MB of storage left.',
            $second['message']
        );
        $this->assertFalse($second['created']);
    }

    public function test_context_and_file_right_are_checked_before_the_request_is_judged_and_the_target_must_be_png(): void {
        global $DB, $USER;
        $this->user_with_image();
        $part = ['sourcepath' => 'part.png', 'location' => 'store', 'expected_contenthash' => ''];
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => compose_material_file::execute([], 'vertical', 'x.png'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/user:manageownfiles', CAP_PROHIBIT, $roleid, \context_user::instance($USER->id)->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $norights = $this->caught(fn() => compose_material_file::execute([], 'vertical', 'x.png'));
        $this->assertInstanceOf(\required_capability_exception::class, $norights);
        unassign_capability('moodle/user:manageownfiles', $roleid, \context_user::instance($USER->id)->id);
        accesslib_clear_all_caches_for_unit_testing();

        $empty = $this->caught(fn() => compose_material_file::execute([], 'vertical', 'x.png'));
        $this->assertInstanceOf(\moodle_exception::class, $empty);
        $this->assertSame('materialcompositionemptyparts', $empty->errorcode);
        $arrangement = $this->caught(fn() => compose_material_file::execute([$part], 'diagonal', 'x.png'));
        $this->assertInstanceOf(\moodle_exception::class, $arrangement);
        $this->assertSame('materialcompositioninvalidarrangement', $arrangement->errorcode);
        $format = $this->caught(fn() => compose_material_file::execute([$part], 'vertical', 'x.JPG'));
        $this->assertInstanceOf(\moodle_exception::class, $format);
        $this->assertSame('materialcompositionoutputunsupported', $format->errorcode);
        $this->assertSame('JPG', $format->a);
    }

    public function test_the_trash_copy_keeps_context_name_content_and_a_path_of_cmid_and_hash(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $original = get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'pic.png',
        ], 'bytes');

        activity_file_trash::trash($original, 42);
        activity_file_trash::trash($original, 42);

        $copies = get_file_storage()->get_area_files(
            $context->id,
            activity_file_trash::COMPONENT,
            activity_file_trash::FILEAREA,
            42,
            'filename',
            false
        );
        $this->assertCount(1, $copies);
        $copy = reset($copies);
        $this->assertSame('/42/' . $original->get_pathnamehash() . '/', $copy->get_filepath());
        $this->assertSame('pic.png', $copy->get_filename());
        $this->assertSame('bytes', $copy->get_content());
        $this->assertSame((int) $context->id, (int) $copy->get_contextid());
    }

    public function test_the_newest_matching_trash_copy_is_found_by_name_content_and_activity(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $make = static fn(string $name, string $content, string $path) => get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_page', 'filearea' => 'content',
            'itemid' => 0, 'filepath' => $path, 'filename' => $name,
        ], $content);
        $older = $make('pic.png', 'same', '/a/');
        $newer = $make('pic.png', 'same', '/b/');
        $otherfile = $make('other.png', 'same', '/c/');
        foreach ([$older, $newer, $otherfile] as $file) {
            activity_file_trash::trash($file, 7);
        }
        activity_file_trash::trash($newer, 8);
        $DB->set_field(
            'files',
            'timecreated',
            100,
            ['component' => activity_file_trash::COMPONENT, 'filepath' => '/7/' . $older->get_pathnamehash() . '/']
        );
        $DB->set_field(
            'files',
            'timecreated',
            200,
            ['component' => activity_file_trash::COMPONENT, 'filepath' => '/7/' . $newer->get_pathnamehash() . '/']
        );
        $hash = $newer->get_contenthash();

        $found = activity_file_trash::find_for_restore($context->id, 7, 'pic.png', $hash);

        $this->assertSame('/7/' . $newer->get_pathnamehash() . '/', $found->get_filepath());
        $this->assertNull(activity_file_trash::find_for_restore($context->id, 7, 'pic.png', sha1('different')));
        $this->assertNull(activity_file_trash::find_for_restore($context->id, 7, 'missing.png', $hash));
        $this->assertNull(activity_file_trash::find_for_restore($context->id, 9, 'pic.png', $hash));
        $this->assertNull(activity_file_trash::find_for_restore($context->id + 1, 7, 'pic.png', $hash));
    }

    /**
     * Teacher, glossary and two glossaries (the decoy first).
     *
     * @return \stdClass The target glossary module.
     */
    private function glossary(): \stdClass {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'name' => 'Decoy']);
        return $this->getDataGenerator()->create_module('glossary', ['course' => $course->id, 'name' => 'Target']);
    }

    public function test_the_glossary_report_has_integer_ids_the_indexes_and_the_gap_notice(): void {
        $glossary = $this->glossary();

        $result = add_glossary_entries::execute((int) $glossary->cmid, [
            ['concept' => 'Atom', 'definition' => 'Small'],
            ['concept' => 'Ion', 'definition' => 'Charged'],
        ]);

        $this->assertSame(['cmid', 'entries', 'gap_notice'], array_keys($result));
        $this->assertSame((int) $glossary->cmid, $result['cmid']);
        $this->assertSame([0, 1], array_column($result['entries'], 'index'));
        $this->assertSame(get_string('historygapnotice', 'local_coursepilot'), $result['gap_notice']);
        global $DB;
        $this->assertSame(2, $DB->count_records('glossary_entries', ['glossaryid' => $glossary->id]));
        $this->assertSame(0, $DB->count_records('glossary_entries', ['glossaryid' => $glossary->id - 1]));
    }

    public function test_the_glossary_tool_checks_context_and_both_rights_before_looking_at_entries(): void {
        global $DB;
        $glossary = $this->glossary();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => add_glossary_entries::execute((int) $glossary->cmid, []));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'mod/glossary:write'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(
                fn() => add_glossary_entries::execute((int) $glossary->cmid, [['concept' => 'X', 'definition' => 'Y']])
            );
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }
}
