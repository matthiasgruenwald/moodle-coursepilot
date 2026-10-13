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

use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Complete catalog settings for activity read-modify-write.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(module_state::class)]
final class module_state_test extends \advanced_testcase {
    /**
     * Catalog clients can read full page content and shared settings.
     */
    public function test_reads_complete_activity_settings(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Überblick',
            'content' => '<p>Read / write</p>',
        ]);

        $result = module_state::read_settings((int) $page->cmid);

        $this->assertSame((int) $page->cmid, $result['cmid']);
        $this->assertSame('page', $result['modname']);
        $this->assertSame([], $result['learner_locks']);
        $settings = json_decode($result['settings_json'], true);
        $this->assertSame('Überblick', $settings['name']);
        $this->assertSame('<p>Read / write</p>', $settings['content']);
        $this->assertSame((int) $course->id, $settings['course']);
        $this->assertSame('page', $settings['modulename']);
        $this->assertStringContainsString('Überblick', $result['settings_json']);
        $this->assertStringContainsString('Read / write', $result['settings_json']);
    }

    /**
     * Activities without a field catalog remain readable without learner locks.
     */
    public function test_reads_uncatalogued_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $book = $this->getDataGenerator()->create_module('book', ['course' => $course->id, 'name' => 'Reading']);

        $result = module_state::read_settings((int) $book->cmid);

        $this->assertSame('book', $result['modname']);
        $this->assertSame('Reading', json_decode($result['settings_json'], true)['name']);
        $this->assertSame([], $result['learner_locks']);
    }
}
