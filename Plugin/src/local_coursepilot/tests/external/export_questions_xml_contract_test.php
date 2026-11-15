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
 * Exact responses, messages and check order of export_questions_xml.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(export_questions_xml::class)]
#[CoversClass(\local_coursepilot\question_xml::class)]
final class export_questions_xml_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var int[] Question ids by name. */
    private array $questions = [];

    /** @var int Category id. */
    private int $categoryid;

    /**
     * Teacher with a bank and two multiple-choice questions.
     */
    private function setup_bank(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = ensure_question_bank::execute($course->id, 'Bank');
        $this->categoryid = (int) ensure_question_category::execute('Category', (int) $bank['topcategoryid'])['id'];
        foreach (['First', 'Second'] as $name) {
            $created = create_mc_question::execute($this->categoryid, $name, "Text of {$name}", 'single', [
                ['answer' => 'Yes', 'fraction' => 1], ['answer' => 'No', 'fraction' => 0],
            ]);
            $this->questions[$name] = $created['questionid'];
        }
    }

    public function test_an_empty_list_and_a_missing_target_path_are_refused_with_their_reason(): void {
        $this->setup_bank();

        $none = $this->caught(fn() => export_questions_xml::execute([], 'out.xml'));
        $this->assertInstanceOf(\invalid_parameter_exception::class, $none);
        $this->assertSame('Specify at least one questionid.', $none->debuginfo);

        foreach (['', '   '] as $blank) {
            $nopath = $this->caught(fn() => export_questions_xml::execute([$this->questions['First']], $blank));
            $this->assertInstanceOf(\invalid_parameter_exception::class, $nopath);
            $this->assertSame(
                'targetpath is required in standard mode (material store path of the XML file to write), '
                    . 'e.g. "export.xml". For placeholder mode, set placeholder=true instead.',
                $nopath->debuginfo
            );
        }
    }

    public function test_placeholder_response_is_exact(): void {
        $this->setup_bank();

        $one = export_questions_xml::execute([$this->questions['First']], '', true);
        $two = export_questions_xml::execute([$this->questions['First'], $this->questions['Second']], '', true);

        $this->assert_declared_types($one, export_questions_xml::execute_returns());
        $this->assertSame(['xml', 'path', 'count', 'message'], array_keys($one));
        $this->assertSame('', $one['path']);
        $this->assertSame(1, $one['count']);
        $this->assertSame(
            get_string('questionexportone', 'local_coursepilot') . ' ' . get_string(
                'questionexportplaceholder',
                'local_coursepilot'
            ),
            $one['message']
        );
        $this->assertStringStartsWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n", $one['xml']);
        $this->assertStringEndsWith("\n</quiz>\n", $one['xml']);
        $this->assertSame(1, substr_count($one['xml'], '<question type="multichoice">'));
        $this->assertSame(2, $two['count']);
        $this->assertSame(2, substr_count($two['xml'], '<question type="multichoice">'));
        $this->assertSame(
            get_string('questionexportmany', 'local_coursepilot', 2) . ' ' . get_string(
                'questionexportplaceholder',
                'local_coursepilot'
            ),
            $two['message']
        );
        $this->assertLessThan(strpos($two['xml'], 'Text of Second'), strpos($two['xml'], 'Text of First'));
    }

    public function test_embedded_files_are_replaced_and_named_in_the_placeholder_message(): void {
        global $DB;
        $this->setup_bank();
        $contextid = $DB->get_field('question_categories', 'contextid', ['id' => $this->categoryid]);
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid, 'component' => 'question', 'filearea' => 'questiontext',
            'itemid' => $this->questions['Second'], 'filepath' => '/', 'filename' => 'a.png',
        ], 'bytes');

        $result = export_questions_xml::execute([$this->questions['First'], $this->questions['Second']], '', true);

        $detail = get_string('questionexportmissingdetail', 'local_coursepilot', (object) ['name' => 'Second', 'files' => 'a.png']);
        $this->assertSame(
            get_string('questionexportmany', 'local_coursepilot', 2) . ' '
                . get_string('questionexportplaceholder', 'local_coursepilot') . ' '
                . get_string('questionexportmissing', 'local_coursepilot', $detail),
            $result['message']
        );
        $this->assertStringContainsString('File removed', $result['xml']);
    }

    public function test_standard_mode_writes_the_file_and_names_its_path(): void {
        $this->setup_bank();

        $result = export_questions_xml::execute([$this->questions['First']], 'export.xml');

        $this->assert_declared_types($result, export_questions_xml::execute_returns());
        $this->assertSame('', $result['xml']);
        $this->assertSame(1, $result['count']);
        $this->assertSame(
            get_string('questionexportone', 'local_coursepilot') . ' ' . get_string(
                'questionexportpath',
                'local_coursepilot',
                $result['path']
            ),
            $result['message']
        );
        $this->assertStringEndsWith('export.xml', $result['path']);
    }

    public function test_a_quota_warning_follows_the_path_in_the_message(): void {
        global $CFG;
        $this->setup_bank();
        $size = strlen(export_questions_xml::execute([$this->questions['First']], '', true)['xml']);
        $CFG->userquota = $size + 20;

        $result = export_questions_xml::execute([$this->questions['First']], 'export.xml');

        $this->assertSame(
            get_string('questionexportone', 'local_coursepilot') . ' ' . get_string(
                'questionexportpath',
                'local_coursepilot',
                $result['path']
            )
                . ' ' . get_string('materialquotawarning', 'local_coursepilot', '0.0'),
            $result['message']
        );
    }

    public function test_the_context_restriction_and_capabilities_are_checked_for_each_question(): void {
        global $DB;
        $this->setup_bank();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(fn() => export_questions_xml::execute([$this->questions['First']], '', true));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'moodle/question:viewall'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            $e = $this->caught(fn() => export_questions_xml::execute([$this->questions['First']], '', true));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }

    public function test_writing_to_the_material_store_needs_the_right_to_manage_own_files(): void {
        global $DB;
        $this->setup_bank();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'user'], MUST_EXIST);
        assign_capability('moodle/user:manageownfiles', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);

        $e = $this->caught(fn() => export_questions_xml::execute([$this->questions['First']], 'export.xml'));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_the_context_restriction_also_covers_the_material_store_of_standard_mode(): void {
        global $DB;
        $this->setup_bank();
        $bankcontext = \context::instance_by_id($DB->get_field('question_categories', 'contextid', ['id' => $this->categoryid]));

        external_api::set_context_restriction($bankcontext);
        try {
            $placeholder = $this->caught(fn() => export_questions_xml::execute([$this->questions['First']], '', true));
            $standard = $this->caught(fn() => export_questions_xml::execute([$this->questions['First']], 'export.xml'));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertNull($placeholder, 'The question itself lies in the restricted bank.');
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $standard);
    }
}
