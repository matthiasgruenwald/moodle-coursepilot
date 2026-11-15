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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Entry guards of the dedicated quiz patch tool (#674).
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(update_quiz_settings::class)]
final class update_quiz_settings_674_final_contract_test extends \advanced_testcase {
    use \local_coursepilot\tests\caught_throwable;

    /**
     * Teacher with a course and a quiz.
     *
     * @return int Course module ID of the quiz.
     */
    private function setup_quiz(): int {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        return (int) $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'name' => 'Before'])->cmid;
    }

    /**
     * A JSON value that is valid but not an object or array is refused as an invalid patch.
     *
     * @param string $json
     */
    #[DataProvider('valid_json_without_a_patch')]
    public function test_valid_json_that_is_not_a_patch_is_refused(string $json): void {
        $cmid = $this->setup_quiz();

        $e = $this->caught(fn() => update_quiz_settings::execute($cmid, $json));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidpatchjson', $e->errorcode);
    }

    /**
     * Valid JSON scalars.
     *
     * @return string[][]
     */
    public static function valid_json_without_a_patch(): array {
        return ['number' => ['5'], 'string' => ['"name"'], 'null' => ['null'], 'boolean' => ['true']];
    }

    /**
     * Malformed JSON is refused with the same error.
     */
    public function test_malformed_json_is_refused(): void {
        $cmid = $this->setup_quiz();

        $e = $this->caught(fn() => update_quiz_settings::execute($cmid, '{broken'));

        $this->assertInstanceOf(\moodle_exception::class, $e);
        $this->assertSame('invalidpatchjson', $e->errorcode);
    }

    /**
     * The manage-activities right is checked before the patch is looked at.
     */
    public function test_managing_activities_is_required_before_the_patch_is_read(): void {
        global $DB;
        $cmid = $this->setup_quiz();
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $e = $this->caught(fn() => update_quiz_settings::execute($cmid, '{broken'));

        $this->assertInstanceOf(\required_capability_exception::class, $e);
        $this->assertSame('Before', $DB->get_field('quiz', 'name', ['id' => get_coursemodule_from_id('quiz', $cmid)->instance]));
    }

    /**
     * Without a grade argument the maximum grade is left alone.
     */
    public function test_omitting_the_grade_keeps_the_maximum_grade(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id, 'grade' => 10, 'name' => 'Before']);

        $result = $this->succeeded(fn() => update_quiz_settings::execute($quiz->cmid, json_encode(['name' => 'After'])));

        $this->assertSame('After', $DB->get_field('quiz', 'name', ['id' => $quiz->id]));
        $this->assertEquals(10, $DB->get_field('quiz', 'grade', ['id' => $quiz->id]));
        $this->assertSame([], $result['side_effects']);
    }

    /**
     * A context restriction of the web service request is honoured before anything is written.
     */
    public function test_a_restricted_request_context_is_refused(): void {
        global $DB;
        $cmid = $this->setup_quiz();
        $other = $this->getDataGenerator()->create_course();
        \core_external\external_api::set_context_restriction(\context_course::instance($other->id));

        try {
            $e = $this->caught(fn() => update_quiz_settings::execute($cmid, json_encode(['name' => 'After'])));
        } finally {
            \core_external\external_api::set_context_restriction(\context_system::instance());
        }

        $this->assertInstanceOf(\core_external\restricted_context_exception::class, $e);
        $this->assertSame('Before', $DB->get_field('quiz', 'name', ['id' => get_coursemodule_from_id('quiz', $cmid)->instance]));
    }
}
