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
use local_coursepilot\tests\caught_throwable;
use local_coursepilot\tests\declared_return_types;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact filters, ordering and types of the course catalog (Spec 0015, #379).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(get_course_catalog::class)]
final class get_course_catalog_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var \stdClass Course with three sections. */
    private \stdClass $course;

    /** @var int[] Page cmids in section 1 by creation order. */
    private array $pages = [];

    /**
     * Course with a teacher, named sections and three pages in section 1 listed in reverse order.
     */
    private function setup_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $this->pages[] = (int) $this->getDataGenerator()
                ->create_module('page', ['course' => $this->course->id, 'name' => $name], ['section' => 1])->cmid;
        }
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->set_field('course_sections', 'name', 'Algebra', ['id' => $section->id]);
        $DB->set_field('course_sections', 'summary', '<p>Lead  text</p>', ['id' => $section->id]);
        $DB->set_field('course_sections', 'sequence', implode(',', array_reverse($this->pages)), ['id' => $section->id]);
        $second = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 2], '*', MUST_EXIST);
        $restriction = ['op' => '&', 'c' => [['type' => 'date', 'd' => '>', 't' => 5]], 'showc' => [true]];
        $DB->set_field('course_sections', 'availability', json_encode($restriction), ['id' => $second->id]);
        rebuild_course_cache($this->course->id, true);
    }

    public function test_the_unfiltered_catalog_has_the_declared_types_in_every_field(): void {
        $this->setup_course();

        $result = get_course_catalog::execute((int) $this->course->id);

        $this->assert_declared_types($result, get_course_catalog::execute_returns());
        $this->assertSame((int) $this->course->id, $result['courseid']);
        $this->assertSame(['sectionnum' => -1, 'modname' => '', 'detail' => 'compact'], $result['filters']);
        $this->assertSame([0, 1, 2], array_column($result['sections'], 'sectionnum'));
    }

    public function test_sections_carry_their_name_summary_and_visibility(): void {
        global $DB;
        $this->setup_course();

        $sections = get_course_catalog::execute((int) $this->course->id)['sections'];

        $this->assertSame('Algebra', $sections[1]['name']);
        $this->assertSame('', $sections[2]['name']);
        $this->assertSame(
            ['html' => '', 'preview' => 'Lead text', 'truncated' => true],
            $sections[1]['summary']
        );
        $this->assertSame(['html' => '', 'preview' => '', 'truncated' => false], $sections[2]['summary']);
        $this->assertSame(1, $sections[1]['visible']);
        $this->assertSame('', $sections[1]['availability']);
        $this->assertStringContainsString('"type":"date"', $sections[2]['availability']);
        $this->assertSame(
            (int) $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]),
            $sections[1]['id']
        );
    }

    public function test_modules_follow_the_section_sequence_not_their_ids(): void {
        $this->setup_course();

        $modules = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['modules'];

        $this->assertSame(array_reverse($this->pages), array_column($modules, 'cmid'));
        $this->assertSame(['Gamma', 'Beta', 'Alpha'], array_column($modules, 'name'));
    }

    public function test_modules_missing_from_the_sequence_come_last_in_id_order(): void {
        global $DB;
        $this->setup_course();
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'sequence', (string) $this->pages[2], ['id' => $sectionid]);
        rebuild_course_cache($this->course->id, true);

        $modules = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['modules'];

        $this->assertSame([$this->pages[2], $this->pages[0], $this->pages[1]], array_column($modules, 'cmid'));
    }

    public function test_module_rows_have_the_declared_types_and_values(): void {
        $this->setup_course();

        $result = get_course_catalog::execute((int) $this->course->id, 1, 'page', 'full');

        $this->assert_declared_types($result, get_course_catalog::execute_returns());
        $module = $result['sections'][0]['modules'][2];
        $this->assertSame($this->pages[0], $module['cmid']);
        $this->assertSame('page', $module['modname']);
        $this->assertSame('Alpha', $module['name']);
        $this->assertSame(1, $module['visible']);
        $this->assertSame(1, $module['visibleoncoursepage']);
        $this->assertSame(0, $module['groupmode']);
        $this->assertSame(['completion' => 0, 'completionview' => 0, 'completionpassgrade' => 0], $module['completion']);
        $this->assertSame('', $module['availability']);
    }

    public function test_filters_are_reported_as_applied(): void {
        $this->setup_course();

        $result = get_course_catalog::execute((int) $this->course->id, 0, ' label ', 'FULL');

        $this->assertSame(['sectionnum' => 0, 'modname' => 'label', 'detail' => 'full'], $result['filters']);
        $this->assertSame([0], array_column($result['sections'], 'sectionnum'));
        $this->assertSame([], $result['sections'][0]['modules']);
    }

    public function test_the_module_filter_keeps_only_that_type(): void {
        global $DB;
        $this->setup_course();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $this->course->id], ['section' => 1]);

        $labels = get_course_catalog::execute((int) $this->course->id, 1, 'label')['sections'][0]['modules'];
        $all = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['modules'];

        $this->assertSame([(int) $label->cmid], array_column($labels, 'cmid'));
        $this->assertCount(4, $all);
        $this->assertSame(['label'], array_values(array_unique(array_column($labels, 'modname'))));
        $this->assertTrue((bool) $DB->record_exists('course_modules', ['id' => $label->cmid]));
    }

    public function test_modules_in_deletion_are_not_listed(): void {
        global $DB;
        $this->setup_course();
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $this->pages[1]]);

        $modules = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['modules'];

        $this->assertSame([$this->pages[2], $this->pages[0]], array_column($modules, 'cmid'));
    }

    public function test_detail_modes_decide_whether_the_html_is_delivered(): void {
        $this->setup_course();

        $compact = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0];
        $full = get_course_catalog::execute((int) $this->course->id, 1, '', 'full')['sections'][0];

        $this->assertSame('compact', get_course_catalog::execute((int) $this->course->id)['filters']['detail']);
        $this->assertSame('', $compact['summary']['html']);
        $this->assertTrue($compact['summary']['truncated']);
        $this->assertSame('<p>Lead  text</p>', $full['summary']['html']);
        $this->assertFalse($full['summary']['truncated']);
    }

    public function test_a_long_summary_is_cut_for_the_preview_only_in_compact_mode(): void {
        global $DB;
        $this->setup_course();
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]);
        $DB->set_field('course_sections', 'summary', '<p>' . str_repeat('word ', 80) . '</p>', ['id' => $sectionid]);

        $compact = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['summary']['preview'];
        $full = get_course_catalog::execute((int) $this->course->id, 1, '', 'full')['sections'][0]['summary']['preview'];

        $this->assertSame(280, strlen($compact));
        $this->assertSame(str_repeat('word ', 55) . 'wo...', $compact);
        $this->assertSame(rtrim(str_repeat('word ', 80)), $full);
    }

    public function test_the_context_restriction_of_the_web_service_is_enforced(): void {
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $e = $this->caught(fn() => get_course_catalog::execute((int) $this->course->id));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    public function test_the_plugin_capability_is_required(): void {
        global $DB;
        $this->setup_course();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/coursepilot:use', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);

        $e = $this->caught(fn() => get_course_catalog::execute((int) $this->course->id));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_a_summary_of_exactly_the_preview_limit_is_not_cut_and_blank_ones_are_not_truncated(): void {
        global $DB;
        $this->setup_course();
        $sectionid = $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]);

        $DB->set_field('course_sections', 'summary', '<p>' . str_repeat('x', 280) . '</p>', ['id' => $sectionid]);
        $exact = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['summary'];
        $DB->set_field('course_sections', 'summary', '<p>' . str_repeat('x', 281) . '</p>', ['id' => $sectionid]);
        $over = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['summary'];
        $DB->set_field('course_sections', 'summary', "  \n ", ['id' => $sectionid]);
        $blank = get_course_catalog::execute((int) $this->course->id, 1)['sections'][0]['summary'];

        $this->assertSame(str_repeat('x', 280), $exact['preview']);
        $this->assertSame(str_repeat('x', 277) . '...', $over['preview']);
        $this->assertSame(['html' => '', 'preview' => '', 'truncated' => false], $blank);
    }
}
