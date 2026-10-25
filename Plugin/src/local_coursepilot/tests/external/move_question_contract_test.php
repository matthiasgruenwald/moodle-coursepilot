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
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exact responses, messages and the order of the checks of move_question (ADR 0015, Spec 0017 §7.1, #414).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(move_question::class)]
#[CoversClass(\local_coursepilot\question_suspect_gate::class)]
final class move_question_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var \stdClass Course with the banks. */
    private \stdClass $course;

    /** @var int[] Category ids by name. */
    private array $categories = [];

    /** @var \core_question_generator Question generator. */
    private \core_question_generator $generator;

    /**
     * Course, editing teacher, one bank with the categories Source and Target, and a second bank with Elsewhere.
     */
    private function setup_banks(): void {
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        /** @var \core_question_generator $questiongen */
        $questiongen = $this->getDataGenerator()->get_plugin_generator('core_question');
        $this->generator = $questiongen;

        $bank = ensure_question_bank::execute($this->course->id, 'First bank');
        foreach (['Source', 'Target'] as $name) {
            $this->categories[$name] = (int) ensure_question_category::execute($name, (int) $bank['topcategoryid'])['id'];
        }
        $second = ensure_question_bank::execute($this->course->id, 'Second bank');
        $this->categories['Elsewhere'] = (int) ensure_question_category::execute('Elsewhere', (int) $second['topcategoryid'])['id'];
    }

    /**
     * Creates a true/false question.
     *
     * @param string $category Category name.
     * @param string $name
     * @param string|null $idnumber
     * @return \stdClass
     */
    private function question(string $category, string $name, ?string $idnumber = null): \stdClass {
        $record = ['category' => $this->categories[$category], 'name' => $name];
        if ($idnumber !== null) {
            $record['idnumber'] = $idnumber;
        }
        return $this->generator->create_question('truefalse', null, $record);
    }

    /**
     * Bank entry id of a question.
     *
     * @param \stdClass $question
     * @return int
     */
    private function entry_of(\stdClass $question): int {
        global $DB;
        return (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $question->id], MUST_EXIST);
    }

    /**
     * Category id a question currently sits in.
     *
     * @param \stdClass $question
     * @return int
     */
    private function category_of(\stdClass $question): int {
        global $DB;
        return (int) $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $this->entry_of($question)]);
    }

    public function test_moved_response_is_exact_and_lists_every_version_in_order(): void {
        $this->setup_banks();
        $other = $this->question('Source', 'Other');
        $moving = $this->question('Source', 'Moving', 'm-1');
        $second = $this->generator->update_question($moving, null, ['category' => $this->categories['Source']]);

        $result = move_question::execute((int) $second->id, $this->categories['Target']);

        $this->assertSame([
            'status' => 'moved',
            'questionbankentryid' => $this->entry_of($moving),
            'versionids' => [(int) $moving->id, (int) $second->id],
            'idnumber_disambiguated' => false,
            'message' => 'Question moved to the target category.',
            'idnumber' => '',
            'categoryid' => 0,
            'candidates' => [],
            'questiontext_old' => '',
            'questiontext_new' => '',
        ], $result);
        $this->assertSame($this->categories['Target'], $this->category_of($moving));
        $this->assertSame($this->categories['Source'], $this->category_of($other), 'Other questions stay.');
    }

    public function test_the_chosen_question_moves_and_not_the_first_one_of_the_bank(): void {
        $this->setup_banks();
        $first = $this->question('Source', 'First');
        $chosen = $this->question('Source', 'Chosen');
        $chosen = $this->generator->update_question(
            $chosen,
            null,
            ['category' => $this->categories['Source'], 'name' => 'Chosen v2']
        );

        move_question::execute((int) $chosen->id, $this->categories['Target']);

        $this->assertSame($this->categories['Source'], $this->category_of($first));
        $this->assertSame($this->categories['Target'], $this->category_of($chosen));
    }

    public function test_suspect_response_is_exact_and_names_the_latest_text_of_the_moving_question(): void {
        global $DB;
        $this->setup_banks();
        $this->question('Source', 'Unrelated');
        $moving = $this->question('Source', 'Moving', 'dup-1');
        $newest = $this->generator->update_question($moving, null, [
            'category' => $this->categories['Source'], 'questiontext' => ['text' => 'Newest text', 'format' => FORMAT_HTML],
        ]);
        $existing = $this->question('Target', 'Existing', 'dup-1');
        $DB->set_field('question', 'questiontext', 'Existing text', ['id' => $existing->id]);

        $result = move_question::execute((int) $moving->id, $this->categories['Target']);

        $this->assertSame([
            'status' => 'suspect',
            'questionbankentryid' => $this->entry_of($moving),
            'versionids' => [],
            'idnumber_disambiguated' => false,
            'message' => 'Suspect case: the target category already has an entry with the idnumber "dup-1". '
                . 'Nothing was moved. To move despite the collision, call again with confirmed=true.',
            'idnumber' => 'dup-1',
            'categoryid' => $this->categories['Target'],
            'candidates' => [['questionid' => (int) $existing->id, 'name' => 'Existing', 'idnumber' => 'dup-1']],
            'questiontext_old' => 'Existing text',
            'questiontext_new' => 'Newest text',
        ], $result);
        $this->assertSame($this->categories['Source'], $this->category_of($moving));
    }

    public function test_confirmed_move_reports_the_renamed_idnumber(): void {
        global $DB;
        $this->setup_banks();
        $moving = $this->question('Source', 'Moving', 'dup-2');
        $this->question('Target', 'Existing', 'dup-2');

        $result = move_question::execute((int) $moving->id, $this->categories['Target'], true);

        $renamed = $DB->get_field('question_bank_entries', 'idnumber', ['id' => $this->entry_of($moving)]);
        $this->assertNotSame('dup-2', $renamed);
        $this->assertTrue($result['idnumber_disambiguated']);
        $this->assertSame(
            'Question moved to the target category. The idnumber "dup-2" was already taken in the target category '
                . 'and was renamed to "' . $renamed . '".',
            $result['message']
        );
    }

    public function test_a_question_without_idnumber_moves_without_a_collision_check(): void {
        $this->setup_banks();
        $moving = $this->question('Source', 'Moving');
        $this->question('Target', 'Existing');

        $result = move_question::execute((int) $moving->id, $this->categories['Target']);

        $this->assertSame('moved', $result['status']);
        $this->assertFalse($result['idnumber_disambiguated']);
    }

    public function test_the_context_restriction_covers_the_source_bank_before_a_collision_is_reported(): void {
        $this->setup_banks();
        $moving = $this->question('Source', 'Moving', 'r-1');
        $this->question('Target', 'Existing', 'r-1');
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $e = $this->caught(fn() => move_question::execute((int) $moving->id, $this->categories['Target']));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
        $this->assertSame($this->categories['Source'], $this->category_of($moving));
    }

    public function test_the_context_restriction_covers_a_different_target_bank_before_a_collision_is_reported(): void {
        global $DB;
        $this->setup_banks();
        $moving = $this->question('Source', 'Moving', 'r-2');
        $this->question('Elsewhere', 'Existing', 'r-2');
        $this->question('Target', 'Existing', 'r-2');
        $sourcecontext = \context::instance_by_id(
            $DB->get_field('question_categories', 'contextid', ['id' => $this->categories['Source']])
        );

        external_api::set_context_restriction($sourcecontext);
        try {
            $e = $this->caught(fn() => move_question::execute((int) $moving->id, $this->categories['Elsewhere']));
            $same = $this->caught(fn() => move_question::execute((int) $moving->id, $this->categories['Target']));
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
        $this->assertNull($same, 'A target in the restricted bank itself is fine.');
    }

    public function test_each_capability_is_checked_before_the_collision_is_reported(): void {
        global $DB;
        $this->setup_banks();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $sourcecontext = \context::instance_by_id(
            $DB->get_field('question_categories', 'contextid', ['id' => $this->categories['Source']])
        );
        $elsewherecontext = \context::instance_by_id(
            $DB->get_field('question_categories', 'contextid', ['id' => $this->categories['Elsewhere']])
        );
        $moving = $this->question('Source', 'Moving', 'cap-1');
        $this->question('Elsewhere', 'Existing', 'cap-1');
        $this->question('Target', 'Existing', 'cap-1');

        // The plugin capability in the source bank; the target bank is another one.
        assign_capability('local/coursepilot:use', CAP_PROHIBIT, $roleid, $sourcecontext->id, true);
        $e = $this->caught(fn() => move_question::execute((int) $moving->id, $this->categories['Elsewhere']));
        $this->assertInstanceOf(\required_capability_exception::class, $e, 'source');
        assign_capability('local/coursepilot:use', CAP_INHERIT, $roleid, $sourcecontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        // The plugin capability in the target bank only.
        assign_capability('local/coursepilot:use', CAP_PROHIBIT, $roleid, $elsewherecontext->id, true);
        $e = $this->caught(fn() => move_question::execute((int) $moving->id, $this->categories['Elsewhere']));
        $this->assertInstanceOf(\required_capability_exception::class, $e, 'target');
        assign_capability('local/coursepilot:use', CAP_INHERIT, $roleid, $elsewherecontext->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        // The native right to move the question.
        foreach (['moodle/question:movemine', 'moodle/question:moveall'] as $capability) {
            assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
            accesslib_clear_all_caches_for_unit_testing();
        }
        $e = $this->caught(fn() => move_question::execute((int) $moving->id, $this->categories['Target']));
        $this->assertInstanceOf(\moodle_exception::class, $e, 'move');
        $this->assertSame('nopermissions', $e->errorcode);
    }

    public function test_the_native_move_right_is_checked_for_every_version(): void {
        global $DB;
        $this->setup_banks();
        $moving = $this->question('Source', 'Moving', 'ver-1');
        $this->question('Target', 'Existing', 'ver-1');
        $second = $this->generator->update_question($moving, null, ['category' => $this->categories['Source']]);
        $DB->set_field('question', 'createdby', $this->getDataGenerator()->create_user()->id, ['id' => $moving->id]);
        $DB->set_field('question', 'createdby', $this->getDataGenerator()->create_user()->id, ['id' => $second->id]);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/question:moveall', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);

        $e = $this->caught(fn() => move_question::execute((int) $second->id, $this->categories['Target']));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('nopermissions', $e->errorcode);
    }
}
