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
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Exact error parameters, ordering of the checks and messages of set_restriction.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(set_restriction::class)]
final class set_restriction_contract_test extends \advanced_testcase {
    use caught_throwable;

    /** @var \stdClass Course of the activities. */
    private \stdClass $course;

    /** @var int Activity that receives the restriction. */
    private int $target;

    /** @var int Activity a condition can point at. */
    private int $other;

    /**
     * Course, editing teacher and two pages.
     */
    private function setup_course(): void {
        $this->resetAfterTest();
        set_config('enableavailability', 1);
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
        $this->target = (int) $this->getDataGenerator()->create_module('page', ['course' => $this->course->id])->cmid;
        $this->other = (int) $this->getDataGenerator()->create_module('page', ['course' => $this->course->id])->cmid;
    }

    /**
     * Runs the tool and returns what it throws.
     *
     * @param mixed $conditions Conditions, JSON encoded unless already a string.
     * @return \Throwable|null
     */
    private function failure(mixed $conditions): ?\Throwable {
        $json = is_string($conditions) ? $conditions : json_encode($conditions);
        return $this->caught(fn() => set_restriction::execute($this->target, $json));
    }

    /**
     * Cases of rejected conditions with the error code and the placeholders the teacher message carries.
     *
     * @return array<string, array{mixed, string, array<string, string>}>
     */
    public static function rejected_conditions(): array {
        return [
            'unknown type' => [[['type' => 'nope']], 'restrictionunknowntype', ['field' => 'type', 'value' => '"nope"']],
            'missing type' => [[['status' => 'pass']], 'restrictionunknowntype', ['field' => 'type', 'value' => 'null']],
            'activity missing' => [[['type' => 'completion', 'status' => 'pass']], 'restrictionactivitynotfound',
                ['field' => 'activity_cmid', 'value' => 'null']],
            'activity zero' => [[['type' => 'completion', 'activity_cmid' => 0, 'status' => 'pass']],
                'restrictionactivitynotfound', ['field' => 'activity_cmid', 'value' => 'null']],
            'activity negative' => [[['type' => 'completion', 'activity_cmid' => -4, 'status' => 'pass']],
                'restrictionactivitynotfound', ['field' => 'activity_cmid', 'value' => 'null']],
            'activity true' => [[['type' => 'completion', 'activity_cmid' => true, 'status' => 'pass']],
                'restrictionactivitynotfound', ['field' => 'activity_cmid', 'value' => 'null']],
            'activity leading zero' => [[['type' => 'completion', 'activity_cmid' => '07', 'status' => 'pass']],
                'restrictionactivitynotfound', ['field' => 'activity_cmid', 'value' => 'null']],
            'activity trailing text' => [[['type' => 'completion', 'activity_cmid' => '7x', 'status' => 'pass']],
                'restrictionactivitynotfound', ['field' => 'activity_cmid', 'value' => 'null']],
            'activity leading text' => [[['type' => 'completion', 'activity_cmid' => 'x7', 'status' => 'pass']],
                'restrictionactivitynotfound', ['field' => 'activity_cmid', 'value' => 'null']],
            'activity unknown' => [[['type' => 'completion', 'activity_cmid' => 987654, 'status' => 'pass']],
                'restrictionactivitynotfound', ['field' => 'activity_cmid', 'value' => '987654']],
            'status unknown' => [[['type' => 'completion', 'activity_cmid' => '@other', 'status' => 'bogus']],
                'restrictioninvalidstatus', ['field' => 'status', 'value' => '"bogus"']],
            'status missing' => [[['type' => 'completion', 'activity_cmid' => '@other']],
                'restrictioninvalidstatus', ['field' => 'status', 'value' => 'null']],
            'date direction' => [[['type' => 'date', 'direction' => 'sideways', 'timestamp' => 5]], 'restrictioninvaliddate',
                ['field' => 'direction/timestamp', 'value' => '{"type":"date","direction":"sideways","timestamp":5}']],
            'date timestamp' => [[['type' => 'date', 'direction' => 'from', 'timestamp' => '5']], 'restrictioninvaliddate',
                ['field' => 'direction/timestamp', 'value' => '{"type":"date","direction":"from","timestamp":"5"}']],
            'group text' => [[['type' => 'group', 'group_id' => 'abc']], 'restrictiongroupnotfound',
                ['field' => 'group_id', 'value' => '"abc"']],
            'group negative' => [[['type' => 'group', 'group_id' => -2]], 'restrictiongroupnotfound',
                ['field' => 'group_id', 'value' => '-2']],
            'group unknown' => [[['type' => 'group', 'group_id' => 987654]], 'restrictiongroupnotfound',
                ['field' => 'group_id', 'value' => '987654']],
            'group of another course' => [[['type' => 'group', 'group_id' => '@foreigngroup']], 'restrictiongroupnotfound',
                ['field' => 'group_id', 'value' => '@foreigngroup']],
        ];
    }

