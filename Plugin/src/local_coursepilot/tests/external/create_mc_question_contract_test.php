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
 * Exact response, stored content, suspect gate and check order of create_mc_question.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(create_mc_question::class)]
#[CoversClass(\local_coursepilot\question_xml::class)]
#[CoversClass(\local_coursepilot\question_import::class)]
#[CoversClass(\local_coursepilot\question_suspect_gate::class)]
final class create_mc_question_contract_test extends \advanced_testcase {
    use caught_throwable;
    use declared_return_types;

    /** @var int Category id. */
    private int $categoryid;

    /** @var \stdClass Course. */
    private \stdClass $course;

    /**
     * Teacher with a bank and a category.
     */
    private function setup_bank(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = ensure_question_bank::execute($this->course->id, 'Bank');
        $this->categoryid = (int) ensure_question_category::execute('Category', (int) $bank['topcategoryid'])['id'];
    }

    /**
     * Two answers, one correct.
     *
     * @return mixed[]
     */
    private static function answers(): array {
        return [['answer' => 'Yes', 'fraction' => 1, 'feedback' => 'Right'], ['answer' => 'No', 'fraction' => 0]];
    }

    public function test_created_response_is_exact_with_declared_types(): void {
        global $DB;
        $this->setup_bank();

        $result = create_mc_question::execute($this->categoryid, 'First', 'Question?', 'single', self::answers());

        $this->assert_declared_types($result, create_mc_question::execute_returns());
        $entryid = (int) $DB->get_field('question_bank_entries', 'id', ['questioncategoryid' => $this->categoryid]);
        $questionid = (int) $DB->get_field('question', 'id', ['name' => 'First']);
        $this->assertSame([
            'name' => 'First',
            'questionid' => $questionid,
            'questionbankentryid' => $entryid,
            'version' => 1,
            'status' => 'first_import',
            'message' => 'MC question "First" created (bank entry ' . $entryid . ', version 1).',
            'idnumber' => '',
            'categoryid' => 0,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => '',
        ], $result);
    }

    public function test_the_stored_question_has_the_exact_content_and_defaults(): void {
        global $DB;
        $this->setup_bank();
        $name = 'Fish & Chips';
        $text = 'Text with ]]> inside and <b>html</b>';

        $result = create_mc_question::execute($this->categoryid, $name, $text, 'multiple', [
            ['answer' => 'One ]]> end', 'fraction' => 0.33333, 'feedback' => 'F1 ]]> end'],
            ['answer' => 'Two', 'fraction' => 0.33333],
            ['answer' => 'Three', 'fraction' => 0.33334, 'feedback' => 'F3'],
            ['answer' => 'Wrong', 'fraction' => -0.5],
        ]);

        $question = $DB->get_record('question', ['id' => $result['questionid']], '*', MUST_EXIST);
        $this->assertSame(clean_param($name, PARAM_TEXT), $question->name);
        $this->assertSame($text, $question->questiontext);
        $this->assertSame('', $question->generalfeedback);
        $this->assertEquals(1.0, $question->defaultmark);
        $answers = array_values($DB->get_records('question_answers', ['question' => $question->id], 'id'));
        $this->assertSame(['One ]]> end', 'Two', 'Three', 'Wrong'], array_column($answers, 'answer'));
        $this->assertSame(['F1 ]]> end', '', 'F3', ''], array_column($answers, 'feedback'));
        $this->assertEqualsWithDelta(0.3333300, (float) $answers[0]->fraction, 1e-9);
        $this->assertEqualsWithDelta(0.3333400, (float) $answers[2]->fraction, 1e-9);
        $this->assertEqualsWithDelta(-0.5, (float) $answers[3]->fraction, 1e-9);
        $this->assertSame('0', (string) $DB->get_field('qtype_multichoice_options', 'single', ['questionid' => $question->id]));
    }

    public function test_default_mark_general_feedback_and_single_mode_are_stored(): void {
        global $DB;
        $this->setup_bank();

        $with = create_mc_question::execute($this->categoryid, 'With', 'Q', 'single', self::answers(), 2.5, 'General');
        $without = create_mc_question::execute($this->categoryid, 'Without', 'Q', 'single', self::answers());

        $this->assertEquals(2.5, $DB->get_field('question', 'defaultmark', ['id' => $with['questionid']]));
        $this->assertSame('General', $DB->get_field('question', 'generalfeedback', ['id' => $with['questionid']]));
        $this->assertEquals(1.0, $DB->get_field('question', 'defaultmark', ['id' => $without['questionid']]));
        $this->assertSame(
            '1',
            (string) $DB->get_field('qtype_multichoice_options', 'single', ['questionid' => $without['questionid']])
        );
    }

