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


namespace local_coursepilot\output;

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * The module history view runs on the activity's module context (#674 wave 2).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(history_page::class)]
final class history_page_674_final_contract_test extends \advanced_testcase {
    public function test_the_module_view_sets_the_module_context_on_the_page(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        /** @var \mod_page_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_page');
        $page = $generator->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('page', $page->id, $course->id, false, MUST_EXIST);
        $PAGE = new \moodle_page();

        history_page::response((int) $cm->id, 0, 0, false);

        $this->assertSame(
            (int) \context_module::instance($cm->id)->id,
            (int) $PAGE->context->id
        );
        $this->assertDebuggingNotCalled();
    }

    public function test_the_course_view_sets_the_course_context_on_the_page(): void {
        global $PAGE;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $PAGE = new \moodle_page();
        // A context already set on the page must not survive the course view.
        $PAGE->set_context(\context_system::instance());

        history_page::response(0, (int) $course->id, 0, false);

        $this->assertSame((int) \context_course::instance($course->id)->id, (int) $PAGE->context->id);
        $this->assertDebuggingNotCalled();
    }
}