    /**
     * A rejected condition names its field and value.
     *
     * @param mixed $conditions
     * @param string $errorcode
     * @param array<string, string> $placeholders
     */
    #[DataProvider('rejected_conditions')]
    public function test_rejected_condition_names_its_field_and_value(
        mixed $conditions,
        string $errorcode,
        array $placeholders
    ): void {
        $this->setup_course();
        $foreign = $this->getDataGenerator()->create_group(['courseid' => $this->getDataGenerator()->create_course()->id]);
        $conditions = json_decode(str_replace(
            ['"@other"', '@foreigngroup'],
            [(string) $this->other, (string) $foreign->id],
            json_encode($conditions)
        ), true);
        $placeholders = array_map(static fn($v) => str_replace('@foreigngroup', (string) $foreign->id, $v), $placeholders);

        $e = $this->failure($conditions);

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame($errorcode, $e->errorcode);
        $this->assertSame($placeholders, $e->a);
    }

    public function test_text_ids_of_existing_activities_and_groups_are_accepted(): void {
        $this->setup_course();
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);

        $e = $this->failure([
            ['type' => 'completion', 'activity_cmid' => (string) $this->other, 'status' => 'complete'],
            ['type' => 'group', 'group_id' => (string) $group->id],
            ['type' => 'group', 'group_id' => null],
            ['type' => 'group'],
        ]);

