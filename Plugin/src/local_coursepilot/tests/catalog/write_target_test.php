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

namespace local_coursepilot\catalog;

use local_coursepilot\external\create_module;
use local_coursepilot\external\create_quiz;
use local_coursepilot\external\update_module_settings;
use local_coursepilot\external\update_quiz_settings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * One checked target state behind the public create and update write tools
 * (Spec 0028 F11, issue #646). All tests cross the external write boundary
 * with synthetic data.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(write_target::class)]
final class write_target_test extends \advanced_testcase {

    /** @var \stdClass */
    private \stdClass $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $this->course->id, 'editingteacher');
        $this->setUser($teacher);
    }

    /**
     * Runs $write and returns the rejection.
     *
     * @param callable $write
     * @return \moodle_exception
     */
    private function rejection(callable $write): \moodle_exception {
        try {
            $write();
        } catch (\moodle_exception $e) {
            return $e;
        }
        $this->fail('The write should have been rejected.');
    }

    /**
     * @param string $modname
     * @return int
     */
    private function count_modules(string $modname): int {
        global $DB;
        return $DB->count_records($modname, ['course' => $this->course->id]);
    }

    /**
     * @param string $modname
     * @param int $cmid
     * @return \stdClass Raw instance row.
     */
    private function instance(string $modname, int $cmid): \stdClass {
        global $DB;
        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
        return $DB->get_record($modname, ['id' => $cm->instance], '*', MUST_EXIST);
    }

    /**
     * @return array<string, mixed>
     */
    private static function quiz_fields(): array {
        return ['name' => 'Quiz', 'intro' => '', 'subnet' => '', 'browsersecurity' => '-',
            'preferredbehaviour' => 'deferredfeedback'];
    }

    /** The same date violation is rejected identically on create and update, without mutation. */
    public function test_same_date_violation_is_rejected_on_create_and_update(): void {
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $dates = ['duedate' => 2000000000, 'cutoffdate' => 1900000000];

        $oncreate = $this->rejection(fn() => create_module::execute(
            $this->course->id, 0, 'forum', json_encode(['name' => 'F', 'intro' => ''] + $dates)));
        $onupdate = $this->rejection(fn() => update_module_settings::execute($forum->cmid, json_encode($dates)));

        $this->assertSame('combinationruleviolation', $oncreate->errorcode);
        $this->assertSame($oncreate->errorcode, $onupdate->errorcode);
        $this->assertSame($oncreate->getMessage(), $onupdate->getMessage());
        $this->assertSame(1, $this->count_modules('forum'));
        $this->assertEquals(0, $this->instance('forum', $forum->cmid)->duedate);
    }

    /** Quiz keeps its own write tools, but decides the date rule the same way. */
    public function test_same_quiz_date_violation_is_rejected_on_create_and_update(): void {
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $dates = ['timeopen' => 2000000000, 'timeclose' => 1900000000];

        $oncreate = $this->rejection(fn() => create_quiz::execute(
            $this->course->id, 0, json_encode(self::quiz_fields() + $dates)));
        $onupdate = $this->rejection(fn() => update_quiz_settings::execute($quiz->cmid, json_encode($dates)));

        $this->assertSame('combinationruleviolation', $oncreate->errorcode);
        $this->assertSame($oncreate->getMessage(), $onupdate->getMessage());
        $this->assertSame(1, $this->count_modules('quiz'));
        $this->assertEquals(0, $this->instance('quiz', $quiz->cmid)->timeopen);
    }

    /** Stealth without allowstealth is rejected by every catalog write path. */
    public function test_stealth_is_rejected_on_every_write_path(): void {
        set_config('allowstealth', 0);
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $stealth = ['visibleoncoursepage' => 0];

        $writes = [
            fn() => create_module::execute($this->course->id, 0, 'forum', json_encode(['name' => 'F', 'intro' => ''] + $stealth)),
            fn() => update_module_settings::execute($forum->cmid, json_encode($stealth)),
            fn() => create_quiz::execute($this->course->id, 0, json_encode(self::quiz_fields() + $stealth)),
            fn() => update_quiz_settings::execute($quiz->cmid, json_encode($stealth)),
        ];
        foreach ($writes as $write) {
            $this->assertSame('stealthnotallowed', $this->rejection($write)->errorcode);
        }
        $this->assertSame(1, $this->count_modules('forum'));
        $this->assertSame(1, $this->count_modules('quiz'));
    }

    /** A filled form default is part of the target: its learner lock counts on create, as a change does on update. */
    public function test_learner_lock_from_default_and_from_change_is_rejected_alike(): void {
        $choice = create_module::execute($this->course->id, 0, 'choice', json_encode([
            'name' => 'C', 'intro' => '', 'allowupdate' => 1, 'option' => ['Ja', 'Nein'],
        ]));

        // allowupdate defaults to 0, a learner lock, although this call does not name it.
        $oncreate = $this->rejection(fn() => create_module::execute(
            $this->course->id, 0, 'choice', json_encode(['name' => 'C2', 'intro' => '', 'option' => ['Ja']])));
        $onupdate = $this->rejection(fn() => update_module_settings::execute($choice['cmid'], json_encode(['allowupdate' => 0])));

        $this->assertSame('learnerlocksunconfirmed', $oncreate->errorcode);
        $this->assertSame('learnerlocksunconfirmed', $onupdate->errorcode);
        $this->assertStringContainsString('form default', $oncreate->getMessage());
        $this->assertSame(1, $this->count_modules('choice'));
        $this->assertEquals(1, $this->instance('choice', $choice['cmid'])->allowupdate);
    }

    /** Unknown and blocked fields stay rejected on both paths. */
    public function test_unknown_and_blocked_fields_are_rejected_on_create_and_update(): void {
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        foreach (['nosuchfield' => 'unknownfield', 'course' => 'blockedfield'] as $field => $errorcode) {
            $oncreate = $this->rejection(fn() => create_module::execute(
                $this->course->id, 0, 'forum', json_encode(['name' => 'F', 'intro' => '', $field => 1])));
            $onupdate = $this->rejection(fn() => update_module_settings::execute($forum->cmid, json_encode([$field => 1])));
            $this->assertSame($errorcode, $oncreate->errorcode, $field);
            $this->assertSame($errorcode, $onupdate->errorcode, $field);
        }
        $this->assertSame(1, $this->count_modules('forum'));
    }

    /** Unnamed fields take their catalog form defaults and cannot violate a rule. */
    public function test_create_fills_defaults_into_the_target(): void {
        $result = create_module::execute($this->course->id, 0, 'assign', json_encode([
            'name' => 'A', 'intro' => '', 'cutoffdate' => 1900000000,
        ]), 'store', ['cutoffdate']);

        $assign = $this->instance('assign', $result['cmid']);
        $this->assertEquals(1900000000, $assign->cutoffdate);
        $this->assertEquals(0, $assign->duedate);
        $this->assertSame(['cutoffdate', 'intro', 'name'], $this->sorted(array_column($result['created_fields'], 'field')));
    }

    /** A date change is checked against the full target: unchanged current values count. */
    public function test_date_patch_is_checked_against_unchanged_current_values(): void {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id, 'allowsubmissionsfromdate' => 1800000000, 'duedate' => 0, 'cutoffdate' => 0,
        ]);

        $e = $this->rejection(fn() => update_module_settings::execute($assign->cmid, json_encode(['duedate' => 1700000000])));

        $this->assertSame('combinationruleviolation', $e->errorcode);
        $this->assertStringContainsString('allowsubmissionsfromdate', $e->getMessage());
        $this->assertEquals(0, $this->instance('assign', $assign->cmid)->duedate);
    }

    /** A mixed target (one date changed, the other current) is accepted when valid. */
    public function test_valid_mixed_target_is_written(): void {
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $this->course->id, 'allowsubmissionsfromdate' => 1800000000, 'duedate' => 0, 'cutoffdate' => 0,
        ]);

        update_module_settings::execute($assign->cmid, json_encode(['duedate' => 1900000000]));

        $this->assertEquals(1900000000, $this->instance('assign', $assign->cmid)->duedate);
    }

    /** An independent patch does not re-judge historic invalid dates. */
    public function test_independent_patch_ignores_historic_invalid_dates(): void {
        global $DB;
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $this->course->id]);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        $DB->update_record('forum', (object) ['id' => $forum->id, 'duedate' => 2000000000, 'cutoffdate' => 1900000000]);
        $DB->update_record('quiz', (object) ['id' => $quiz->id, 'timeopen' => 2000000000, 'timeclose' => 1900000000,
            'overduehandling' => 'graceperiod', 'graceperiod' => 0]);

        update_module_settings::execute($forum->cmid, json_encode(['name' => 'Renamed forum']));
        update_quiz_settings::execute($quiz->cmid, json_encode(['name' => 'Renamed quiz']));

        $this->assertSame('Renamed forum', $this->instance('forum', $forum->cmid)->name);
        $this->assertSame('Renamed quiz', $this->instance('quiz', $quiz->cmid)->name);
    }

    /** The parallel-length rule of choice applies to create and update alike. */
    public function test_parallel_array_lengths_are_enforced_on_create_and_update(): void {
        $choice = create_module::execute($this->course->id, 0, 'choice', json_encode([
            'name' => 'C', 'intro' => '', 'allowupdate' => 1, 'option' => ['Ja', 'Nein'],
        ]));
        $wronglimit = ['option' => ['Ja', 'Nein'], 'limit' => [1, 2, 3]];

        $oncreate = $this->rejection(fn() => create_module::execute(
            $this->course->id, 0, 'choice', json_encode(['name' => 'C2', 'intro' => '', 'allowupdate' => 1] + $wronglimit)));
        $onupdate = $this->rejection(fn() => update_module_settings::execute($choice['cmid'], json_encode(['limit' => [1, 2, 3]])));

        $this->assertSame('combinationruleviolation', $oncreate->errorcode);
        $this->assertSame($oncreate->getMessage(), $onupdate->getMessage());
        $this->assertSame(1, $this->count_modules('choice'));

        // Extending only the reference list stays valid: the native write guards missing limits.
        update_module_settings::execute($choice['cmid'], json_encode(['option' => ['Ja', 'Nein', 'Vielleicht']]));
        $this->assertTrue($this->has_option($choice['cmid'], 'Vielleicht'));
    }

    /**
     * @param int $cmid
     * @param string $text
     * @return bool
     */
    private function has_option(int $cmid, string $text): bool {
        global $DB;
        $texts = $DB->get_fieldset('choice_options', 'text', ['choiceid' => $this->instance('choice', $cmid)->id]);
        return in_array($text, $texts, true);
    }

    /**
     * @param string[] $values
     * @return string[]
     */
    private function sorted(array $values): array {
        sort($values);
        return $values;
    }
}
