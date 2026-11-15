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
 * The shared suspected-collision gate of the question tools (ADR 0015, Spec 0017 §7.1, #414).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(question_suspect_gate::class)]
final class question_suspect_gate_test extends \advanced_testcase {
    public function test_empty_result_has_the_fixed_response_shape(): void {
        $this->assertSame([
            'idnumber' => '',
            'categoryid' => 0,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => '',
        ], question_suspect_gate::empty_result());
    }

    public function test_response_fields_default_to_the_empty_result(): void {
        $fields = question_suspect_gate::response_fields();

        $this->assertSame(['idnumber', 'categoryid', 'candidates', 'questiontext_old', 'questiontext_new'], array_keys($fields));
        $this->assertSame('', $fields['idnumber']->default);
        $this->assertSame(0, $fields['categoryid']->default);
        $this->assertSame([], $fields['candidates']->default);
        $this->assertSame('', $fields['questiontext_old']->default);
        $this->assertSame('', $fields['questiontext_new']->default);
        /** @var \core_external\external_value $category */
        $category = $fields['categoryid'];
        $this->assertSame(PARAM_INT, $category->type);
        $this->assertSame(VALUE_DEFAULT, $category->required);
    }

    public function test_response_converts_the_collision_to_the_published_types(): void {
        $collision = (object) ['idnumber' => 123, 'questionid' => '7', 'name' => 456, 'questiontext' => 789];

        $this->assertSame([
            'idnumber' => '123',
            'categoryid' => 5,
            'candidates' => [['questionid' => 7, 'name' => '456', 'idnumber' => '123']],
            'questiontext_old' => '789',
            'questiontext_new' => 'New text',
        ], question_suspect_gate::response($collision, 5, 'New text'));
    }

    /**
     * A category with two questions: "Alpha" (idnumber A-1, two versions) and "Beta" (idnumber B-1).
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass} Category, Alpha question (version 1), Beta question.
     */
    private function bank(): array {
        global $DB;
        $this->resetAfterTest();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $alpha = $generator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Alpha']);
        $beta = $generator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Beta']);
        $DB->set_field('question_bank_entries', 'idnumber', 'A-1', ['id' => $this->entry_id($alpha)]);
        $DB->set_field('question_bank_entries', 'idnumber', 'B-1', ['id' => $this->entry_id($beta)]);

        return [$category, $alpha, $beta];
    }

    /**
     * Bank entry of a question.
     *
     * @param \stdClass $question
     * @return int
     */
    private function entry_id(\stdClass $question): int {
        global $DB;
        return (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id], MUST_EXIST);
    }

    public function test_collision_is_the_newest_version_of_the_entry_with_the_idnumber(): void {
        global $DB;
        [$category, $alpha] = $this->bank();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $newer = $generator->update_question($alpha, null, ['name' => 'Alpha v2', 'questiontext' => ['text' => 'Second text']]);
        $generator->update_question($newer, null, ['name' => 'Alpha v3', 'questiontext' => ['text' => 'Third text']]);
        // A new version written through the generator starts without an idnumber.
        $DB->set_field('question_bank_entries', 'idnumber', 'A-1', ['id' => $this->entry_id($alpha)]);

        $found = question_suspect_gate::find_idnumber_collision((int) $category->id, 'A-1', 0);

        $this->assertNotNull($found);
        $this->assertSame('Alpha v3', $found->name);
        $this->assertSame('A-1', $found->idnumber);
        $this->assertSame((string) $DB->get_field('question', 'id', ['name' => 'Alpha v3']), (string) $found->questionid);
        $this->assertSame('Third text', $found->questiontext);
    }

    public function test_no_collision_without_a_match_an_idnumber_or_with_the_entry_itself_excluded(): void {
        [$category, $alpha, $beta] = $this->bank();

        $this->assertNull(question_suspect_gate::find_idnumber_collision((int) $category->id, '', 0));
        $this->assertNull(question_suspect_gate::find_idnumber_collision((int) $category->id, 'unknown', 0));
        $this->assertNull(question_suspect_gate::find_idnumber_collision((int) $category->id + 1000, 'A-1', 0));
        $this->assertNull(question_suspect_gate::find_idnumber_collision((int) $category->id, 'A-1', $this->entry_id($alpha)));
        $this->assertSame(
            'Beta',
            question_suspect_gate::find_idnumber_collision((int) $category->id, 'B-1', $this->entry_id($alpha))->name
        );
    }

    public function test_name_candidates_list_every_entry_of_that_name_with_published_types(): void {
        global $DB;
        [$category, $alpha] = $this->bank();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $twin = $generator->create_question('truefalse', null, ['category' => $category->id, 'name' => 'Alpha']);
        $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $this->entry_id($twin)]);

        $candidates = question_suspect_gate::find_name_candidates((int) $category->id, 'Alpha');

        usort($candidates, static fn(array $a, array $b): int => $a['questionid'] <=> $b['questionid']);
        $this->assertSame([
            ['questionid' => (int) $alpha->id, 'name' => 'Alpha', 'idnumber' => 'A-1'],
            ['questionid' => (int) $twin->id, 'name' => 'Alpha', 'idnumber' => ''],
        ], $candidates);
        $this->assertSame([], question_suspect_gate::find_name_candidates((int) $category->id, 'Gamma'));
    }

    public function test_name_candidates_report_the_newest_version_of_each_entry(): void {
        global $DB;
        [$category, $alpha] = $this->bank();
        /** @var \core_question_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $newer = $generator->update_question($alpha, null, ['name' => 'Alpha']);
        // A new version written through the generator starts without an idnumber.
        $DB->set_field('question_bank_entries', 'idnumber', 'A-1', ['id' => $this->entry_id($alpha)]);

        $candidates = question_suspect_gate::find_name_candidates((int) $category->id, 'Alpha');

        $this->assertSame([['questionid' => (int) $newer->id, 'name' => 'Alpha', 'idnumber' => 'A-1']], $candidates);
    }
}
