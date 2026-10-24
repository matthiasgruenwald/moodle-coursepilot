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
use local_coursepilot\question_import;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Import access, complete results and actionable input failures at the write boundary.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(question_import::class)]
#[CoversClass(import_questions_xml::class)]
final class question_import_contract_test extends \advanced_testcase {
    /**
     * Restore the external API restriction for the next client.
     */
    protected function tearDown(): void {
        external_api::set_context_restriction(\context_system::instance());
        parent::tearDown();
    }

    /**
     * A token restricted to another course cannot write to this question bank.
     */
    public function test_import_respects_the_external_context_restriction(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $other = $this->getDataGenerator()->create_course();
        external_api::set_context_restriction(\context_course::instance($other->id));
        $this->expectException(\core_external\restricted_context_exception::class);
        import_questions_xml::execute($categoryid, self::xml('Restricted'));
    }

    /**
     * Question-add permission does not replace Coursepilot access.
     */
    public function test_import_requires_coursepilot_access(): void {
        $this->resetAfterTest();
        [$course, $categoryid] = $this->category();
        global $DB;
        $roleid = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('local/coursepilot:use', CAP_PROHIBIT, $roleid, \context_course::instance($course->id)->id, true);
        $this->expectException(\required_capability_exception::class);
        import_questions_xml::execute($categoryid, self::xml('Forbidden'));
    }

    /**
     * A resolved material reference keeps the file block but drops the material attribute.
     */
    public function test_material_reference_attribute_is_removed_from_the_resolved_block(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $bytes = "\x89PNG\r\n\x1a\nstub";
        [$directory, $name] = \local_coursepilot\material_files::resolve_file('diagramm.png');
        get_file_storage()->create_file_from_string(
            \local_coursepilot\material_files::filerecord(
                \local_coursepilot\material_files::own_context()->id,
                $directory,
                $name
            ),
            $bytes
        );
        $resolve = new \ReflectionMethod(question_import::class, 'resolve_material_file_references');

        $resolved = $resolve->invoke(
            null,
            '<q><file name="a.png" path="/" material="diagramm.png" encoding="base64"></file></q>',
            \local_coursepilot\material_files::LOCATION_STORE
        );

        $this->assertSame(
            '<q><file name="a.png" path="/" encoding="base64">' . base64_encode($bytes) . '</file></q>',
            $resolved
        );
    }

    /**
     * Omitting the module's optional confirmation never authorizes a new supplied identity.
     */
    public function test_module_default_does_not_confirm_a_supplied_identity(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $result = question_import::import($categoryid, self::xml('Suspect', 'not-yet-present'));
        $this->assertSame([
            'name' => 'Suspect',
            'questionbankentryid' => 0,
            'version' => 0,
            'status' => 'suspect',
            'message' => 'Suspect case: The supplied idnumber "not-yet-present" has no match in the target category. '
                . 'Nothing was imported. To create a new entry anyway, call again with confirmed=true.',
            'idnumber' => 'not-yet-present',
            'categoryid' => $categoryid,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => 'Choose four.',
        ], $result['questions'][0]);
    }

    /**
     * An identity in another category is not a match for the current write.
     */
    public function test_identity_lookup_is_scoped_to_the_target_category(): void {
        $this->resetAfterTest();
        [, $first] = $this->category();
        import_questions_xml::execute($first, self::xml('Existing', 'shared-id'), true);
        [, $second] = $this->category();
        $result = import_questions_xml::execute($second, self::xml('New', 'shared-id'));
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('suspect', $result['questions'][0]['status']);
        $this->assertSame(0, $result['questions'][0]['questionbankentryid']);
        $this->assertSame($second, $result['questions'][0]['categoryid']);
    }

