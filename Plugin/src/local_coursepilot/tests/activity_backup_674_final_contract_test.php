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

/**
 * Whole-number fallback of the scaffolded module version (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(activity_backup::class)]
final class activity_backup_674_final_contract_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        // Backup temporary-table bookkeeping must survive each operation's transaction boundary.
        $this->preventResetByRollback();
    }

    /**
     * Course with an editing teacher acting.
     *
     * @return \stdClass
     */
    private function course_as_editing_teacher(): \stdClass {
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return $course;
    }

    /**
     * Minimal activity XML of a page.
     *
     * @return string
     */
    private function page_xml(): string {
        return '<activity id="1" moduleid="900001" modulename="page" contextid="1"><page id="1">'
            . '<name>Versioned</name><intro></intro><introformat>1</introformat>'
            . '<content>&lt;p&gt;Hallo&lt;/p&gt;</content><contentformat>1</contentformat>'
            . '<legacyfiles>0</legacyfiles><legacyfileslast>$@NULL@$</legacyfileslast><display>5</display>'
            . '<displayoptions>a:1:{s:12:"printheading";s:1:"1";}</displayoptions><revision>1</revision>'
            . '<timemodified>0</timemodified></page></activity>';
    }

    /**
     * A stored module version that is zero as a whole number but not as text still falls back to the site version.
     */
    public function test_descriptor_falls_back_when_the_stored_version_is_zero_as_a_whole_number(): void {
        global $CFG;
        $this->resetAfterTest();
        $course = $this->course_as_editing_teacher();
        set_config('version', '0.5', 'mod_page');
        $CFG->keeptempdirectoriesonbackup = true;
        $before = glob($CFG->tempdir . '/backup/cp*', GLOB_ONLYDIR) ?: [];

        activity_backup::restore((int) $course->id, 1, $this->page_xml());

        $created = array_values(array_diff(glob($CFG->tempdir . '/backup/cp*', GLOB_ONLYDIR) ?: [], $before));
        $this->assertCount(1, $created);
        $this->assertStringContainsString(
            '<module id="900001" version="' . (int) $CFG->version . '">',
            file_get_contents($created[0] . '/activities/page_900001/module.xml')
        );
    }
}
