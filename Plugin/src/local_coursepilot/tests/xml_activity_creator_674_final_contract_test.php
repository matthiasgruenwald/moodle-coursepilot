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

use local_coursepilot\external\export_default_activity;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Creation footprint, chain walking and Lightboxgallery XML rule of xml_activity_creator (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(xml_activity_creator::class)]
final class xml_activity_creator_674_final_contract_test extends \advanced_testcase {
    /**
     * A freshly created activity keeps exactly one history state, written from the XML.
     */
    public function test_created_activity_keeps_exactly_one_xml_state(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];

        $cmid = xml_activity_creator::create($course->id, 'book', 2, $xml)['cmid'];

        $states = $DB->get_records('local_coursepilot_cm_version', ['cmid' => $cmid]);
        $this->assertCount(1, $states);
        $this->assertSame(\local_coursepilot\history\version_writer::SOURCE_FROM_XML, reset($states)->source);
    }

    /**
     * History rows that observers wrote for the new activity during creation are dropped before the one XML state.
     */
    public function test_created_activity_drops_states_written_on_the_way(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $xml = export_default_activity::execute($course->id, 'book')['xml'];
        // Pre-seed states for the ids the next course module can get, as an observer would have written them.
        $next = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id])->cmid + 1;
        for ($candidate = $next; $candidate < $next + 5; $candidate++) {
            foreach ([1, 2] as $version) {
                $DB->insert_record('local_coursepilot_cm_version', (object) [
                    'cmid' => $candidate, 'courseid' => $course->id, 'version' => $version, 'source' => 'moodle',
                    'userid' => $teacher->id, 'moduleinfo_json' => '{}', 'coursemodule_json' => '{}',
                    'timecreated' => time(),
                ]);
            }
        }

        $cmid = xml_activity_creator::create($course->id, 'book', 2, $xml)['cmid'];

        $this->assertGreaterThanOrEqual($next, $cmid);
        $this->assertLessThan($next + 5, $cmid);
        $states = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $cmid]));
        $this->assertCount(1, $states);
        $this->assertSame(1, (int) $states[0]->version);
        $this->assertSame(\local_coursepilot\history\version_writer::SOURCE_FROM_XML, $states[0]->source);
    }

    /**
     * Prepares a course with a teacher and returns the course and a book XML.
     *
     * @return array{0: \stdClass, 1: string} Course and activity XML.
     */
    private function course_with_book_xml(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, export_default_activity::execute($course->id, 'book')['xml']];
    }

    /**
     * Writes a superseded marker state as the hand-over from one activity to the next leaves it.
     *
     * @param int $courseid Course.
     * @param int $oldcmid Replaced activity.
     * @param int $newcmid Replacing activity.
     */
    private function link(int $courseid, int $oldcmid, int $newcmid): void {
        global $DB, $USER;
        $DB->insert_record('local_coursepilot_cm_version', (object) [
            'cmid' => $oldcmid, 'courseid' => $courseid, 'version' => 99, 'source' => 'superseded',
            'sourcecmid' => $newcmid, 'userid' => $USER->id, 'moduleinfo_json' => '{}',
            'coursemodule_json' => '{}', 'timecreated' => time(),
        ]);
    }

    /**
     * An activity without any hand-over link has no successor and only itself as hidden predecessor.
     */
    public function test_an_unlinked_activity_has_no_successor_and_no_hidden_predecessors(): void {
        [$course, $xml] = $this->course_with_book_xml();
        $a = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $b = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $c = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $this->link($course->id, $b, $c);

        $preview = xml_activity_creator::preview_supersede($course->id, 'book', $xml, $a);

        $this->assertSame(0, $preview['successor_cmid']);
        $this->assertSame(1, $preview['hidden_predecessors']);
    }

    /**
     * The newest successor is the end of the chain, found even when the chain leads back into itself.
     */
    public function test_the_successor_is_the_chain_end_and_a_cycle_does_not_loop(): void {
        [$course, $xml] = $this->course_with_book_xml();
        $a = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $b = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $c = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $this->link($course->id, $a, $b);
        $this->link($course->id, $b, $c);
        $this->link($course->id, $c, $b);

        $preview = xml_activity_creator::preview_supersede($course->id, 'book', $xml, $a);

        $this->assertSame($c, $preview['successor_cmid']);
    }

    /**
     * A single link yields exactly that successor.
     */
    public function test_a_single_link_names_its_target_as_successor(): void {
        [$course, $xml] = $this->course_with_book_xml();
        $a = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $b = xml_activity_creator::create($course->id, 'book', 1, $xml)['cmid'];
        $this->link($course->id, $a, $b);

        $preview = xml_activity_creator::preview_supersede($course->id, 'book', $xml, $a);

        $this->assertSame($b, $preview['successor_cmid']);
    }

    /**
     * A book XML passes the XML check and is then judged on the activity to replace.
     */
    public function test_a_book_xml_without_timemodified_is_not_refused_by_the_xml_check(): void {
        [$course] = $this->course_with_book_xml();
        $xml = '<activity modulename="book"><book><name>x</name></book></activity>';

        try {
            xml_activity_creator::preview_supersede($course->id, 'book', $xml, 987654);
            $this->fail('A missing activity to replace must be refused.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('replaces_cmid 987654 is not an activity of type "book"', $e->debuginfo);
        }
    }

    /**
     * The Lightboxgallery XML check demands timemodified; a complete XML reaches the next check.
     */
    public function test_lightboxgallery_xml_with_timemodified_passes_the_xml_check(): void {
        if (!\core_component::get_component_directory('mod_lightboxgallery')) {
            $this->markTestSkipped('mod_lightboxgallery is not installed in this environment.');
        }
        [$course] = $this->course_with_book_xml();
        $without = '<activity modulename="lightboxgallery"><lightboxgallery><name>x</name></lightboxgallery></activity>';
        $with = '<activity modulename="lightboxgallery"><lightboxgallery><timemodified>1</timemodified>'
            . '</lightboxgallery></activity>';

        try {
            xml_activity_creator::preview_supersede($course->id, 'lightboxgallery', $without, 987654);
            $this->fail('timemodified must be required');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('requires <timemodified> in <lightboxgallery>', $e->debuginfo);
        }
        try {
            xml_activity_creator::preview_supersede($course->id, 'lightboxgallery', $with, 987654);
            $this->fail('A missing activity to replace must be refused.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('replaces_cmid 987654 is not an activity', $e->debuginfo);
        }
    }

    /**
     * Files declared for a Lightboxgallery are attached to the created activity.
     */
    public function test_create_attaches_declared_files_to_a_lightboxgallery(): void {
        if (!\core_plugin_manager::instance()->get_plugin_info('mod_lightboxgallery')) {
            $this->markTestSkipped('Requires real mod_lightboxgallery; run the isolated optional-plugin suite.');
        }
        [$course] = $this->course_with_book_xml();
        $gallery = $this->getDataGenerator()->create_module('lightboxgallery', ['course' => $course->id]);
        $cm = get_coursemodule_from_id('lightboxgallery', $gallery->cmid, $course->id, false, MUST_EXIST);
        $xml = activity_backup::export($cm);
        $image = imagecreatetruecolor(120, 120);
        ob_start();
        imagepng($image);
        $content = ob_get_clean();
        imagedestroy($image);
        material_files::replace(
            null,
            material_files::filerecord(material_files::own_context()->id, '/coursepilot-material/', 'pic.png'),
            $content
        );

        $cmid = xml_activity_creator::create(
            $course->id,
            'lightboxgallery',
            1,
            $xml,
            false,
            null,
            [['path' => 'pic.png', 'filearea' => 'gallery_images', 'caption' => 'Pic']]
        )['cmid'];

        $stored = get_file_storage()->get_area_files(
            \context_module::instance($cmid)->id,
            'mod_lightboxgallery',
            'gallery_images',
            0,
            'filename',
            false
        );
        $this->assertSame(['pic.png'], array_values(array_map(static fn($file) => $file->get_filename(), $stored)));
    }
}
