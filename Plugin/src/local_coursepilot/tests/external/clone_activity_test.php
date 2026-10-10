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
use local_coursepilot\history\version_writer;
use PHPUnit\Framework\Attributes\CoversClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Cloning (Spec 0017 §7.5, #421): one endpoint handles both paths,
 * removes broken prerequisites and records exactly one history entry
 * with source cloned.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(clone_activity::class)]
final class clone_activity_test extends \advanced_testcase {
    /**
     * Provides course with editing teacher.
     *
     * @return array{0: \stdClass, 1: \stdClass} Course, teacher (editingteacher).
     */
    private function course_with_editing_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return [$course, $teacher];
    }

    /**
     * Same-course clones preserve plugin settings (page content here) and
     * use the supplied title without a copy suffix.
     */
    public function test_intra_course_clone_preserves_settings_and_sets_title(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'name' => 'Original',
            'content' => 'Original-Inhalt',
        ]);

        $result = clone_activity::execute((int) $page->cmid, 'Klon von Original');
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $this->assertNotSame((int) $page->cmid, $result['cmid']);
        $this->assertSame((int) $course->id, $result['courseid']);

        $newcm = get_coursemodule_from_id('page', $result['cmid'], 0, false, MUST_EXIST);
        $this->assertSame('Klon von Original', $newcm->name);
        $this->assertStringNotContainsStringIgnoringCase('kopie', $newcm->name);
        $this->assertStringNotContainsStringIgnoringCase('copy', $newcm->name);

        $newinstance = $DB->get_record('page', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('Original-Inhalt', $newinstance->content);
    }

    /**
     * Cross-course clones appear in the target course.
     */
    public function test_cross_course_clone(): void {
        global $DB;
        $this->resetAfterTest();
        [$sourcecourse, $teacher] = $this->course_with_editing_teacher();
        $targetcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $targetcourse->id, 'editingteacher');

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $sourcecourse->id,
            'name' => 'Original',
            'content' => 'Original-Inhalt',
        ]);

        $result = clone_activity::execute((int) $page->cmid, 'Klon in anderem Kurs', (int) $targetcourse->id);
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $this->assertSame((int) $targetcourse->id, $result['courseid']);
        $newcm = get_coursemodule_from_id('page', $result['cmid'], (int) $targetcourse->id, false, MUST_EXIST);
        $this->assertSame('Klon in anderem Kurs', $newcm->name);

        $newinstance = $DB->get_record('page', ['id' => $newcm->instance], '*', MUST_EXIST);
        $this->assertStringContainsString('Original-Inhalt', $newinstance->content);
    }

    /**
     * Detect and remove broken completion references to modules not copied
     * into the target course, describing the removal clearly.
     */
    public function test_removes_and_names_broken_prerequisite_on_cross_course_clone(): void {
        global $DB;
        $this->resetAfterTest();
        [$sourcecourse, $teacher] = $this->course_with_editing_teacher();
        $targetcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $targetcourse->id, 'editingteacher');

        $prerequisite = $this->getDataGenerator()->create_module('page', [
            'course' => $sourcecourse->id,
            'name' => 'Voraussetzung-Ziel',
        ]);
        $dependent = $this->getDataGenerator()->create_module('page', [
            'course' => $sourcecourse->id,
            'name' => 'Abhaengige Aktivitaet',
        ]);

        set_restriction::execute((int) $dependent->cmid, json_encode([
            ['type' => 'completion', 'activity_cmid' => (int) $prerequisite->cmid, 'status' => 'complete'],
        ]));

        $result = clone_activity::execute((int) $dependent->cmid, 'Klon mit kaputter Voraussetzung', (int) $targetcourse->id);
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $this->assertStringContainsString('entfernt', $result['message']);
        $this->assertStringContainsString('Voraussetzung-Ziel', $result['message']);

        $newcm = $DB->get_record('course_modules', ['id' => $result['cmid']], '*', MUST_EXIST);
        $this->assertTrue($newcm->availability === null || $newcm->availability === '');
    }

    /**
     * Reject missing target-course editing capability. Enroll the teacher
     * as a student so validation reaches the capability check instead of
     * failing earlier with require_login_exception, following
     * set_restriction_test::test_requires_manageactivities_capability().
     */
    public function test_capability_error_in_target_course(): void {
        $this->resetAfterTest();
        [$sourcecourse, $teacher] = $this->course_with_editing_teacher();
        $targetcourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($teacher->id, $targetcourse->id, 'student');

        $page = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id]);

        $this->expectException(\required_capability_exception::class);
        clone_activity::execute((int) $page->cmid, 'Klon', (int) $targetcourse->id);
    }

    /**
     * Clearly reject missing editing capability in the source course too.
     */
    public function test_capability_error_in_source_course(): void {
        $this->resetAfterTest();
        $sourcecourse = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $sourcecourse->id, 'student');
        $this->setUser($teacher);

        $page = $this->getDataGenerator()->create_module('page', ['course' => $sourcecourse->id]);

        $this->expectException(\required_capability_exception::class);
        clone_activity::execute((int) $page->cmid, 'Klon');
    }

    /**
     * Cloning records exactly one version 1 with source cloned and source
     * module ID, regardless of observer writes during cloning.
     */
    public function test_history_stand_has_correct_origin(): void {
        global $DB;
        $this->resetAfterTest();
        [$course] = $this->course_with_editing_teacher();

        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);

        $result = clone_activity::execute((int) $page->cmid, 'Klon');
        $result = external_api::clean_returnvalue(clone_activity::execute_returns(), $result);

        $versions = array_values($DB->get_records('local_coursepilot_cm_version', ['cmid' => $result['cmid']]));
        $this->assertCount(1, $versions);
        $this->assertSame(1, (int) $versions[0]->version);
        $this->assertSame(version_writer::SOURCE_CLONED, $versions[0]->source);
        $this->assertSame((int) $page->cmid, (int) $versions[0]->sourcecmid);
    }
}
