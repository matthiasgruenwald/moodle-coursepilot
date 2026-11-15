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

global $CFG;
require_once($CFG->dirroot . '/question/engine/bank.php');

/**
 * Defaults and text normalisation of the question import writer (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(question_import::class)]
final class question_import_defaults_674_contract_test extends \advanced_testcase {
    /**
     * Calls a private static method of the writer.
     *
     * @param string $method Method name.
     * @param mixed ...$args Arguments.
     * @return mixed
     */
    private function call(string $method, ...$args) {
        $m = new \ReflectionMethod(question_import::class, $method);
        return $m->invoke(null, ...$args);
    }

    /**
     * Saves a minimal true/false question through the private save() and returns its stored row.
     *
     * @param \stdClass $overrides Properties merged into the question.
     * @return \stdClass Stored question row.
     */
    private function saved_row(\stdClass $overrides): \stdClass {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $context = \context::instance_by_id($category->contextid);
        $question = (object) [
            'qtype' => 'truefalse',
            'name' => 'Defaults',
            'questiontext' => 'Q',
            'questiontextformat' => FORMAT_HTML,
            'generalfeedback' => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'correctanswer' => 1,
            'feedbacktrue' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
            'feedbackfalse' => ['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0],
        ];
        foreach ((array) $overrides as $key => $value) {
            $question->$key = $value;
        }
        $saved = $this->call('save', $category, $context, $question, null, 'ID-674');
        return $DB->get_record('question', ['id' => $saved->id], '*', MUST_EXIST);
    }

    public function test_the_default_mark_is_one_when_neither_mark_field_is_present(): void {
        $row = $this->saved_row(new \stdClass());
        $this->assertEqualsWithDelta(1.0, (float) $row->defaultmark, 0.0000001);
    }

    public function test_a_legacy_defaultgrade_becomes_the_default_mark(): void {
        $row = $this->saved_row((object) ['defaultgrade' => 2.5]);
        $this->assertEqualsWithDelta(2.5, (float) $row->defaultmark, 0.0000001);
    }

    public function test_the_penalty_is_zero_when_the_question_names_none(): void {
        $row = $this->saved_row(new \stdClass());
        $this->assertEqualsWithDelta(0.0, (float) $row->penalty, 0.0000001);
    }

    public function test_a_text_array_keeps_its_own_format_and_itemid_and_casts_the_text(): void {
        $result = $this->call('as_text_array', ['text' => 5, 'format' => FORMAT_PLAIN, 'itemid' => 7], FORMAT_HTML, 3);
        $this->assertSame(['text' => '5', 'format' => FORMAT_PLAIN, 'itemid' => 7], $result);
    }

    public function test_a_text_array_without_format_and_itemid_takes_the_given_ones(): void {
        $result = $this->call('as_text_array', ['text' => 'a'], FORMAT_PLAIN, 3);
        $this->assertSame(['text' => 'a', 'format' => FORMAT_PLAIN, 'itemid' => 3], $result);
    }

    /**
     * Runs reparse() on the given XML and returns the thrown round-trip exception plus the output-buffer level change.
     *
     * @param string $xml Wrapped quiz XML.
     * @return array{0: \moodle_exception, 1: int}
     */
    private function reparse_failure(string $xml): array {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $context = \context::instance_by_id($category->contextid);
        $level = ob_get_level();
        try {
            $this->call('reparse', $category, $context, $xml);
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\moodle_exception::class, $e);
            return [$e, ob_get_level() - $level];
        }
        $this->fail('A moodle_exception was expected.');
    }

    public function test_a_reader_exception_during_reparse_is_a_round_trip_parse_error_and_closes_the_buffer(): void {
        [$e, $leak] = $this->reparse_failure('<?xml version="1.0" encoding="UTF-8"?><quiz></quiz>');

        $this->assertSame('roundtripmismatch', $e->errorcode);
        $this->assertSame('parse', $e->a->field);
        $this->assertStringStartsWith(' (', $e->a->detail);
        $this->assertSame(0, $leak);
    }

    public function test_a_reader_exception_during_parse_is_an_invalid_parameter_and_closes_the_buffer(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $context = \context::instance_by_id($category->contextid);
        $level = ob_get_level();

        try {
            $this->call('parse', $category, $context, '<?xml version="1.0" encoding="UTF-8"?><quiz></quiz>');
            $this->fail('An invalid_parameter_exception was expected.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertSame($level, ob_get_level());
        }
    }
}
