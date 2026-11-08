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

use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Discarding a half-made activity row invalidates the course cache (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(course_module_placement::class)]
final class course_module_placement_674_final_contract_test extends \advanced_testcase {
    /**
     * The course cache revision moves on, also when the row never made it into the section sequence.
     */
    public function test_discarding_a_half_made_row_bumps_the_course_cache_revision(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        // The task inserted the row but did not append it to the section sequence yet.
        $cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $DB->get_field('modules', 'id', ['name' => 'page']),
            'instance' => 0, 'section' => $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1]),
        ]);
        $before = (int) $DB->get_field('course', 'cacherev', ['id' => $course->id]);

        course_module_placement::discard_failed($cmid);

        $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));
        $this->assertGreaterThan($before, (int) $DB->get_field('course', 'cacherev', ['id' => $course->id]));
    }
}
