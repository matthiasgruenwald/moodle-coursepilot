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
        ];
    }
}