    public function test_a_same_named_entry_is_reported_as_suspect_until_confirmed(): void {
        global $DB;
        $this->setup_bank();
        $first = create_mc_question::execute($this->categoryid, 'Twin', 'Question?', 'single', self::answers());
        $count = $DB->count_records('question_bank_entries');

        $held = create_mc_question::execute($this->categoryid, 'Twin', 'Second text', 'single', self::answers());

        $this->assertSame([
            'name' => 'Twin',
            'questionid' => 0,
            'questionbankentryid' => 0,
            'version' => 0,
            'status' => 'suspect',
            'message' => 'Suspect case: The target category already contains an entry named "Twin". '
                . 'Nothing was created. To create a new entry anyway, call again with confirmed=true.',
            'idnumber' => '',
            'categoryid' => $this->categoryid,
            'candidates' => [[
                'questionid' => $first['questionid'],
                'name' => 'Twin',
                'idnumber' => $DB->get_field('question_bank_entries', 'idnumber', ['id' => $first['questionbankentryid']]),
            ]],
            'questiontext_old' => '',
            'questiontext_new' => 'Second text',
        ], $held);
        $this->assertSame($count, $DB->count_records('question_bank_entries'));

        $confirmed = create_mc_question::execute(
            $this->categoryid,
            'Twin',
            'Second text',
            'single',
            self::answers(),
            1.0,
            '',
            true
        );

        $this->assertSame('first_import', $confirmed['status']);
        $this->assertSame($count + 1, $DB->count_records('question_bank_entries'));
    }

    public function test_the_context_restriction_and_each_capability_come_before_the_suspect_check(): void {
        global $DB;
        $this->setup_bank();
        create_mc_question::execute($this->categoryid, 'Twin', 'Question?', 'single', self::answers());
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $restricted = $this->caught(
                fn() => create_mc_question::execute($this->categoryid, 'Twin', 'Q', 'single', self::answers())
            );
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }
        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $restricted);

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        foreach (['local/coursepilot:use', 'moodle/question:add'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
            // A single answer is invalid, so a removed capability check would end in another exception.
            $e = $this->caught(fn() => create_mc_question::execute(
                $this->categoryid,
                'Twin',
                'Q',
                'single',
                [['answer' => 'Only', 'fraction' => 1]]
            ));
            $this->assertInstanceOf(\required_capability_exception::class, $e, $capability);
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
    }

    public function test_invalid_answers_are_refused_before_the_suspect_check(): void {
        $this->setup_bank();
        create_mc_question::execute($this->categoryid, 'Twin', 'Question?', 'single', self::answers());

        $e = $this->caught(
            fn() => create_mc_question::execute($this->categoryid, 'Twin', 'Q', 'single', [['answer' => 'Only', 'fraction' => 1]])
        );

        $this->assertInstanceOf(\invalid_parameter_exception::class, $e);
        $this->assertSame('A multiple-choice question needs at least 2 answers.', $e->debuginfo);
    }

    public function test_the_generated_xml_is_exact(): void {
        $method = new \ReflectionMethod(create_mc_question::class, 'build_xml');

        $xml = $method->invoke(null, [
            'name' => 'O\'Neil "x" <&>',
            'questiontext' => 'Text ]]> end',
            'generalfeedback' => 'General',
            'selectionmode' => 'multiple',
            'defaultmark' => 2.5,
            'answers' => [
                ['answer' => 'A', 'fraction' => 1 / 3, 'feedback' => 'Fa'],
                ['answer' => 'B', 'fraction' => 0.12345678],
                ['answer' => 'C', 'fraction' => 0.5, 'feedback' => ''],
            ],
        ]);

        $expected = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<quiz>
  <question type="multichoice">
    <name><text>O&apos;Neil &quot;x&quot; &lt;&amp;&gt;</text></name>
    <questiontext format="html"><text><![CDATA[Text ]]]]><![CDATA[> end]]></text></questiontext>
    <generalfeedback format="html"><text><![CDATA[General]]></text></generalfeedback>
    <defaultgrade>2.5</defaultgrade>
    <penalty>0.3333333</penalty>
    <hidden>0</hidden>
    <idnumber></idnumber>
    <single>false</single>
    <shuffleanswers>true</shuffleanswers>
    <answernumbering>abc</answernumbering>
    <correctfeedback format="html"><text></text></correctfeedback>
    <partiallycorrectfeedback format="html"><text></text></partiallycorrectfeedback>
    <incorrectfeedback format="html"><text></text></incorrectfeedback>
    <answer fraction="33.33333" format="html">
      <text><![CDATA[A]]></text>
      <feedback format="html"><text><![CDATA[Fa]]></text></feedback>
    </answer>
    <answer fraction="12.34568" format="html">
      <text><![CDATA[B]]></text>
      <feedback format="html"><text><![CDATA[]]></text></feedback>
    </answer>
    <answer fraction="50" format="html">
      <text><![CDATA[C]]></text>
      <feedback format="html"><text><![CDATA[]]></text></feedback>
    </answer>
  </question>
</quiz>
XML;

        $this->assertSame($expected, $xml);
    }
}
