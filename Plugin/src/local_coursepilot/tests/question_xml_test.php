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

/**
 * Shared question rules used by the creation and patch tools.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(question_xml::class)]
final class question_xml_test extends \advanced_testcase {
    /**
     * Reject weights outside the multiple-choice range.
     */
    public function test_rejects_fraction_above_one(): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage('fraction must be between -1 and 1.');
        question_xml::validate_answers([
            ['answer' => 'Correct', 'fraction' => 1.1],
            ['answer' => 'Other', 'fraction' => 0],
        ], 'single');
    }
    /**
     * Invalid selection and weight sets fail before a question can be written.
     *
     * @param mixed[] $answers
     * @param string $mode
     * @param string $message
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid_answer_sets')]
    public function test_rejects_invalid_answer_sets(array $answers, string $mode, string $message): void {
        $this->expectException(\invalid_parameter_exception::class);
        $this->expectExceptionMessage($message);
        question_xml::validate_answers($answers, $mode);
    }

    /**
     * Answer sets rejected by the published multiple-choice rules.
     *
     * @return array<string, array{0: mixed[], 1: string, 2: string}>
     */
    public static function invalid_answer_sets(): array {
        return [
            'too few answers' => [[['fraction' => 1]], 'single', 'at least 2 answers'],
            'unknown mode' => [[['fraction' => 1], ['fraction' => 0]], 'other', 'single or multiple'],
            'negative weight out of range' => [[['fraction' => 1], ['fraction' => -1.1]], 'single', 'between -1 and 1'],
            'two correct single answers' => [[['fraction' => 0.5], ['fraction' => 0.5]], 'single', 'exactly one'],
            'no correct multiple answer' => [[['fraction' => 0], ['fraction' => -1]], 'multiple', 'positive fraction'],
            'incomplete positive total' => [[['fraction' => 0.5], ['fraction' => 0]], 'multiple', 'sum to exactly 1'],
            'rounded excessive total' => [[['fraction' => 0.51], ['fraction' => 0.5]], 'multiple',
                'Positive fraction values must sum to exactly 1 (currently 1.01).'],
        ];
    }

    /**
     * A missing question cannot resolve to an unrelated existing bank entry.
     */
    public function test_missing_question_keeps_its_identity_in_the_failure(): void {
        $this->resetAfterTest();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $generator->create_question('multichoice', null, ['category' => $category->id]);
        try {
            question_xml::resolve_native_question(PHP_INT_MAX);
            $this->fail('An unrelated question must not satisfy this lookup.');
        } catch (\moodle_exception $error) {
            $this->assertSame('No question with questionid ' . PHP_INT_MAX . ' found.', $error->debuginfo);
        }
    }

    /**
     * The default serialization removes bytes and keeps an XML-safe, named trace.
     */
    public function test_default_export_replaces_embedded_file_with_a_safe_named_placeholder(): void {
        $this->resetAfterTest();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $othercategory = $generator->create_question_category();
        $generator->create_question('multichoice', null, ['category' => $othercategory->id]);
        $category = $generator->create_question_category();
        $created = $generator->create_question('multichoice', null, ['category' => $category->id]);
        [$question, $resolvedcategory, $context] = question_xml::resolve_native_question((int) $created->id);
        $this->assertSame((int) $created->id, (int) $question->id);
        $this->assertSame((int) $category->id, (int) $resolvedcategory->id);
        $this->assertSame('', $question->idnumber);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'question',
            'filearea' => 'questiontext',
            'itemid' => $question->id,
            'filepath' => '/',
            'filename' => 'a--b.png',
        ], 'image bytes');
        [$xml, $files] = question_xml::question_to_xml($question, $category, $context);
        $this->assertSame(['a--b.png'], $files);
        $this->assertStringContainsString(
            "<!-- File removed (no binary transport in export): a- -b.png -->\n",
            $xml
        );
        $this->assertStringNotContainsString(base64_encode('image bytes'), $xml);
        $this->assertNotFalse(simplexml_load_string('<quiz>' . $xml . '</quiz>'));
    }

    /**
     * Calculated exports retain the native datasets needed to answer their formulas.
     */
    public function test_calculated_export_retains_dataset_definitions_and_values(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $created = $generator->create_question('calculated', 'sum', ['category' => $category->id]);
        /** @var \qtype_calculated $qtype */
        $qtype = \question_bank::get_qtype('calculated');
        $qtype->import_datasets((object) [
            'id' => $created->id,
            'category' => $category->id,
            'dataset' => [(object) [
                'name' => 'a', 'distribution' => 'uniform', 'min' => '1', 'max' => '10',
                'length' => '0', 'itemcount' => 1, 'status' => 'private',
                'datasetitem' => [(object) ['itemnumber' => 1, 'value' => 3]],
            ]],
        ]);
        $result = \local_coursepilot\external\export_questions_xml::execute([(int) $created->id], '', true);
        $result = \core_external\external_api::clean_returnvalue(
            \local_coursepilot\external\export_questions_xml::execute_returns(),
            $result
        );
        $xml = simplexml_load_string($result['xml']);
        $this->assertNotFalse($xml);
        $datasets = $xml->xpath('//dataset_definition[name/text="a"]');
        $this->assertCount(1, $datasets);
        $dataset = $datasets[0];
        $this->assertSame('a', (string) $dataset->name->text);
        $this->assertSame('3', (string) $dataset->dataset_items->dataset_item->value);
    }
}