    /**
     * XML identity whitespace does not create a duplicate entry.
     */
    public function test_identity_whitespace_is_normalized_before_matching(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $first = import_questions_xml::execute($categoryid, self::xml('Stable', 'stable-id'), true);
        $again = import_questions_xml::execute($categoryid, self::xml('Stable', '  stable-id  '));
        $again = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $again);
        $this->assertSame('reimport', $again['questions'][0]['status']);
        $this->assertSame($first['questions'][0]['questionbankentryid'], $again['questions'][0]['questionbankentryid']);
        $this->assertSame(2, $again['questions'][0]['version']);
    }

    /**
     * Confirming an identity never authorizes a silently changed question name.
     *
     * @param bool $existing
     */
    #[DataProvider('identity_roundtrip_cases')]
    public function test_confirmed_identity_still_requires_a_lossless_roundtrip(bool $existing): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        if ($existing) {
            import_questions_xml::execute($categoryid, self::xml('Saved name', 'stable-id'), true);
        }
        $before = $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]);
        try {
            import_questions_xml::execute($categoryid, self::xml('', 'stable-id'), true);
            $this->fail('Moodle-generated names must not silently replace the supplied XML name.');
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertStringContainsString('name', $error->getMessage());
        }
        $this->assertSame($before, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
    }

    /**
     * Both the confirmed first import and a new version keep the round-trip guard.
     *
     * @return array<string, array{bool}>
     */
    public static function identity_roundtrip_cases(): array {
        return ['confirmed first import' => [false], 'existing identity' => [true]];
    }

    /**
     * Native XML normalization is accepted, but saving must not discard an answer or invent a name.
     *
     * @param string $search
     * @param string $replacement
     * @param string $field
     */
    #[DataProvider('content_roundtrip_cases')]
    public function test_content_loss_rolls_back_the_entire_import(string $search, string $replacement, string $field): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace($search, $replacement, self::xml('Content contract'));
        if ($field === '') {
            $result = import_questions_xml::execute($categoryid, $xml);
            $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
            $this->assertSame('first_import', $result['questions'][0]['status']);
            $question = get_question::execute($categoryid, 'Content contract');
            $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
            $export = export_questions_xml::execute([$question['questionid']], '', true);
            $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
            $this->assertStringContainsString(strip_tags($search), $export['xml']);
            $this->assertStringNotContainsString(strip_tags($replacement), $export['xml']);
            $this->assertSame(1, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
            return;
        }
        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('Changed question content must not be committed.');
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertStringContainsString('"' . $field . '"', $error->getMessage());
        }
        $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
    }

    /**
     * Core trims text and feedback and drops blank answer options when saving a question.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function content_roundtrip_cases(): array {
        return [
            'question text' => ['Choose four.', ' Choose four. ', ''],
            'general feedback' => ['Compare the numbers.', ' Compare the numbers. ', ''],
            'first answer' => ['<text>4</text>', '<text> 4 </text>', ''],
            'second answer' => ['<text>5</text>', '<text> 5 </text>', ''],
            'first feedback' => ['Correct.', ' Correct. ', ''],
            'second feedback' => ['Too high.', ' Too high. ', ''],
            'blank additional answer' => ['</question>',
                '<answer fraction="0"><text> </text><feedback><text>Blank.</text></feedback></answer></question>', 'answers'],
            'generated name' => ['<text>Content contract</text>', '<text></text>', 'name'],
        ];
    }

    /**
     * The round-trip contract checks every numerical answer, including a later changed representation.
     *
     * @param string $first
     * @param string $second
     */
    #[DataProvider('numerical_roundtrip_cases')]
    public function test_numerical_answer_normalization_requires_a_lossless_roundtrip(string $first, string $second): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace('type="multichoice"', 'type="numerical"', self::xml('Numerical contract'));
        $xml = str_replace(
            ['<text>4</text>', '<text>5</text>'],
            ['<text>' . $first . '</text>', '<text>' . $second . '</text>'],
            $xml
        );
        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('A rewritten numerical answer must not silently replace the supplied text.');
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertStringContainsString('"answers"', $error->getMessage());
        }
        $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
    }

    /**
     * Numerical persistence converts decimal answer strings to their native number representation.
     *
     * @return array<string, array{string, string}>
     */
    public static function numerical_roundtrip_cases(): array {
        return ['first answer changes' => ['1.0', '2'], 'second answer changes' => ['1', '2.0']];
    }

    /**
     * Fractions within native persistence precision do not make a lossless import fail.
     *
     * @param string $first
     * @param string $second
     * @param string[] $expected
     */
    #[DataProvider('fraction_precision_cases')]
    public function test_numerical_fractions_accept_native_persistence_precision(
        string $first,
        string $second,
        array $expected
    ): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $xml = str_replace(
            ['type="multichoice"', 'fraction="100"', 'fraction="0"'],
            ['type="numerical"', 'fraction="' . $first . '"', 'fraction="' . $second . '"'],
            self::xml('Fraction precision')
        );
        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Fraction precision');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], '', true);
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $document = simplexml_load_string($export['xml']);
        $this->assertNotFalse($document);
        $this->assertSame($expected, array_map(
            static fn($answer): string => (string) $answer['fraction'],
            iterator_to_array($document->question->answer, false)
        ));
    }

    /**
     * Native decimal persistence rounds percentages without discarding a boundary value.
     *
     * @return array<string, array{string, string, string[]}>
     */
    public static function fraction_precision_cases(): array {
        return [
            'near full credit' => ['99.9999999', '0.0000001', ['100', '0']],
            'rounding boundary' => ['12.3454999', '100', ['12.3455', '100']],
            'boundary at a higher fraction' => ['32.3454999', '100', ['32.3455', '100']],
        ];
    }

    /**
     * An import without files must not consume an unrelated editor draft belonging to the teacher.
     */
    public function test_import_without_files_leaves_unrelated_editor_drafts_out_of_the_question(): void {
        global $USER;
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $contextid = \context_user::instance($USER->id)->id;
        get_file_storage()->create_directory($contextid, 'user', 'draft', 1, '/');
        $draft = get_file_storage()->create_file_from_string([
            'contextid' => $contextid,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => 1,
            'filepath' => '/', 'filename' => 'private-notes.txt',
        ], 'Private editor notes');

        $result = import_questions_xml::execute($categoryid, self::xml('No files'));
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'No files');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], 'no-files.xml');
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $stored = \local_coursepilot\material_area::read_for_location('workbench', $export['path']);
        $document = simplexml_load_string($stored['content']);
        $this->assertNotFalse($document);
        $this->assertSame([], $document->xpath('//file'));
        $this->assertSame('Private editor notes', $draft->get_content());
    }

    /**
     * Native ordering questions remain importable while their editor form is changed during saving.
     */
    public function test_ordering_import_preserves_the_saved_answer_order(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace(
            ['type="multichoice"', 'Correct.', 'Too high.', '<single>true</single>'],
            ['type="ordering"', '', '', '<shownumcorrect>0</shownumcorrect>'],
            self::xml('Ordering contract')
        );
        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Ordering contract');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], '', true);
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $document = simplexml_load_string($export['xml']);
        $this->assertNotFalse($document);
        $this->assertSame('ordering', (string) $document->question['type']);
        $this->assertSame(['4', '5'], array_map(
            static fn($answer): string => (string) $answer->text,
            iterator_to_array($document->question->answer, false)
        ));
    }

    /**
     * An answer's formatting or feedback is preserved, or the entire write is refused with its cause.
     *
     * @param string $answer
     * @param string $feedback
     */
    #[DataProvider('ordering_content_cases')]
    public function test_ordering_content_is_preserved_or_the_import_is_rolled_back(string $answer, string $feedback): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace(
            ['type="multichoice"', '<text>4</text>', 'Correct.', 'Too high.', '<single>true</single>'],
            ['type="ordering"', '<text><![CDATA[' . $answer . ']]></text>', $feedback, '',
                '<shownumcorrect>0</shownumcorrect>'],
            self::xml('Ordering fidelity')
        );
        try {
            $result = import_questions_xml::execute($categoryid, $xml);
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertStringContainsString('field "answers"', $error->getMessage());
            $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
            return;
        }
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Ordering fidelity');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], '', true);
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $document = simplexml_load_string($export['xml']);
        $this->assertNotFalse($document);
        $this->assertSame($answer, (string) $document->question->answer[0]->text);
        $this->assertSame($feedback, (string) $document->question->answer[0]->feedback->text);
    }

    /**
     * Native persistence must not silently discard an answer paragraph or teacher feedback.
     *
     * @return array<string, array{string, string}>
     */
    public static function ordering_content_cases(): array {
        return ['answer paragraph' => ['<p>4</p>', ''], 'answer feedback' => ['4', 'Teacher feedback.']];
    }

    /**
     * A native formula validation failure retains the specific unsupported function and writes nothing.
     */
    public function test_calculated_formula_failure_keeps_the_native_save_cause(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace(
            ['type="multichoice"', 'Choose four.'],
            ['type="calculated"', '{=coursepilot_unknown_function(1)}'],
            self::xml('Unsupported formula')
        );
        $xml = str_replace('</answer>', '<tolerance>0.01</tolerance><tolerancetype>1</tolerancetype>'
            . '<correctanswerformat>1</correctanswerformat><correctanswerlength>2</correctanswerlength></answer>', $xml);
        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('An unsupported formula function must not create a question.');
        } catch (\invalid_parameter_exception $error) {
            $this->assertStringContainsString('coursepilot_unknown_function', $error->debuginfo);
        }
        $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
    }

    /**
     * Foreign Moodle editing-wizard parameters cannot select a different native save operation.
     */
    public function test_import_rejects_foreign_question_editing_wizard_state(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace('type="multichoice"', 'type="calculated"', self::xml('Wizard boundary', 'wizard-boundary'));
        $xml = str_replace('</answer>', '<tolerance>0.01</tolerance><tolerancetype>1</tolerancetype>'
            . '<correctanswerformat>1</correctanswerformat><correctanswerlength>2</correctanswerlength></answer>', $xml);
        $created = import_questions_xml::execute($categoryid, $xml, true);
        $this->assertSame('first_import', $created['questions'][0]['status']);
        $entryid = $created['questions'][0]['questionbankentryid'];
        $this->assertSame(1, $DB->count_records('question_versions', ['questionbankentryid' => $entryid]));
        $previouspost = $_POST;
        try {
            $_POST['wizardnow'] = 'datasetdefinitions';
            try {
                import_questions_xml::execute($categoryid, $xml);
                $this->fail('Foreign UI state must not silently reuse the existing question version.');
            } catch (\invalid_parameter_exception $error) {
                $this->assertStringContainsString('question editing wizard state', $error->debuginfo);
            }
        } finally {
            $_POST = $previouspost;
        }
        $this->assertSame(1, $DB->count_records('question_versions', ['questionbankentryid' => $entryid]));
    }

    /**
     * Calculated dataset values remain valid XML, or the import reports the native reparse failure atomically.
     */
    public function test_calculated_dataset_export_is_readable_or_the_import_is_rolled_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace(
            ['type="multichoice"', '<text>4</text>', '<text>5</text>'],
            ['type="calculated"', '<text>{a}+{b}</text>', '<text>{a}-{b}</text>'],
            self::xml('Calculated dataset fidelity')
        );
        $xml = str_replace('</answer>', '<tolerance>0.01</tolerance><tolerancetype>1</tolerancetype>'
            . '<correctanswerformat>1</correctanswerformat><correctanswerlength>2</correctanswerlength></answer>', $xml);
        $datasets = '<dataset_definitions><dataset_definition><status><text>private</text></status>'
            . '<name><text>a</text></name><type>calculated</type><distribution><text>uniform</text></distribution>'
            . '<minimum><text>1.0</text></minimum><maximum><text>10.0</text></maximum><decimals><text>1</text></decimals>'
            . '<itemcount>1</itemcount><dataset_items><dataset_item><number>1</number><value>&lt;</value>'
            . '</dataset_item></dataset_items><number_of_items>1</number_of_items></dataset_definition></dataset_definitions>';
        $xml = str_replace('</question>', $datasets . '</question>', $xml);
        try {
            $result = import_questions_xml::execute($categoryid, $xml);
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertStringContainsString('field "parse"', $error->getMessage());
            $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
            return;
        }
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Calculated dataset fidelity');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], '', true);
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $document = simplexml_load_string($export['xml']);
        $this->assertNotFalse($document);
        $this->assertSame('<', (string) $document->question->dataset_definitions
            ->dataset_definition[0]->dataset_items->dataset_item->value);
    }

    /**
     * Feedback line breaks survive writing, or the write is rolled back with its cause.
     *
     * @param string $first
     * @param string $second
     * @param string $expectedfirst
     * @param string $expectedsecond
     */
    #[DataProvider('truefalse_feedback_cases')]
    public function test_truefalse_feedback_is_preserved_or_the_import_is_rolled_back(
        string $first,
        string $second,
        string $expectedfirst,
        string $expectedsecond
    ): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = str_replace(
            ['type="multichoice"', '<text>4</text>', '<text>5</text>', 'Correct.', 'Too high.'],
            ['type="truefalse"', '<text>true</text>', '<text>false</text>', $first, $second],
            self::xml('Truefalse fidelity')
        );
        try {
            $result = import_questions_xml::execute($categoryid, $xml);
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertSame(
                'Round-trip check failed after writing: field "answers" differs from the imported XML. '
                    . 'Nothing was imported, the write was rolled back.',
                $error->getMessage()
            );
            $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
            return;
        }
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Truefalse fidelity');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], '', true);
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $document = simplexml_load_string($export['xml']);
        $this->assertNotFalse($document);
        $this->assertSame($expectedfirst, (string) $document->question->answer[0]->feedback->text);
        $this->assertSame($expectedsecond, (string) $document->question->answer[1]->feedback->text);
    }

    /**
     * Either answer's feedback can contain significant line breaks.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function truefalse_feedback_cases(): array {
        return [
            'true feedback' => ["<![CDATA[First\nSecond]]>", 'Too high.', "First\nSecond", 'Too high.'],
            'false feedback' => ['Correct.', "<![CDATA[First\nSecond]]>", 'Correct.', "First\nSecond"],
        ];
    }

    /**
     * Colliding or XML-sensitive filenames must not silently replace an editor attachment's bytes.
     *
     * @param bool $truefeedback
     * @param string $filename
     */
    #[DataProvider('feedback_attachment_cases')]
    public function test_truefalse_feedback_attachments_are_preserved_or_the_import_is_rolled_back(
        bool $truefeedback,
        string $filename
    ): void {
        global $CFG, $DB, $USER;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $contextid = \context_user::instance($USER->id)->id;
        get_file_storage()->create_directory($contextid, 'user', 'draft', 1, '/');
        $content = 'Original teacher attachment.';
        get_file_storage()->create_file_from_string([
            'contextid' => $contextid, 'component' => 'user', 'filearea' => 'draft', 'itemid' => 1,
            'filepath' => '/', 'filename' => $filename,
        ], $content);
        $feedback = '<text><![CDATA[<a href="' . $CFG->wwwroot . '/draftfile.php/' . $contextid
            . '/user/draft/1/' . rawurlencode($filename) . '">Teacher attachment</a>]]></text>'
            . '<file name="' . htmlspecialchars($filename, ENT_QUOTES | ENT_XML1) . '" path="/" encoding="base64">Yg==</file>';
        $xml = str_replace(
            ['type="multichoice"', '<text>4</text>', '<text>5</text>',
                '<text>' . ($truefeedback ? 'Correct.' : 'Too high.') . '</text>'],
            ['type="truefalse"', '<text>true</text>', '<text>false</text>', $feedback],
            self::xml('Feedback attachments')
        );
        \local_coursepilot\material_area::write('feedback.txt', $xml);
        try {
            $result = import_questions_xml::execute($categoryid, '', false, 'feedback.txt', 'workbench');
        } catch (\moodle_exception $error) {
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertSame(
                'Round-trip check failed after writing: field "answers" differs from the imported XML. '
                    . 'Nothing was imported, the write was rolled back.',
                $error->getMessage()
            );
            $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
            return;
        }
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Feedback attachments');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], 'feedback-export.xml');
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $stored = \local_coursepilot\material_area::read_for_location('workbench', $export['path']);
        $document = simplexml_load_string($stored['content']);
        $this->assertNotFalse($document);
        $savedfeedback = $document->question->answer[$truefeedback ? 0 : 1]->feedback;
        $this->assertSame(1, preg_match('/href="@@PLUGINFILE@@\/([^"]+)"/', (string) $savedfeedback->text, $match));
        $files = array_values(array_filter(
            iterator_to_array($savedfeedback->file, false),
            static fn($file): bool => ltrim((string) $file['path'], '/') . (string) $file['name'] === rawurldecode($match[1])
        ));
        $this->assertCount(1, $files);
        $this->assertSame($content, base64_decode((string) $files[0], true));
    }

    /**
     * The attachment can belong to either feedback option.
     *
     * @return array<string, array{bool, string}>
     */
    public static function feedback_attachment_cases(): array {
        return [
            'true feedback' => [true, 'large.txt'],
            'false feedback' => [false, 'large.txt'],
            'normalized true attachment filename' => [true, 'a&b.txt'],
            'normalized false attachment filename' => [false, 'a&b.txt'],
        ];
    }

    /**
     * An embedded answer remains answerable, or the complete native reparse cause explains the rollback.
     */
    public function test_cloze_answer_is_preserved_or_the_native_reparse_cause_is_reported(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        [, $categoryid] = $this->category();
        $xml = '<quiz><question type="cloze"><name><text>Cloze fidelity</text></name>'
            . '<questiontext format="html"><text><![CDATA[Choose {1:SHORTANSWER:=four#Correct.~five#No.}.]]></text>'
            . '</questiontext><generalfeedback format="html"><text>Use the word.</text></generalfeedback>'
            . '</question></quiz>';
        try {
            $result = import_questions_xml::execute($categoryid, $xml);
        } catch (\moodle_exception $error) {
            $missing = get_string('questionsmissing', 'qtype_multianswer');
            $cause = get_string('importerrorquestion', 'question') . ' '
                . get_string('invalidmultianswerquestion', 'qtype_multianswer', $missing);
            $this->assertSame('roundtripmismatch', $error->errorcode);
            $this->assertSame(
                'Round-trip check failed after writing: field "parse" differs from the imported XML (' . $cause . '). '
                    . 'Nothing was imported, the write was rolled back.',
                $error->getMessage()
            );
            $this->assertSame(0, $DB->count_records('question_bank_entries', ['questioncategoryid' => $categoryid]));
            return;
        }
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Cloze fidelity');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $definition = \question_bank::load_question($question['questionid']);
        $this->assertContains('four', array_values($definition->get_correct_response()));
    }

    /**
     * Every imported question has a complete result and the documented generated identity.
     */
    public function test_batch_results_keep_every_question_message_and_identity(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $xml = str_replace('</quiz>', '', self::xml('First'))
            . str_replace('<quiz>', '', self::xml('Second'));
        $raw = question_import::import($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $raw);
        $this->assertSame(['First', 'Second'], array_column($result['questions'], 'name'));
        foreach ($raw['questions'] as $question) {
            $this->assertSame([], $question['candidates']);
            $this->assertSame('', $question['idnumber']);
            $this->assertSame(0, $question['categoryid']);
            $this->assertSame('', $question['questiontext_old']);
            $this->assertSame('', $question['questiontext_new']);
            $this->assertSame('Question "' . $question['name'] . '" created (version 1).', $question['message']);
            $read = get_question::execute($categoryid, $question['name']);
            $read = external_api::clean_returnvalue(get_question::execute_returns(), $read);
            $export = export_questions_xml::execute([$read['questionid']], '', true);
            $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
            $this->assertMatchesRegularExpression('/<idnumber>kp-[a-f0-9]{16}<\/idnumber>/', $export['xml']);
        }
    }

    /**
     * A second write names both the question and its new version.
     */
    public function test_reimport_reports_the_new_version(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        import_questions_xml::execute($categoryid, self::xml('Stable', 'stable-id'), true);
        $result = import_questions_xml::execute($categoryid, self::xml('Stable', 'stable-id'));
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('reimport', $result['questions'][0]['status']);
        $this->assertSame(2, $result['questions'][0]['version']);
        $this->assertSame(
            'Question "Stable" imported as a new version (version 2) of the same bank entry.',
            $result['questions'][0]['message']
        );
    }

    /**
     * XML marks, penalties and non-HTML formats survive the complete public import/export path.
     */
    public function test_import_preserves_marks_penalty_and_markdown_formats(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $xml = str_replace('format="html"', 'format="markdown"', self::xml('Markdown contract'));
        $xml = str_replace('<single>', '<defaultgrade>7</defaultgrade><penalty>0.25</penalty><single>', $xml);
        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $question = get_question::execute($categoryid, 'Markdown contract');
        $question = external_api::clean_returnvalue(get_question::execute_returns(), $question);
        $export = export_questions_xml::execute([$question['questionid']], '', true);
        $export = external_api::clean_returnvalue(export_questions_xml::execute_returns(), $export);
        $document = simplexml_load_string($export['xml']);
        $this->assertNotFalse($document);
        $this->assertEquals(7, (float) $document->question->defaultgrade);
        $this->assertEquals(0.25, (float) $document->question->penalty);
        $this->assertSame('markdown', (string) $document->question->questiontext['format']);
        $this->assertSame('markdown', (string) $document->question->generalfeedback['format']);
    }

    /**
     * Invalid input explains exactly which door or wrapper must be corrected.
     *
     * @param string $xml
     * @param string $path
     * @param string $message
     */
    #[DataProvider('invalid_inputs')]
    public function test_input_failure_keeps_the_complete_guidance(string $xml, string $path, string $message): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        try {
            import_questions_xml::execute($categoryid, $xml, false, $path);
            $this->fail('Invalid input must be rejected.');
        } catch (\invalid_parameter_exception $error) {
            $this->assertSame($message, $error->debuginfo);
        }
    }

    /**
     * Documented input failures, including whitespace-only unused doors.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function invalid_inputs(): array {
        $neither = 'Neither xmlcontent nor xmlpath supplied - choose exactly one input: XML as text (xmlcontent) or '
            . 'a reference to an XML file in the material store (xmlpath).';
        $wrapper = 'The XML lacks the enclosing <quiz> element. Moodle question XML always consists of <quiz> with one '
            . 'or more <question> blocks inside it - a single <question> block cannot be '
            . 'imported. Send the complete Moodle export or wrap the questions in <quiz>...</quiz> instead.';
        return [
            'neither input' => ['', '', $neither],
            'whitespace inputs' => [" \n ", '  ', $neither],
            'two inputs' => ['<quiz/>', 'source.xml',
                'xmlcontent and xmlpath cannot both be supplied - choose exactly one input: '
                    . 'XML as text (xmlcontent) or a reference to an XML file in the material store (xmlpath).'],
            'missing wrapper' => ['<question/>', '', $wrapper],
            'uppercase wrapper' => [str_replace(['<quiz>', '</quiz>'], ['<QUIZ>', '</QUIZ>'], self::xml('Wrapper')),
                '', $wrapper],
        ];
    }

    /**
     * The size error keeps the actual size, limit and complete recovery instruction.
     */
    public function test_oversized_input_keeps_the_complete_guidance(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        try {
            import_questions_xml::execute($categoryid, str_repeat('x', 10 * 1024 * 1024));
            $this->fail('Oversized input must be rejected.');
        } catch (\invalid_parameter_exception $error) {
            $this->assertSame(
                'The XML is too large (10.0 MB, limit 5.0 MB). Split the import across smaller files - e.g. one file per '
                    . 'question category.',
                $error->debuginfo
            );
        }
    }

    /**
     * XML at the effective byte ceiling remains importable.
     */
    public function test_input_at_the_exact_byte_ceiling_is_imported(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $serverlimit = get_max_upload_file_size();
        $limit = $serverlimit > 0 ? min(question_import::MAX_XML_BYTES, $serverlimit) : question_import::MAX_XML_BYTES;
        $xml = self::xml('Exact size');
        $padding = '<!--' . str_repeat('x', $limit - strlen($xml) - 7) . '-->';
        $xml = str_replace('</quiz>', $padding . '</quiz>', $xml);
        $this->assertSame($limit, strlen($xml));

        $result = import_questions_xml::execute($categoryid, $xml);
        $result = external_api::clean_returnvalue(import_questions_xml::execute_returns(), $result);
        $this->assertSame('first_import', $result['questions'][0]['status']);
        $this->assertSame('Exact size', $result['questions'][0]['name']);
    }

    /**
     * Unknown question types report the parser cause rather than an empty import.
     */
    public function test_unsupported_question_type_keeps_the_native_parse_cause(): void {
        $this->resetAfterTest();
        [, $categoryid] = $this->category();
        $xml = str_replace('type="multichoice"', 'type="coursepilot_missing_type"', self::xml('Unknown type'));
        try {
            import_questions_xml::execute($categoryid, $xml);
            $this->fail('An unsupported question type must be refused.');
        } catch (\invalid_parameter_exception $error) {
            $this->assertStringStartsWith('Invalid Moodle XML:', $error->debuginfo);
            $this->assertStringContainsString('coursepilot_missing_type', $error->debuginfo);
        }
    }

    /**
     * A real teacher with a native question bank and category.
     *
     * @return array{\stdClass, int}
     */
    private function category(): array {
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $bank = ensure_question_bank::execute($course->id, 'Import contract');
        $bank = external_api::clean_returnvalue(ensure_question_bank::execute_returns(), $bank);
        $category = ensure_question_category::execute('Import', (int) $bank['topcategoryid']);
        $category = external_api::clean_returnvalue(ensure_question_category::execute_returns(), $category);
        return [$course, (int) $category['id']];
    }

    /**
     * One native multichoice question with distinct answers and feedback.
     *
     * @param string $name
     * @param string $idnumber
     * @return string
     */
    private static function xml(string $name, string $idnumber = ''): string {
        return '<quiz><question type="multichoice"><name><text>' . $name . '</text></name>'
            . '<idnumber>' . $idnumber . '</idnumber><questiontext format="html"><text>Choose four.</text></questiontext>'
            . '<generalfeedback format="html"><text>Compare the numbers.</text></generalfeedback><single>true</single>'
            . '<answer fraction="100"><text>4</text><feedback><text>Correct.</text></feedback></answer>'
            . '<answer fraction="0"><text>5</text><feedback><text>Too high.</text></feedback></answer>'
            . '</question></quiz>';
    }
}
