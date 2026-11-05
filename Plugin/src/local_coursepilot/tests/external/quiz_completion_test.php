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
use local_coursepilot\catalog\completion_settings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Public completion tools preserve quiz requirements and learner confirmations.
 *
 * @package    local_coursepilot
 * @copyright  2026 Coursepilot
 * @license    https://www.gnu.org/licenses/agpl-3.0.html GNU AGPL v3 or later
 */
#[CoversClass(set_completion::class)]
#[CoversClass(completion_settings::class)]
final class quiz_completion_test extends \advanced_testcase {
    /**
     * Create a quiz with completion enabled and an authorised editing teacher.
     *
     * @param int $minimum
     * @param int $attempts
     * @return int Course module ID.
     */
    private function quiz(int $minimum = 0, int $attempts = 0): int {
        set_config('enablecompletion', COMPLETION_ENABLED);
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => COMPLETION_ENABLED]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionminattempts' => $minimum,
            'attempts' => $attempts,
        ]);
        return (int) $quiz->cmid;
    }

    /**
     * Read the same settings a tool client sees.
     *
     * @param int $cmid
     * @return mixed[]
     */
    private function read(int $cmid): array {
        $result = external_api::clean_returnvalue(
            get_module_settings::execute_returns(),
            get_module_settings::execute($cmid)
        );
        return json_decode($result['settings_json'], true);
    }

    /**
     * A completion minimum cannot exceed the number of attempts the quiz permits.
     */
    public function test_minimum_cannot_exceed_allowed_attempts(): void {
        $this->resetAfterTest();
        $cmid = $this->quiz(0, 1);

        try {
            set_completion::execute($cmid, '{"completionminattempts":2}');
            $this->fail('The minimum cannot exceed the permitted attempts.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completioninvalidfieldvalue', $e->errorcode);
        }
        $this->assertSame(0, (int) $this->read($cmid)['completionminattempts']);
    }

    /**
     * Completion changes must retain a minimum omitted from the patch.
     */
    public function test_other_completion_patch_preserves_minimum_attempts(): void {
        $this->resetAfterTest();
        $cmid = $this->quiz(2);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, '{"completionview":1}')
        );

        $this->assertSame(2, (int) $this->read($cmid)['completionminattempts']);
        $this->assertSame(['completionview'], array_column($result['changes'], 'field'));
    }

    /**
     * Zero switches the minimum requirement off rather than restoring a form default.
     */
    public function test_zero_disables_minimum_attempts(): void {
        $this->resetAfterTest();
        $cmid = $this->quiz(2);

        $result = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, '{"completionminattempts":0}')
        );

        $this->assertSame(0, (int) $this->read($cmid)['completionminattempts']);
        $this->assertSame(['completionminattempts'], array_column($result['changes'], 'field'));
    }

    /**
     * A changed minimum cannot delete existing completion data without confirmation.
     */
    public function test_minimum_attempts_change_requires_data_loss_confirmation(): void {
        global $DB;
        $this->resetAfterTest();
        $cmid = $this->quiz(1);
        $student = $this->getDataGenerator()->create_user();
        $DB->insert_record('course_modules_completion', [
            'coursemoduleid' => $cmid, 'userid' => $student->id,
            'completionstate' => COMPLETION_COMPLETE, 'timemodified' => time(),
        ]);

        try {
            set_completion::execute($cmid, '{"completionminattempts":2}');
            $this->fail('Existing learner completion must require confirmation.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completiondatalossconfirmationrequired', $e->errorcode);
            $this->assertStringContainsString('1', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->read($cmid)['completionminattempts']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $cmid]));

        $unchanged = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, '{"completionminattempts":1}')
        );
        $this->assertSame([], $unchanged['changes']);
        $this->assertTrue($DB->record_exists('course_modules_completion', ['coursemoduleid' => $cmid]));

        $confirmed = external_api::clean_returnvalue(
            set_completion::execute_returns(),
            set_completion::execute($cmid, '{"completionminattempts":2}', true)
        );
        $this->assertSame(2, (int) $this->read($cmid)['completionminattempts']);
        $this->assertSame(['completionminattempts'], array_column($confirmed['changes'], 'field'));
    }

    /**
     * The count is quiz-specific and a refused mixed patch writes nothing.
     */
    public function test_minimum_attempts_is_rejected_for_other_activity_types(): void {
        $this->resetAfterTest();
        $cmid = $this->quiz();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->read($cmid)['course']]);

        try {
            set_completion::execute($page->cmid, '{"completionview":1,"completionminattempts":2}');
            $this->fail('Minimum attempts belongs only to a quiz.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionfieldnotformodname', $e->errorcode);
            $this->assertStringContainsString('quiz', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->read($page->cmid)['completionview']);
    }

    /**
     * General quiz settings cannot bypass the dedicated confirmed write operation.
     */
    public function test_general_quiz_patch_cannot_change_minimum_attempts(): void {
        $this->resetAfterTest();
        $cmid = $this->quiz(1);

        try {
            update_quiz_settings::execute($cmid, '{"completionminattempts":2}');
            $this->fail('The ordinary quiz patch must refuse completion fields.');
        } catch (\moodle_exception $e) {
            $this->assertSame('completionfieldviasetcompletion', $e->errorcode);
        }
        $this->assertSame(1, (int) $this->read($cmid)['completionminattempts']);
    }
}