        $this->assertNull($e);
    }

    public function test_activity_of_another_course_is_not_found(): void {
        $this->setup_course();
        $elsewhere = (int) $this->getDataGenerator()
            ->create_module('page', ['course' => $this->getDataGenerator()->create_course()->id])->cmid;

        $e = $this->failure([['type' => 'completion', 'activity_cmid' => $elsewhere, 'status' => 'pass']]);

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('restrictionactivitynotfound', $e->errorcode);
        $this->assertSame(['field' => 'activity_cmid', 'value' => (string) $elsewhere], $e->a);
    }

    /**
     * Provides documents that are not a condition list.
     *
     * @return array<string, array{string}>
     */
    public static function documents_that_are_no_condition_list(): array {
        return [
            'invalid' => ['not json'], 'number' => ['5'], 'string' => ['"x"'], 'null' => ['null'],
            'object' => ['{"type":"group"}'], 'object with ids' => ['{"0":{"type":"group"},"2":{"type":"group"}}'],
        ];
    }

    #[DataProvider('documents_that_are_no_condition_list')]
    public function test_document_that_is_no_condition_list_is_refused(string $json): void {
        $this->setup_course();

        $e = $this->failure($json);

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidrestrictionjson', $e->errorcode);
    }

    public function test_list_entries_must_be_objects(): void {
        $this->setup_course();

        foreach (['[5]', '["group"]', '[[1,2]]', '[null]'] as $json) {
            $e = $this->failure($json);
            $this->assertInstanceOf(\coding_exception::class, $e, $json);
            $this->assertStringContainsString('conditions_json must be an array of JSON objects.', $e->getMessage());
        }
    }

    public function test_restrictions_must_be_enabled_for_the_site(): void {
        $this->setup_course();
        set_config('enableavailability', 0);

        $e = $this->failure([['type' => 'group']]);

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('restrictionsnotenabled', $e->errorcode);
    }

    public function test_response_and_message_for_one_several_and_no_condition(): void {
        $this->setup_course();
        $date = ['type' => 'date', 'direction' => 'from', 'timestamp' => 1767225600];

        $one = set_restriction::execute($this->target, json_encode([$date]));
        $this->assertSame([
            'cmid' => $this->target,
            'modname' => 'page',
            'message' => 'Restriction set: 1 condition must be met.',
        ], $one);

        $three = set_restriction::execute($this->target, json_encode([$date, ['type' => 'group'], $date]));
        $this->assertSame('Restrictions set: 3 conditions must all be met.', $three['message']);

        $none = set_restriction::execute($this->target, '[]');
        $this->assertSame('All restrictions were removed.', $none['message']);
    }

    public function test_the_written_availability_is_the_native_and_tree_visible_to_learners(): void {
        global $DB;
        $this->setup_course();

        set_restriction::execute($this->target, json_encode([
            ['type' => 'completion', 'activity_cmid' => $this->other, 'status' => 'incomplete'],
            ['type' => 'date', 'direction' => 'until', 'timestamp' => 1767225600],
        ]));

        $this->assertSame(
            '{"op":"&","c":[{"type":"completion","cm":' . $this->other . ',"e":0},'
                . '{"type":"date","d":"<","t":1767225600}],"showc":[true,true]}',
            $DB->get_field('course_modules', 'availability', ['id' => $this->target])
        );
    }

    public function test_the_context_restriction_of_the_web_service_is_checked_first(): void {
        $this->setup_course();
        $other = $this->getDataGenerator()->create_course();

        external_api::set_context_restriction(\context_course::instance($other->id));
        try {
            $e = $this->failure('not json');
        } finally {
            external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
    }

    /**
     * Provides the capabilities the tool requires.
     *
     * @return array<string, array{string}>
     */
    public static function required_capabilities(): array {
        return ['plugin use' => ['local/coursepilot:use'], 'manage activities' => ['moodle/course:manageactivities']];
    }

    #[DataProvider('required_capabilities')]
    public function test_each_required_capability_is_checked_before_the_conditions(string $capability): void {
        global $DB;
        $this->setup_course();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $e = $this->failure('not json');

        $this->assertInstanceOf(\required_capability_exception::class, $e);
    }

    public function test_an_unknown_activity_is_refused_before_anything_else(): void {
        $this->setup_course();

        $e = $this->caught(fn() => set_restriction::execute(987654, '[]'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
    }

    public function test_every_grade_wait_is_listed_with_its_activity_and_independent_of_other_conditions(): void {
        $this->setup_course();
        $assign = (int) $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id])->cmid;
        $second = (int) $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id])->cmid;

        $e = $this->failure([
            ['type' => 'date', 'direction' => 'from', 'timestamp' => 1767225600],
            ['type' => 'completion', 'activity_cmid' => $this->other, 'status' => 'complete'],
            ['type' => 'completion', 'activity_cmid' => $assign, 'status' => 'pass'],
            ['type' => 'completion', 'activity_cmid' => $second, 'status' => 'fail'],
        ]);

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('learnerlocksunconfirmed', $e->errorcode);
        $this->assertSame(
            '"teacher_grade:' . $assign . '": the condition on assign (cmid ' . $assign
                . ') is only met once the teacher has graded the learner. | "teacher_grade:' . $second
                . '": the condition on assign (cmid ' . $second . ') is only met once the teacher has graded the learner.',
            $e->a['locks']
        );
        $this->assertSame('["teacher_grade:' . $assign . '","teacher_grade:' . $second . '"]', $e->a['ids']);
    }

    public function test_an_unchanged_condition_is_skipped_and_the_following_ones_are_still_checked(): void {
        $this->setup_course();
        $assign = (int) $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id])->cmid;
        $second = (int) $this->getDataGenerator()->create_module('assign', ['course' => $this->course->id])->cmid;
        set_restriction::execute(
            $this->target,
            json_encode([['type' => 'completion', 'activity_cmid' => $assign, 'status' => 'pass']]),
            ['teacher_grade:' . $assign]
        );

        $e = $this->failure([
            ['type' => 'completion', 'activity_cmid' => $assign, 'status' => 'pass'],
            ['type' => 'completion', 'activity_cmid' => $second, 'status' => 'pass'],
        ]);

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('["teacher_grade:' . $second . '"]', $e->a['ids']);
    }
}
