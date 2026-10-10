<?php
// This file is part of Coursepilot, a plugin for Moodle - http://moodle.org/
//
// Coursepilot is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Coursepilot is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Coursepilot.  If not, see <https://www.gnu.org/licenses/>.

namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Template data preparation for history.php (#552, Spec 0023 part 5).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(history_page::class)]
final class history_page_test extends \advanced_testcase {
    /**
     * Creates page.
     *
     * @return array{0: \stdClass, 1: \stdClass}
     */
    private function create_page(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->get_plugin_generator('mod_page')->create_instance([
            'course' => $course->id,
            'name' => 'Erste Fassung',
        ]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);

        return [$course, $cm];
    }

    public function test_history_uses_the_viewers_language(): void {
        global $CFG;
        $this->resetAfterTest();
        // Moodle's built-in language fixture makes German available without a downloaded language pack.
        $CFG->langotherroot = $CFG->libdir . '/tests/fixtures/langtest';
        get_string_manager(true);
        [$course, $cm] = $this->create_page();
        $listurl = new \moodle_url('/local/coursepilot/history.php', ['id' => $course->id]);
        \local_coursepilot\history\version_writer::capture((int) $cm->id, 0);

        force_current_language('en');
        $english = history_page::versions_data($cm->id, $cm->name, false, $listurl);
        $this->assertStringContainsString('first recorded state', $english['rows'][0]['summary_line']);
        $this->assertStringContainsString('no content change detected', $english['rows'][1]['summary_line']);
        $this->assertSame('User #0', $english['rows'][1]['user']);
        $this->assertStringContainsString('The history is incomplete', $english['gap_notice']);

        force_current_language('de');
        $german = history_page::versions_data($cm->id, $cm->name, false, $listurl);
        $this->assertStringContainsString('erster erfasster Stand', $german['rows'][0]['summary_line']);
        $this->assertStringContainsString('keine inhaltliche Änderung erkennbar', $german['rows'][1]['summary_line']);
        $this->assertSame('Nutzer #0', $german['rows'][1]['user']);
        $this->assertStringContainsString('Der Verlauf ist nicht lückenlos', $german['gap_notice']);
    }

    public function test_newest_version_never_offers_restore(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $listurl = new \moodle_url('/local/coursepilot/history.php', ['id' => $course->id]);

        $data = history_page::versions_data($cm->id, $cm->name, true, $listurl);

        $this->assertCount(1, $data['rows']);
        $this->assertFalse($data['rows'][0]['canrestore']);
        $this->assertNull($data['rows'][0]['restoreurl']);
    }

    public function test_canrestore_false_hides_restore_link_even_on_older_version(): void {
        $this->resetAfterTest();
        [$course, $cm] = $this->create_page();
        $listurl = new \moodle_url('/local/coursepilot/history.php', ['id' => $course->id]);

        $data = history_page::versions_data($cm->id, $cm->name, false, $listurl);

        foreach ($data['rows'] as $row) {
            $this->assertFalse($row['canrestore']);
            $this->assertNull($row['restoreurl']);
        }
    }

    public function test_activities_data_is_empty_for_a_course_without_history(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();

        $data = history_page::activities_data($course->id);

        $this->assertTrue($data['empty']);
        $this->assertSame([], $data['rows']);
    }

    public function test_activities_data_lists_activities_with_recorded_history(): void {
        $this->resetAfterTest();
        [$course] = $this->create_page();

        $data = history_page::activities_data($course->id);

        $this->assertFalse($data['empty']);
        $this->assertCount(1, $data['rows']);
        $this->assertSame('Erste Fassung', $data['rows'][0]['name']);
        $this->assertSame('page', $data['rows'][0]['modname']);
    }
}
