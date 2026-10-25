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
 * course_module_placement (Spec 0026 module 4, #595).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(course_module_placement::class)]
final class course_module_placement_test extends \advanced_testcase {
    use \local_coursepilot\tests\caught_throwable;

    /**
     * Provides course with pages.
     *
     * @return array{0: \stdClass, 1: int[]} course and the cmids of three pages in section 1
     */
    private function course_with_pages(): array {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $cmids = [];
        foreach (['A', 'B', 'C'] as $name) {
            $cmids[] = (int) $this->getDataGenerator()->create_module(
                'page',
                ['course' => $course->id, 'name' => $name],
                ['section' => 1]
            )->cmid;
        }
        return [$course, $cmids];
    }

    /**
     * Returns cmids of a section in course order.
     *
     * @param int $courseid The courseid.
     * @param int $sectionnum The sectionnum.
     * @return int[] cmids of a section in course order
     */
    private function order(int $courseid, int $sectionnum): array {
        rebuild_course_cache($courseid, true);
        return array_map('intval', get_fast_modinfo($courseid)->sections[$sectionnum] ?? []);
    }

    /**
     * Provides cmids.
     *
     * @param int $courseid The courseid.
     * @return mixed[]
     */
    private function cmids(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$courseid]));
    }

    public function test_place_after_in_the_middle_goes_before_the_successor(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        course_module_placement::place_after($c, $a);
        $this->assertSame([$a, $c, $b], $this->order((int) $course->id, 1));
    }

    public function test_place_after_the_last_one_goes_to_section_end(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        course_module_placement::place_after($a, $c);
        $this->assertSame([$b, $c, $a], $this->order((int) $course->id, 1));
    }

    public function test_place_after_moves_across_sections(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        $other = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id], ['section' => 2])->cmid;
        course_module_placement::place_after($a, $other);
        $this->assertSame([$other, $a], $this->order((int) $course->id, 2));
        $this->assertSame([$b, $c], $this->order((int) $course->id, 1));
    }

    public function test_place_after_its_own_predecessor_changes_nothing(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        course_module_placement::place_after($b, $a);
        $this->assertSame([$a, $b, $c], $this->order((int) $course->id, 1));
    }

    public function test_set_visible_hides_and_shows(): void {
        global $DB;
        [, [$a]] = $this->course_with_pages();
        course_module_placement::set_visible($a, false);
        $this->assertSame(0, (int) $DB->get_field('course_modules', 'visible', ['id' => $a]));
        course_module_placement::set_visible($a, true);
        $this->assertSame(1, (int) $DB->get_field('course_modules', 'visible', ['id' => $a]));
    }

    public function test_discard_failed_removes_the_activity(): void {
        [$course, [$a]] = $this->course_with_pages();
        course_module_placement::discard_failed($a);
        $this->assertNotContains($a, $this->cmids((int) $course->id));
    }

    public function test_discard_failed_leaves_nothing_in_the_recycle_bin(): void {
        global $DB;
        [$course, [$a]] = $this->course_with_pages();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        $bin = $DB->count_records('tool_recyclebin_course');
        course_module_placement::discard_failed($a);
        $this->assertNotContains($a, $this->cmids((int) $course->id));
        $this->assertSame($bin, $DB->count_records('tool_recyclebin_course'));
    }

    public function test_discard_failed_keeps_older_recycle_bin_items(): void {
        global $DB;
        [$course, [$a, $b]] = $this->course_with_pages();
        set_config('coursebinenable', 1, 'tool_recyclebin');
        course_get_format($course)->delete_module(get_fast_modinfo($course)->get_cm($b), false);
        $bin = $DB->count_records('tool_recyclebin_course');
        $this->assertGreaterThan(0, $bin);
        course_module_placement::discard_failed($a);
        $this->assertSame($bin, $DB->count_records('tool_recyclebin_course'));
    }

    public function test_discard_failed_removes_a_half_made_row(): void {
        global $DB;
        [$course] = $this->course_with_pages();
        $cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $DB->get_field('modules', 'id', ['name' => 'page']),
            'instance' => 0, 'section' => $DB->get_field('course_sections', 'id', ['course' => $course->id, 'section' => 1]),
        ]);
        course_module_placement::discard_failed($cmid);
        $this->assertNotContains($cmid, $this->cmids((int) $course->id));
    }

    public function test_move_to_puts_an_activity_before_another_or_at_the_end_of_a_section(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        $sectionid = (int) $this->section_id((int) $course->id, 1);
        $other = (int) $this->getDataGenerator()->create_module('page', ['course' => $course->id], ['section' => 2])->cmid;

        course_module_placement::move_to($c, $sectionid, $a);
        $this->assertSame([$c, $a, $b], $this->order((int) $course->id, 1));

        course_module_placement::move_to($c, $sectionid, null);
        $this->assertSame([$a, $b, $c], $this->order((int) $course->id, 1));

        course_module_placement::move_to($a, $this->section_id((int) $course->id, 2), $other);
        $this->assertSame([$a, $other], $this->order((int) $course->id, 2));
        $this->assertSame([$b, $c], $this->order((int) $course->id, 1));
    }

    /**
     * Section id (course_sections.id) of a section number.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @return int
     */
    private function section_id(int $courseid, int $sectionnum): int {
        global $DB;
        return (int) $DB->get_field('course_sections', 'id', ['course' => $courseid, 'section' => $sectionnum], MUST_EXIST);
    }

    public function test_placement_works_in_a_course_other_than_the_first_one(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        $second = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $x = (int) $this->getDataGenerator()->create_module('page', ['course' => $second->id], ['section' => 1])->cmid;
        $y = (int) $this->getDataGenerator()->create_module('page', ['course' => $second->id], ['section' => 1])->cmid;
        $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $second->id);
        $this->setAdminUser();

        $e = $this->caught(fn() => course_module_placement::place_after($x, $y));
        $this->assertNull($e);
        $this->assertSame([$y, $x], $this->order((int) $second->id, 1));
        $this->assertSame([$a, $b, $c], $this->order((int) $course->id, 1), 'The other course stays as it was.');

        $e = $this->caught(fn() => course_module_placement::set_visible($y, false));
        $this->assertNull($e);
    }

    public function test_discarding_a_half_made_row_completes_its_link_and_touches_no_other_activity(): void {
        global $DB;
        [$course, [$a, $b]] = $this->course_with_pages();
        $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'page']);
        $orphan = (int) $DB->insert_record('page', (object) [
            'course' => $course->id, 'name' => 'Orphan', 'intro' => '', 'introformat' => 1, 'content' => '',
            'contentformat' => 1, 'legacyfiles' => 0, 'display' => 5, 'displayoptions' => '', 'revision' => 1,
            'timemodified' => time(),
        ]);
        $cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $moduleid, 'instance' => 0,
            'section' => $this->section_id((int) $course->id, 1),
        ]);
        course_add_cm_to_section($course, $cmid, 1);
        $before = $DB->get_records_menu('course_modules', ['course' => $course->id], 'id', 'id, instance');
        unset($before[$cmid]);

        course_module_placement::discard_failed($cmid, $orphan);

        $after = $DB->get_records_menu('course_modules', ['course' => $course->id], 'id', 'id, instance');
        $this->assertSame($before, $after, 'Every other activity keeps its own instance.');
        $this->assertFalse($DB->record_exists('page', ['id' => $orphan]), 'The linked instance is deleted with the row.');
    }

    public function test_discarding_a_half_made_row_removes_its_context(): void {
        global $DB;
        [$course] = $this->course_with_pages();
        $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'page']);
        $cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $moduleid, 'instance' => 0,
            'section' => $this->section_id((int) $course->id, 1),
        ]);
        \context_module::instance($cmid);
        $this->assertTrue($DB->record_exists('context', ['contextlevel' => CONTEXT_MODULE, 'instanceid' => $cmid]));

        course_module_placement::discard_failed($cmid);

        $this->assertFalse($DB->record_exists('context', ['contextlevel' => CONTEXT_MODULE, 'instanceid' => $cmid]));
    }

    public function test_discarding_an_activity_leaves_the_course_cache_without_it(): void {
        [$course, [$a, $b, $c]] = $this->course_with_pages();
        $this->assertArrayHasKey($a, get_fast_modinfo((int) $course->id)->cms);

        course_module_placement::discard_failed($a);

        $this->assertArrayNotHasKey($a, get_fast_modinfo((int) $course->id)->cms);
        $this->assertSame([$b, $c], $this->order((int) $course->id, 1));
    }

    public function test_discarding_leaves_the_forced_recycle_bin_setting_as_it_was(): void {
        global $CFG;
        [, [$a, $b]] = $this->course_with_pages();
        $CFG->forced_plugin_settings['tool_recyclebin'] = ['coursebinenable' => 1, 'categorybinenable' => 1];

        course_module_placement::discard_failed($a);

        $this->assertSame(['coursebinenable' => 1, 'categorybinenable' => 1], $CFG->forced_plugin_settings['tool_recyclebin']);

        unset($CFG->forced_plugin_settings['tool_recyclebin']);
        course_module_placement::discard_failed($b);
        $this->assertArrayNotHasKey('tool_recyclebin', $CFG->forced_plugin_settings ?? []);
    }

    public function test_a_failing_deletion_still_restores_the_forced_recycle_bin_setting(): void {
        global $CFG, $DB;
        [$course] = $this->course_with_pages();
        $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'page']);
        $cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $moduleid, 'instance' => 987654,
            'section' => $this->section_id((int) $course->id, 1),
        ]);
        course_add_cm_to_section($course, $cmid, 1);
        $CFG->forced_plugin_settings['tool_recyclebin'] = ['coursebinenable' => 1];

        $e = $this->caught(fn() => course_module_placement::discard_failed($cmid));

        $this->assertNotNull($e);
        $this->assertSame(['coursebinenable' => 1], $CFG->forced_plugin_settings['tool_recyclebin']);
    }

    public function test_a_link_completed_for_a_warm_course_cache_is_seen_by_the_deletion(): void {
        global $DB;
        [$course] = $this->course_with_pages();
        $moduleid = (int) $DB->get_field('modules', 'id', ['name' => 'page']);
        $orphan = (int) $DB->insert_record('page', (object) [
            'course' => $course->id, 'name' => 'Orphan', 'intro' => '', 'introformat' => 1, 'content' => '',
            'contentformat' => 1, 'legacyfiles' => 0, 'display' => 5, 'displayoptions' => '', 'revision' => 1,
            'timemodified' => time(),
        ]);
        $cmid = (int) $DB->insert_record('course_modules', (object) [
            'course' => $course->id, 'module' => $moduleid, 'instance' => 0,
            'section' => $this->section_id((int) $course->id, 1),
        ]);
        course_add_cm_to_section($course, $cmid, 1);
        get_fast_modinfo((int) $course->id);

        $e = $this->caught(fn() => course_module_placement::discard_failed($cmid, $orphan));

        $this->assertNull($e);
        $this->assertFalse($DB->record_exists('course_modules', ['id' => $cmid]));
    }

    public function test_a_deletion_that_fails_inside_core_still_restores_the_forced_recycle_bin_setting(): void {
        global $CFG, $DB;
        if ($DB->get_dbfamily() !== 'mysql') {
            $this->markTestSkipped('MariaDB/MySQL failure trigger fixture required.');
        }
        [$course, [$a]] = $this->course_with_pages();
        $CFG->forced_plugin_settings['tool_recyclebin'] = ['coursebinenable' => 1];
        $cfg = $DB->export_dbconfig();
        $options = (array) ($cfg->dboptions ?? []);
        $ddl = new \mysqli(
            $cfg->dbhost,
            $cfg->dbuser,
            $cfg->dbpass,
            $cfg->dbname,
            (int) ($options['dbport'] ?? ini_get('mysqli.default_port')),
            is_string($options['dbsocket'] ?? null) ? $options['dbsocket'] : null
        );
        $key = 'cp674' . bin2hex(random_bytes(8));
        // Core deletes the activity's own record while the module is removed.
        $ddl->query("CREATE TRIGGER $key BEFORE DELETE ON " . $DB->get_prefix() . 'page
            FOR EACH ROW SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'Synthetic deletion failure\'');
        try {
            $e = $this->caught(fn() => course_module_placement::discard_failed($a));
        } finally {
            if ($DB->is_transaction_started()) {
                $DB->force_transaction_rollback(); // Clean up the deliberately broken red fixture.
            }
            $ddl->query("DROP TRIGGER IF EXISTS $key");
            $ddl->close();
        }

        $this->assertNotNull($e);
        $this->assertStringContainsString(
            'Synthetic deletion failure',
            $e->getMessage() . ($e instanceof \moodle_exception ? (string) $e->debuginfo : '')
        );
        $this->assertSame(['coursebinenable' => 1], $CFG->forced_plugin_settings['tool_recyclebin']);
    }
}
